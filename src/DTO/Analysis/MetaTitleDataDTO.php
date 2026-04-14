<?php declare(strict_types=1);

namespace Illux\ImageAi\DTO\Analysis;

/**
 * DTO for meta title data from API response
 *
 * @phpstan-immutable
 */
readonly class MetaTitleDataDTO
{
    public function __construct(
        public string $metaTitle,
        public float $confidence
    ) {
    }

    /**
     * Create from API response array
     * @param array{metaTitle: string, confidence: float|int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            metaTitle: $data['metaTitle'] ?? '',
            confidence: (float) ($data['confidence'] ?? 0.0)
        );
    }
}
