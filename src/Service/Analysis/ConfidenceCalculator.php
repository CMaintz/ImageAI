<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Service\Analysis;

use CMaintz\ImageAi\Config\ConfidenceConfiguration;
use CMaintz\ImageAi\Config\PluginConfiguration;
use CMaintz\ImageAi\Config\PluginConstants;
use CMaintz\ImageAi\DTO\Analysis\AnalysisResultDTO;
use CMaintz\ImageAi\DTO\Analysis\ConfidenceResult;
use CMaintz\ImageAi\DTO\Analysis\DescriptionDataDTO;
use CMaintz\ImageAi\DTO\Analysis\LanguageAnalysisDTO;
use CMaintz\ImageAi\DTO\Analysis\MetaDataDTO;

/**
 * Calculates comprehensive confidence scores for AI analysis results
 *
 * Combines multiple quality signals:
 * - Weighted Gemini confidence scores by field importance
 * - Validation penalties for poor-quality outputs
 * - Quality signal adjustments (property matching)
 *
 * Note: Confidence is calculated against da-DK content only since that's the primary sales channel.
 */
class ConfidenceCalculator
{
    public function __construct(
        private readonly PluginConfiguration $config
    ) {
    }

    /**
     * Calculate comprehensive confidence score for an analysis result
     *
     * Optimized to perform all calculations in minimal iterations:
     * - Single pass to find Danish analysis and check for duplicates
     * - Combined validation checks within single conditional blocks
     *
     * @param AnalysisResultDTO $dto The analysis result to evaluate
     * @param array{
     *     propertyMatchRate?: float,
     *     responseCompleteness?: float
     * } $qualitySignals Additional quality signals from the analysis process
     * @param array<float> $rawLogProbs Optional log probabilities from Vertex AI
     */
    public function calculate(
        AnalysisResultDTO $dto,
        array $qualitySignals = [],
        array $rawLogProbs = []
    ): ConfidenceResult {
        $confidenceConfig = $this->config->getConfidenceConfig();

        // If confidence checking is disabled, return a neutral score that won't trigger review
        if (!$confidenceConfig->isConfidenceCheckingEnabled()) {
            return new ConfidenceResult(
                score: 1.0,
                requiresReview: false,
                warnings: []
            );
        }

        // Single pass through language analyses to find Danish and check duplicates
        [$danishAnalysis, $hasDuplicates] = $this->analyzeLanguageContent($dto);

        // Calculate all scores in one consolidated method
        [$heuristicScore, $validationPenalty, $warnings] = $this->calculateScoresAndPenalties(
            $dto,
            $danishAnalysis,
            $hasDuplicates,
            $confidenceConfig
        );

        // If we have log probabilities, blend them with heuristic score
        if (!empty($rawLogProbs)) {
            $modelIntrinsicScore = $this->calculateLogProbConfidence($rawLogProbs);
            $geminiScore = ($modelIntrinsicScore * 0.6) + ($heuristicScore * 0.4);

            if ($modelIntrinsicScore < 0.7) {
                $warnings[] = sprintf("Model intern tillid er lav (%.2f)", $modelIntrinsicScore);
            }
        } else {
            $geminiScore = $heuristicScore;
        }

        $qualityAdjustment = $this->calculateQualityAdjustment($qualitySignals, $confidenceConfig, $warnings);

        $finalScore = max(0.0, min(1.0, $geminiScore - $validationPenalty + $qualityAdjustment));

        return new ConfidenceResult(
            score: $finalScore,
            requiresReview: $finalScore < $confidenceConfig->lowConfidenceThreshold,
            warnings: $warnings
        );
    }

    /**
     * Single pass through language analyses to find Danish content and detect duplicates
     *
     * @return array{0: ?LanguageAnalysisDTO, 1: bool} Danish analysis and duplicate flag
     */
    private function analyzeLanguageContent(AnalysisResultDTO $dto): array
    {
        $danishAnalysis = null;
        $descriptions = [];
        $hasDuplicates = false;

        foreach ($dto->languageAnalyses as $languageAnalysis) {
            // Find Danish analysis
            if ($languageAnalysis->languageCode === PluginConstants::PRIMARY_ANALYSIS_LANGUAGE) {
                $danishAnalysis = $languageAnalysis;
            }

            // Check for duplicate descriptions in same pass
            if (!$hasDuplicates && $languageAnalysis->hasContent() && $languageAnalysis->descriptionData !== null) {
                $desc = trim($languageAnalysis->descriptionData->description ?? '');
                if (!empty($desc)) {
                    $normalized = mb_strtolower($desc);
                    if (in_array($normalized, $descriptions, true)) {
                        $hasDuplicates = true;
                    } else {
                        $descriptions[] = $normalized;
                    }
                }
            }
        }

        return [$danishAnalysis, $hasDuplicates];
    }

    /**
     * Calculate weighted confidence and validation penalties in consolidated pass
     *
     * @return array{0: float, 1: float, 2: string[]} Heuristic score, penalty, and warnings
     */
    private function calculateScoresAndPenalties(
        AnalysisResultDTO $dto,
        ?LanguageAnalysisDTO $danishAnalysis,
        bool $hasDuplicates,
        ConfidenceConfiguration $config
    ): array {
        // No Danish content - low confidence, skip validation
        if ($danishAnalysis === null || !$danishAnalysis->hasContent()) {
            return [0.3, 0.0, ['Ingen dansk indhold fundet']];
        }

        $fieldWeights = $config->getFieldWeights();
        $weightedSum = 0.0;
        $totalWeight = 0.0;
        $penalty = 0.0;
        $warnings = [];

        // Process SEO meta data
        if ($danishAnalysis->metaData !== null) {
            [$metaWeight, $metaPenalty, $metaWarnings] = $this->validateMetaData(
                $danishAnalysis->metaData,
                $fieldWeights,
                $config
            );
            $weightedSum += $metaWeight;
            $totalWeight += $fieldWeights['metaTitle']
                + $fieldWeights['metaDescription']
                + $fieldWeights['seoKeywords'];
            $penalty += $metaPenalty;
            $warnings = array_merge($warnings, $metaWarnings);
        }

        // Process product description
        if ($danishAnalysis->descriptionData !== null) {
            [$descWeight, $descPenalty, $descWarnings] = $this->validateDescription(
                $danishAnalysis->descriptionData,
                $fieldWeights['productDescription'],
                $config
            );
            $weightedSum += $descWeight;
            $totalWeight += $fieldWeights['productDescription'];
            $penalty += $descPenalty;
            $warnings = array_merge($warnings, $descWarnings);
        }

        // Properties - confidence weight
        if (!empty($dto->properties)) {
            $weightedSum += 0.85 * $fieldWeights['properties'];
            $totalWeight += $fieldWeights['properties'];
        }

        // Duplicate content penalty
        if ($hasDuplicates) {
            $penalty += $config->duplicateContentPenalty;
            $warnings[] = 'Duplikeret indhold opdaget på tværs af sprog';
        }

        // Property-specific penalties
        [$propertyPenalty, $propertyWarnings] = $this->calculatePropertyPenalty($dto, $config);
        $penalty += $propertyPenalty;
        $warnings = array_merge($warnings, $propertyWarnings);

        $heuristicScore = $totalWeight > 0 ? $weightedSum / $totalWeight : 0.0;
        $cappedPenalty = min($penalty, $config->maxTotalPenalty);

        return [$heuristicScore, $cappedPenalty, $warnings];
    }

    /**
     * Validate meta data (title, description, keywords) and calculate confidence weights
     *
     * @return array{0: float, 1: float, 2: string[]} Weighted sum contribution, penalty, warnings
     */
    private function validateMetaData(
        MetaDataDTO $metaData,
        array $fieldWeights,
        ConfidenceConfiguration $config
    ): array {
        $contentConfig = $this->config->getContentConfig();
        $weightedSum = 0.0;
        $penalty = 0.0;
        $warnings = [];

        // Meta title validation
        $metaTitleText = $metaData->metaTitleData->metaTitle ?? '';
        $titleLength = mb_strlen($metaTitleText);
        $metaTitleMax = $contentConfig->metaTitleMaxLength;
        $minTitleLength = (int) ($metaTitleMax * $config->minLengthRatio);

        $weightedSum += $metaData->metaTitleData->confidence * $fieldWeights['metaTitle'];

        if ($titleLength < $minTitleLength) {
            $penalty += $config->shortTitlePenalty;
            $warnings[] = "Meta-titel er for kort ({$titleLength}/{$minTitleLength} min. tegn)";
        } elseif ($titleLength > $metaTitleMax) {
            $penalty += $config->longTitlePenalty;
            $warnings[] = "Meta-titel overstiger maks. ({$titleLength}/{$metaTitleMax} tegn)";
        }

        // Meta description validation
        $metaDescText = $metaData->metaDescriptionData->metaDescription ?? '';
        $descLength = mb_strlen($metaDescText);
        $metaDescMax = $contentConfig->metaDescriptionMaxLength;
        $minDescLength = (int) ($metaDescMax * $config->minLengthRatio);

        $weightedSum += $metaData->metaDescriptionData->confidence * $fieldWeights['metaDescription'];

        if ($descLength < $minDescLength) {
            $penalty += $config->shortDescriptionPenalty;
            $warnings[] = "Meta-beskrivelse er for kort ({$descLength}/{$minDescLength} min. tegn)";
        }

        // Keywords validation - count and total length in single loop
        $keywords = $metaData->seoKeywordsData->seoKeywords ?? [];
        $keywordCount = 0;
        $totalKeywordsLength = 0;
        foreach ($keywords as $keyword) {
            $keywordCount++;
            $totalKeywordsLength += mb_strlen($keyword);
        }

        $weightedSum += $metaData->seoKeywordsData->confidence * $fieldWeights['seoKeywords'];

        $minKeywords = max(1, (int) ($contentConfig->keywordCount * 0.6));
        if ($keywordCount < $minKeywords) {
            $penalty += $config->fewKeywordsPenalty;
            $warnings[] = "For få nøgleord ({$keywordCount}/{$minKeywords} min.)";
        }

        $keywordsMaxChars = $contentConfig->keywordsMaxCharacterLength;
        if ($totalKeywordsLength > $keywordsMaxChars) {
            $penalty += $config->longTitlePenalty;
            $warnings[] = "Nøgleord samlet længde overstiger maks. ({$totalKeywordsLength}/{$keywordsMaxChars} tegn)";
        }

        // Generic content check - check each field separately
        if ($this->containsGenericContent($metaTitleText, $config)
            || $this->containsGenericContent($metaDescText, $config)) {
            $penalty += $config->genericContentPenalty;
            $warnings[] = "Generisk/pladsholder-indhold opdaget i meta-felter";
        }

        return [$weightedSum, $penalty, $warnings];
    }

    /**
     * Validate product description and calculate confidence weight
     *
     * @return array{0: float, 1: float, 2: string[]} Weighted contribution, penalty, warnings
     */
    private function validateDescription(
        DescriptionDataDTO $descriptionData,
        float $fieldWeight,
        ConfidenceConfiguration $config
    ): array {
        $contentConfig = $this->config->getContentConfig();
        $penalty = 0.0;
        $warnings = [];

        $productDesc = $descriptionData->description ?? '';
        $productDescLength = mb_strlen($productDesc);
        $descMax = $contentConfig->descriptionMaxLength;
        $minProductDescLength = (int) ($descMax * $config->minLengthRatio);

        $weightedContribution = $descriptionData->confidence * $fieldWeight;

        if ($productDescLength < $minProductDescLength) {
            $penalty += $config->shortDescriptionPenalty;
            $warnings[] = "Produktbeskrivelse er for kort ({$productDescLength}/{$minProductDescLength} min. tegn)";
        }

        // Check generic content and hedging words
        $lowerDesc = mb_strtolower($productDesc);

        if ($this->containsGenericContent($productDesc, $config)) {
            $penalty += $config->genericContentPenalty;
            $warnings[] = "Generisk/pladsholder-indhold opdaget i beskrivelse";
        }

        $hedgeCount = $this->countHedgingWords($lowerDesc, $config);
        if ($hedgeCount >= 2) {
            $hedgingPenalty = min($hedgeCount * $config->hedgingPenaltyPerInstance, $config->hedgingPenaltyMax);
            $penalty += $hedgingPenalty;
            $warnings[] = "Tøvende sprog opdaget i beskrivelse ({$hedgeCount} forekomster)";
        }

        return [$weightedContribution, $penalty, $warnings];
    }

    /**
     * Count hedging words in lowercase text
     */
    private function countHedgingWords(string $lowerText, ConfidenceConfiguration $config): int
    {
        $count = 0;
        foreach ($config->hedgingWords as $word) {
            $count += substr_count($lowerText, mb_strtolower($word));
        }
        return $count;
    }

    /**
     * Calculate quality signal adjustments
     *
     * @param string[] &$warnings Warnings array to append to
     */
    private function calculateQualityAdjustment(
        array $qualitySignals,
        ConfidenceConfiguration $config,
        array &$warnings
    ): float {
        $adjustment = 0.0;

        $propertyMatchRate = $qualitySignals['propertyMatchRate'] ?? 1.0;
        if ($propertyMatchRate < 0.5) {
            $adjustment -= $config->lowPropertyMatchPenalty;
            $warnings[] = sprintf('Lav egenskabsmatch-rate (%.0f%%)', $propertyMatchRate * 100);
        }

        $completeness = $qualitySignals['responseCompleteness'] ?? 1.0;
        if ($completeness < 1.0) {
            $adjustment -= (1.0 - $completeness) * 0.1;
            if ($completeness < 0.8) {
                $warnings[] = sprintf('Ufuldstændigt svar (%.0f%% færdig)', $completeness * 100);
            }
        }

        return $adjustment;
    }

    /**
     * Check if text contains generic/placeholder content using configured patterns
     */
    private function containsGenericContent(string $text, ConfidenceConfiguration $config): bool
    {
        foreach ($config->genericPatterns as $pattern) {
            if (@preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate property-specific penalties based on option counts per property
     *
     * Scoring philosophy:
     * - 0 options AND 0 suggestions = HEAVY penalty (useless analysis)
     * - 1-3 total options/suggestions per property = No penalty (sweet spot)
     * - 4+ total options/suggestions = Moderate penalty per extra (uncertainty)
     *
     * @return array{0: float, 1: string[]} Penalty value and warnings
     */
    private function calculatePropertyPenalty(
        AnalysisResultDTO $dto,
        ConfidenceConfiguration $config
    ): array {
        $penalty = 0.0;
        $warnings = [];

        $propertyCount = count($dto->properties);

        // No properties at all
        if ($propertyCount === 0) {
            $penalty += $config->noPropertiesPenalty;
            $warnings[] = 'Ingen egenskaber opdaget i analyse';

            return [$penalty, $warnings];
        }

        $emptyProperties = [];
        $overloadedProperties = [];

        foreach ($dto->properties as $propertyName => $property) {
            $existingOptions = count($property['options'] ?? []);
            $suggestedOptions = count($property['suggestedOptions'] ?? []);
            $totalOptions = $existingOptions + $suggestedOptions;

            if ($totalOptions === 0) {
                // Heavy penalty for empty properties - useless analysis
                $penalty += $config->emptyPropertyPenalty;
                $emptyProperties[] = $propertyName;
            } elseif ($totalOptions > $config->idealOptionsPerProperty) {
                // Moderate penalty for excess options (uncertainty)
                $excessOptions = $totalOptions - $config->idealOptionsPerProperty;
                $penalty += $excessOptions * $config->excessOptionPenalty;
                $overloadedProperties[] = "{$propertyName} ({$totalOptions} valgmuligheder)";
            }
            // 1-3 options: no penalty (ideal range)
        }

        // Generate specific warnings in Danish
        if (!empty($emptyProperties)) {
            $warnings[] = sprintf(
                'Egenskaber uden valgmuligheder: %s',
                implode(', ', $emptyProperties)
            );
        }

        if (!empty($overloadedProperties)) {
            $warnings[] = sprintf(
                'Egenskaber med mange valgmuligheder (indikerer usikkerhed): %s',
                implode(', ', $overloadedProperties)
            );
        }

        return [$penalty, $warnings];
    }

    /**
     * Calculates confidence based on raw token log probabilities (The "Hybrid" Method)
     *
     * This method is for future Vertex AI integration where log probabilities are available.
     * Currently not used since the standard Gemini API doesn't provide log probs.
     *
     * @param array<float> $logProbs Array of log probabilities (e.g., [-0.1, -0.05, -2.3])
     */
    private function calculateLogProbConfidence(array $logProbs): float
    {
        if (empty($logProbs)) {
            return 0.5;
        }

        // Convert log probabilities to probabilities (0-1)
        // exp(-0.1) ≈ 0.90, exp(-2.3) ≈ 0.10
        $probs = array_map('exp', $logProbs);

        // Metric A: Average Confidence (General Belief)
        $avgConf = array_sum($probs) / count($probs);

        // Metric B: Minimum Confidence (The "Weakest Link")
        // If most tokens have high confidence but one is uncertain (e.g., art style identification),
        // the minimum confidence catches that weakness.
        $minConf = min($probs);

        // Calculate Weighted Score
        // We weigh the average heavily, but the minimum acts as a penalty anchor.
        $hybridScore = ($avgConf * 0.7) + ($minConf * 0.3);

        return $hybridScore;
    }
}
