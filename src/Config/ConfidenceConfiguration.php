<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Config;

/**
 * Value object for confidence calculation configuration
 *
 * Immutable configuration for confidence scoring settings.
 * Controls field weights, penalty values, and pattern matching.
 *
 * @phpstan-immutable
 */
readonly class ConfidenceConfiguration
{
    /**
     * @param bool $enableConfidenceThreshold Whether to enable confidence-based review flagging
     * @param float $lowConfidenceThreshold Threshold below which results are flagged for review (0.0-1.0)
     * @param float $fieldWeightMetaTitle Weight for meta title in confidence calculation
     * @param float $fieldWeightMetaDescription Weight for meta description in confidence calculation
     * @param float $fieldWeightProductDescription Weight for product description in confidence calculation
     * @param float $fieldWeightSeoKeywords Weight for SEO keywords in confidence calculation
     * @param float $fieldWeightProperties Weight for properties in confidence calculation
     * @param array<string> $genericPatterns Regex patterns indicating generic/low-quality content
     * @param array<string> $hedgingWords Words indicating AI uncertainty
     * @param int $idealOptionsPerProperty Ideal number of options per property (1-3 recommended)
     * @param float $emptyPropertyPenalty Penalty for properties with no options
     * @param float $excessOptionPenalty Penalty per option beyond ideal
     * @param float $minLengthRatio Minimum length as percentage of max (content below is "too short")
     * @param float $shortTitlePenalty Penalty for meta title below minimum length
     * @param float $longTitlePenalty Penalty for meta title exceeding max length
     * @param float $shortDescriptionPenalty Penalty for description below minimum length
     * @param float $fewKeywordsPenalty Penalty for too few keywords
     * @param float $genericContentPenalty Penalty for generic/placeholder content
     * @param float $hedgingPenaltyPerInstance Penalty per hedging word instance
     * @param float $hedgingPenaltyMax Maximum total hedging penalty
     * @param float $duplicateContentPenalty Penalty for duplicate content across languages
     * @param float $noPropertiesPenalty Penalty when no properties detected
     * @param float $lowPropertyMatchPenalty Penalty for low property match rate
     * @param float $maxTotalPenalty Maximum total penalty cap
     */
    public function __construct(
        public bool $enableConfidenceThreshold,
        public float $lowConfidenceThreshold,
        public float $fieldWeightMetaTitle,
        public float $fieldWeightMetaDescription,
        public float $fieldWeightProductDescription,
        public float $fieldWeightSeoKeywords,
        public float $fieldWeightProperties,
        public array $genericPatterns,
        public array $hedgingWords,
        public int $idealOptionsPerProperty,
        public float $emptyPropertyPenalty,
        public float $excessOptionPenalty,
        public float $minLengthRatio,
        public float $shortTitlePenalty,
        public float $longTitlePenalty,
        public float $shortDescriptionPenalty,
        public float $fewKeywordsPenalty,
        public float $genericContentPenalty,
        public float $hedgingPenaltyPerInstance,
        public float $hedgingPenaltyMax,
        public float $duplicateContentPenalty,
        public float $noPropertiesPenalty,
        public float $lowPropertyMatchPenalty,
        public float $maxTotalPenalty,
    ) {
    }

    /**
     * Get field weights as associative array
     *
     * @return array<string, float>
     */
    public function getFieldWeights(): array
    {
        return [
            'metaTitle' => $this->fieldWeightMetaTitle,
            'metaDescription' => $this->fieldWeightMetaDescription,
            'productDescription' => $this->fieldWeightProductDescription,
            'seoKeywords' => $this->fieldWeightSeoKeywords,
            'properties' => $this->fieldWeightProperties,
        ];
    }

    /**
     * Check if confidence threshold checking is enabled
     */
    public function isConfidenceCheckingEnabled(): bool
    {
        return $this->enableConfidenceThreshold;
    }
}
