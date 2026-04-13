<?php declare(strict_types=1);

namespace Illux\ImageAi\DTO\Analysis;

/**
 * DTO for SEO metadata from API response
 *
 * @phpstan-immutable
 */
readonly class MetaDataDTO
{
    public function __construct(
        public MetaTitleDataDTO $metaTitleData,
        public MetaDescriptionDataDTO $metaDescriptionData,
        public SeoKeywordsDataDTO $seoKeywordsData
    ) {
    }

    /**
     * Create from API response array
     * @param array{metaTitleData: mixed, metaDescriptionData: mixed, seoKeywordsData: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            metaTitleData: MetaTitleDataDTO::fromArray($data['metaTitleData'] ?? []),
            metaDescriptionData: MetaDescriptionDataDTO::fromArray($data['metaDescriptionData'] ?? []),
            seoKeywordsData: SeoKeywordsDataDTO::fromArray($data['seoKeywordsData'] ?? [])
        );
    }

    /**
     * Get average confidence across all meta fields
     */
    public function getAverageConfidence(): float
    {
        return ($this->metaTitleData->confidence
            + $this->metaDescriptionData->confidence
            + $this->seoKeywordsData->confidence) / 3;
    }
}
