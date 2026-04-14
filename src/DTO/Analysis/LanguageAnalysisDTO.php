<?php declare(strict_types=1);

namespace Illux\ImageAi\DTO\Analysis;

/**
 * DTO for language-specific analysis data from API response
 *
 * @phpstan-immutable
 */
readonly class LanguageAnalysisDTO
{
    public function __construct(
        public string $languageCode,
        public ?MetaDataDTO $metaData,
        public ?DescriptionDataDTO $descriptionData
    ) {
    }

    /**
     * Create from API response array
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $analysis */
        $analysis = $data['analysis'] ?? [];

        /** @var array{metaTitleData: mixed, metaDescriptionData: mixed, seoKeywordsData: mixed} $metaDataArray */
        $metaDataArray = is_array($analysis['metaData'] ?? null) ? $analysis['metaData'] : [];

        /** @var array{description: string, confidence: float|int} $descDataArray */
        $descDataArray = is_array($analysis['descriptionData'] ?? null) ? $analysis['descriptionData'] : [];

        return new self(
            languageCode: (string) ($data['language'] ?? 'en-GB'),
            metaData: isset($analysis['metaData']) && is_array($analysis['metaData'])
                ? MetaDataDTO::fromArray($metaDataArray)
                : null,
            descriptionData: isset($analysis['descriptionData']) && is_array($analysis['descriptionData'])
                ? DescriptionDataDTO::fromArray($descDataArray)
                : null
        );
    }

    /**
     * Get average confidence for this language
     */
    public function getAverageConfidence(): float
    {
        $scores = [];

        if ($this->metaData !== null) {
            $scores[] = $this->metaData->getAverageConfidence();
        }

        if ($this->descriptionData !== null) {
            $scores[] = $this->descriptionData->confidence;
        }

        if (empty($scores)) {
            return 0.0;
        }

        return array_sum($scores) / count($scores);
    }

    /**
     * Check if this language analysis has any content
     */
    public function hasContent(): bool
    {
        return $this->metaData !== null || $this->descriptionData !== null;
    }
}
