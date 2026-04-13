<?php declare(strict_types=1);

namespace Illux\ImageAi\DTO\Analysis;

/**
 * DTO for meta description data from API response
 *
 * @phpstan-immutable
 */
readonly class MetaDescriptionDataDTO
{
    public function __construct(
        public string $metaDescription,
        public float $confidence
    ) {
    }

    /**
     * Create from API response array
     * @param array{metaDescription: string, confidence: float|int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            metaDescription: $data['metaDescription'] ?? '',
            confidence: (float) ($data['confidence'] ?? 0.0)
        );
    }
}
