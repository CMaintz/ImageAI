<?php declare(strict_types=1);

namespace Illux\ImageAi\DTO\Analysis;

/**
 * DTO for SEO keywords data from API response
 *
 * @phpstan-immutable
 * @phpstan-type SeoKeywordsArray array{seoKeywords: string[], confidence: float|int}
 */
readonly class SeoKeywordsDataDTO
{
    /**
     * @param string[] $seoKeywords
     */
    public function __construct(
        public array $seoKeywords,
        public float $confidence
    ) {
    }

    /**
     * Create from API response array
     * @param SeoKeywordsArray $data
     */
    public static function fromArray(array $data): self
    {
        $keywords = $data['seoKeywords'] ?? [];
        if (!is_array($keywords)) {
            $keywords = [];
        }

        return new self(
            seoKeywords: $keywords,
            confidence: (float) ($data['confidence'] ?? 0.0)
        );
    }

    /**
     * Get keywords as comma-separated string
     */
    public function getKeywordsString(): string
    {
        return implode(', ', $this->seoKeywords);
    }
}
