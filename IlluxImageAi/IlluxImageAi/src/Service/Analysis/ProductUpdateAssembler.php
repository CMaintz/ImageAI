<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Analysis;

use Illux\ImageAi\Service\Property\PropertyLookupService;
use Shopware\Core\Framework\Context;

/**
 * Assembles product update data from AI analysis results.
 *
 * Combines translation extraction with property ID resolution to create
 * complete product update arrays ready for repository update.
 */
class ProductUpdateAssembler
{
    public function __construct(
        private readonly TranslationDataExtractor $translationExtractor,
        private readonly PropertyLookupService $propertyLookupService
    ) {
    }

    /**
     * Assemble product update from raw analysis data (fresh from AI)
     *
     * @param string $productId Product ID to update
     * @param array $analysisData Raw analysis response from Gemini
     * @param Context $context Shopware context
     * @return array Product update data ready for repository
     */
    public function assembleFromAnalysisData(string $productId, array $analysisData, Context $context): array
    {
        $updateData = [
            'id' => $productId,
            'translations' => $this->translationExtractor->extractFromAnalysisData($analysisData),
        ];

        if (isset($analysisData['properties']) && is_array($analysisData['properties'])) {
            $propertyIds = $this->extractPropertyOptionIds($analysisData['properties'], $context);
            if (!empty($propertyIds)) {
                $updateData['properties'] = array_map(fn($id) => ['id' => $id], $propertyIds);
            }
        }

        return $updateData;
    }

    /**
     * Assemble product update from analysis entity (approval workflow)
     *
     * @param string $productId Product ID to update
     * @param iterable $entityTranslations Analysis entity translations
     * @param array|null $analyzedProperties Stored property data from entity
     * @param Context $context Shopware context
     * @return array Product update data ready for repository
     */
    public function assembleFromEntityData(
        string $productId,
        iterable $entityTranslations,
        ?array $analyzedProperties,
        Context $context
    ): array {
        $updateData = [
            'id' => $productId,
            'translations' => $this->translationExtractor->extractFromEntityTranslations($entityTranslations),
        ];

        if ($analyzedProperties !== null) {
            $propertyIds = $this->extractPropertyOptionIds($analyzedProperties, $context);
            if (!empty($propertyIds)) {
                $updateData['properties'] = array_map(fn($id) => ['id' => $id], $propertyIds);
            }
        }

        return $updateData;
    }

    /**
     * Check if product update has any meaningful data
     */
    public function hasUpdateData(array $updateData): bool
    {
        return !empty($updateData['translations']) || !empty($updateData['properties']);
    }

    /**
     * Extract property option IDs from analysis properties
     *
     * Throws on lookup failures - caller should handle.
     *
     * @param array $properties Properties like {"style": {"options": [...], "confidence": 0.9}}
     * @return array<string> Property option IDs
     */
    private function extractPropertyOptionIds(array $properties, Context $context): array
    {
        $shopwarePropertyValues = [];

        foreach ($properties as $groupName => $groupData) {
            if (isset($groupData['options']) && is_array($groupData['options'])) {
                $shopwarePropertyValues[$groupName] = $groupData['options'];
            }
        }

        if (empty($shopwarePropertyValues)) {
            return [];
        }

        return $this->propertyLookupService->getPropertyOptionIds($shopwarePropertyValues, $context);
    }
}
