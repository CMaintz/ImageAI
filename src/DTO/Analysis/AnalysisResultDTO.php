<?php declare(strict_types=1);

namespace CMaintz\ImageAi\DTO\Analysis;

use InvalidArgumentException;

/**
 * DTO for complete analysis result from API response
 *
 * Provides type-safe access to API response data with validation and mapping helpers.
 *
 * @phpstan-immutable
 * @phpstan-type PropertiesArray array<string, array{options: string[]}>
 * @phpstan-type ApiResponseData array{
 *     productId: string,
 *     analysisResultId: string,
 *     properties: PropertiesArray,
 *     analysis-data: array<int, array{language: string, analysis: mixed}>
 * }
 */
readonly class AnalysisResultDTO
{
    /**
     * @param LanguageAnalysisDTO[] $languageAnalyses
     * @param array<string, array{options: string[]}> $properties
     */
    public function __construct(
        public string $productId,
        public string $analysisResultId,
        public array $properties,
        public array $languageAnalyses
    ) {
    }

    /**
     * Create from API response array with validation
     * @param ApiResponseData $data
     * @throws InvalidArgumentException If required fields are missing
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['productId']) || !isset($data['analysisResultId'])) {
            throw new InvalidArgumentException('Missing required fields: productId or analysisResultId');
        }

        $languageAnalyses = [];
        foreach ($data['analysis-data'] ?? [] as $languageData) {
            if (!is_array($languageData)) {
                continue;
            }

            $languageAnalyses[] = LanguageAnalysisDTO::fromArray($languageData);
        }

        return new self(
            productId: $data['productId'],
            analysisResultId: $data['analysisResultId'],
            properties: $data['properties'] ?? [],
            languageAnalyses: $languageAnalyses
        );
    }

    /**
     * Get language analysis by language code
     */
    public function getLanguageAnalysis(string $languageCode): ?LanguageAnalysisDTO
    {
        foreach ($this->languageAnalyses as $analysis) {
            if ($analysis->languageCode === $languageCode) {
                return $analysis;
            }
        }

        return null;
    }

    /**
     * Check if analysis has any language data
     */
    public function hasLanguageData(): bool
    {
        return !empty($this->languageAnalyses);
    }

    /**
     * Get all language codes that have analysis data
     * @return string[]
     */
    public function getLanguageCodes(): array
    {
        return array_map(
            fn(LanguageAnalysisDTO $analysis) => $analysis->languageCode,
            $this->languageAnalyses
        );
    }
}
