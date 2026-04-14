<?php declare(strict_types=1);

namespace CMaintz\ImageAi\DTO\Analysis;

/**
 * DTO for product description data from API response
 *
 * @phpstan-immutable
 */
readonly class DescriptionDataDTO
{
    public function __construct(
        public string $description,
        public float $confidence
    ) {
    }

    /**
     * Create from API response array
     * @param array{description: string, confidence: float|int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            description: $data['description'] ?? '',
            confidence: (float) ($data['confidence'] ?? 0.0)
        );
    }
}
