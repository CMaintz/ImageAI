<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Analysis;

use Illux\ImageAi\DTO\Analysis\LanguageAnalysisDTO;

/**
 * Extracts translation data from AI analysis responses for product updates.
 *
 * Provides a single source of truth for the translation data structure
 * expected by Shopware product updates.
 *
 * Uses the same DTO parsing as AnalysisMapper for consistency.
 *
 * @phpstan-type ProductTranslation array{
 *     metaTitle?: string,
 *     metaDescription?: string,
 *     keywords?: string,
 *     description?: string
 * }
 */
class TranslationDataExtractor
{
    /**
     * Extract translations from raw analysis data (fresh from AI)
     *
     * Uses LanguageAnalysisDTO for consistent parsing with AnalysisMapper.
     *
     * @param array $analysisData Raw API response
     * @return array<string, ProductTranslation> Translations keyed by language code
     */
    public function extractFromAnalysisData(array $analysisData): array
    {
        if (!isset($analysisData['analysis-data']) || !is_array($analysisData['analysis-data'])) {
            return [];
        }

        $translations = [];

        foreach ($analysisData['analysis-data'] as $languageData) {
            if (!is_array($languageData)) {
                continue;
            }

            $dto = LanguageAnalysisDTO::fromArray($languageData);

            if (!$dto->hasContent()) {
                continue;
            }

            $translationData = $this->extractFromLanguageDTO($dto);
            if (!empty($translationData)) {
                $translations[$dto->languageCode] = $translationData;
            }
        }

        return $translations;
    }

    /**
     * Extract translation data from a LanguageAnalysisDTO
     * @return ProductTranslation
     */
    private function extractFromLanguageDTO(LanguageAnalysisDTO $dto): array
    {
        $translationData = [];

        if ($dto->metaData !== null) {
            $translationData['metaTitle'] = $dto->metaData->metaTitleData->metaTitle;
            $translationData['metaDescription'] = $dto->metaData->metaDescriptionData->metaDescription;
            $translationData['keywords'] = $dto->metaData->seoKeywordsData->getKeywordsString();
        }

        if ($dto->descriptionData !== null) {
            $translationData['description'] = $dto->descriptionData->description;
        }

        return $translationData;
    }

    /**
     * Extract translations from analysis entity translations
     *
     * Used by ApprovalService when applying stored analysis results.
     * Entity translations use languageId as key, not language code.
     *
     * @param iterable $entityTranslations Analysis entity translation collection
     * @return array<string, ProductTranslation> Translations keyed by languageId
     */
    public function extractFromEntityTranslations(iterable $entityTranslations): array
    {
        $translations = [];

        foreach ($entityTranslations as $translation) {
            $languageId = $translation['languageId'] ?? null;
            if (!$languageId || !is_string($languageId)) {
                continue;
            }

            $translationData = [];

            if (!empty($translation['metaTitle']) && is_string($translation['metaTitle'])) {
                $translationData['metaTitle'] = $translation['metaTitle'];
            }
            if (!empty($translation['metaDescription']) && is_string($translation['metaDescription'])) {
                $translationData['metaDescription'] = $translation['metaDescription'];
            }
            if (!empty($translation['seoKeywords']) && is_string($translation['seoKeywords'])) {
                $translationData['keywords'] = $translation['seoKeywords'];
            }
            if (!empty($translation['productDescription']) && is_string($translation['productDescription'])) {
                $translationData['description'] = $translation['productDescription'];
            }

            if (!empty($translationData)) {
                $translations[$languageId] = $translationData;
            }
        }

        return $translations;
    }
}
