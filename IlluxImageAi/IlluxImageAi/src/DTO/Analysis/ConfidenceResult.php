<?php declare(strict_types=1);

namespace Illux\ImageAi\DTO\Analysis;

/**
 * DTO for calculated confidence result with warnings
 *
 * Simple data carrier for confidence calculation results.
 * Frontend handles display logic (level thresholds, formatting) independently.
 *
 * @phpstan-immutable
 */
readonly class ConfidenceResult
{
    /**
     * @param float $score Final confidence score (0.0-1.0)
     * @param bool $requiresReview Whether this result should be flagged for manual review
     * @param string[] $warnings List of warnings explaining penalties
     */
    public function __construct(
        public float $score,
        public bool $requiresReview,
        public array $warnings
    ) {
    }
}
