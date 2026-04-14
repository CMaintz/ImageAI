<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Service\Analysis;

use CMaintz\ImageAi\DTO\Analysis\AnalysisResultDTO;
use CMaintz\ImageAi\DTO\Analysis\LanguageAnalysisDTO;
use CMaintz\ImageAi\Model\Enum\AiAnalysisStatusEnum;
use Throwable;

/**
 * Service responsible for mapping API analysis results to entity data
 *
 * Uses DTOs for type-safe mapping and provides centralized confidence calculation logic.
 * Focuses on analysis result entity data; product updates are handled by ProductUpdateAssembler.
 *
 * @phpstan-type EntityTranslation array{
 *     metaTitle?: string,
 *     metaDescription?: string,
 *     seoKeywords?: string,
 *     productDescription?: string
 * }
 * @phpstan-type EntityData array{
 *     id: string,
 *     status: string,
 *     translations?: array<string, EntityTranslation>,
 *     analysisData?: array,
 *     totalConfidenceScore?: float,
 *     confidenceWarnings?: array,
 *     analyzedProperties?: array,
 *     suggestedPropertyOptionCandidates?: array,
 *     errorMessage?: string|null
 * }
 */
class AnalysisMapper
{
    public function __construct(
        private readonly ConfidenceCalculator $confidenceCalculator
    ) {
    }

    /**
     * Map API response to entity upsert data using DTOs
     * @param string $analysisResultId Analysis result entity ID
     * @param string $productId Product ID
     * @param AiAnalysisStatusEnum $status Status of the analysis
     * @param array|null $analysisData Raw API response data
     * @param string|null $errorMessage Error message if analysis failed
     * @return EntityData Entity data ready for repository upsert
     */
    public function mapToEntityData(
        string $analysisResultId,
        string $productId,
        AiAnalysisStatusEnum $status,
        ?array $analysisData = null,
        ?string $errorMessage = null
    ): array {
        $entityData = [
            'id' => $analysisResultId,
            'productId' => $productId,
            'status' => $status->value,
        ];

        if ($errorMessage !== null) {
            $entityData['errorMessage'] = $errorMessage;
        }

        if ($analysisData !== null
            && isset($analysisData['analysis-data'])
            && is_array($analysisData['analysis-data'])) {
            try {
                $dto = AnalysisResultDTO::fromArray([
                    'productId' => $productId,
                    'analysisResultId' => $analysisResultId,
                    'properties' => $analysisData['properties'] ?? [],
                    'analysis-data' => $analysisData['analysis-data']
                ]);

                $entityData['translations'] = $this->extractTranslations($dto);

                $entityData['analysisData'] = $analysisData;

                // Calculate confidence with heuristics
                $confidenceResult = $this->confidenceCalculator->calculate($dto);
                $entityData['totalConfidenceScore'] = $confidenceResult->score;
                $entityData['confidenceWarnings'] = $confidenceResult->warnings;

                // Override status to PendingReview if confidence is low
                if ($confidenceResult->requiresReview && $status === AiAnalysisStatusEnum::AutoApproved) {
                    $entityData['status'] = AiAnalysisStatusEnum::PendingReview->value;
                }

                if (!empty($dto->properties)) {
                    $entityData['analyzedProperties'] = $dto->properties;

                    // Extract suggested options from properties
                    $suggestedOptions = $this->extractSuggestedOptions($dto->properties);
                    if (!empty($suggestedOptions)) {
                        $entityData['suggestedPropertyOptionCandidates'] = $suggestedOptions;
                    }
                }
            } catch (Throwable) {
                // -1 flags mapping failure; raw data preserved for manual review
                $entityData['analysisData'] = $analysisData;
                $entityData['totalConfidenceScore'] = -1;
            }
        }

        return $entityData;
    }

    /** @return array<string, EntityTranslation> */
    private function extractTranslations(AnalysisResultDTO $dto): array
    {
        if (!$dto->hasLanguageData()) {
            return [];
        }

        $translations = [];

        foreach ($dto->languageAnalyses as $languageAnalysis) {
            if (!$languageAnalysis->hasContent()) {
                continue;
            }

            $translationData = $this->extractLanguageTranslation($languageAnalysis);

            if (!empty($translationData)) {
                $translations[$languageAnalysis->languageCode] = $translationData;
            }
        }

        return $translations;
    }

    /** @return EntityTranslation */
    private function extractLanguageTranslation(LanguageAnalysisDTO $analysis): array
    {
        $translationData = [];

        if ($analysis->metaData !== null) {
            $translationData['metaTitle'] = $analysis->metaData->metaTitleData->metaTitle;
            $translationData['metaDescription'] = $analysis->metaData->metaDescriptionData->metaDescription;
            $translationData['seoKeywords'] = $analysis->metaData->seoKeywordsData->getKeywordsString();
        }

        if ($analysis->descriptionData !== null) {
            $translationData['productDescription'] = $analysis->descriptionData->description;
        }

        return $translationData;
    }

    /**
     * Extract suggested options from analyzed properties
     *
     * Collects all suggestedOptions from each property group into a flat structure
     * for storage in suggestedPropertyOptionCandidates.
     *
     * @param array $properties Analyzed properties from the API response
     * @return array<string, array<string>> Map of property group name => suggested option names
     */
    private function extractSuggestedOptions(array $properties): array
    {
        $suggested = [];

        foreach ($properties as $propertyName => $propertyData) {
            if (!is_array($propertyData)) {
                continue;
            }

            $suggestedOptions = $propertyData['suggestedOptions'] ?? [];

            if (!empty($suggestedOptions) && is_array($suggestedOptions)) {
                // Normalize property name (convert snake_case back to spaces for lookup)
                $normalizedName = str_replace('_', ' ', $propertyName);
                $suggested[$normalizedName] = array_values(array_filter($suggestedOptions, 'is_string'));
            }
        }

        return $suggested;
    }
}
