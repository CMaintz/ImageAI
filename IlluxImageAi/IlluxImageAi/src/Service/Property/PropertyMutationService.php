<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Property;

use Illux\ImageAi\Config\PluginConstants;
use RuntimeException;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Throwable;

/**
 * Service for creating and mutating property groups, options, and product assignments.
 */
class PropertyMutationService
{
    /**
     * @param EntityRepository<PropertyGroupCollection<PropertyGroupEntity>> $propertyGroupRepository
     * @param EntityRepository<PropertyGroupOptionCollection<PropertyGroupOptionEntity>> $propertyGroupOptionRepository
     * @param EntityRepository<ProductCollection<ProductEntity>> $productRepository
     */
    public function __construct(
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly EntityRepository $productRepository,
        private readonly PropertyLookupService $lookupService
    ) {
    }

    /**
     * Create a new property group with full configuration.
     *
     * @param array{
     *     name: string,
     *     displayType?: string,
     *     sortingType?: string,
     *     filterable?: bool,
     *     visibleOnProductDetailPage?: bool,
     *     position?: int,
     *     translations?: array<string, array{name: string}>,
     *     options?: array<array{name: string, translations?: array<string, array{name: string}>}>
     * } $data Property group data
     * @param Context $context
     * @return string The created group ID
     * @throws RuntimeException If creation fails
     */
    public function createPropertyGroup(array $data, Context $context): string
    {
        if (empty($data['name'])) {
            throw new RuntimeException('Property group name is required');
        }

        $groupId = Uuid::randomHex();

        try {
            $groupData = [
                'id' => $groupId,
                'name' => $data['name'],
                'displayType' => $data['displayType'] ?? 'text',
                'sortingType' => $data['sortingType'] ?? 'alphanumeric',
                'filterable' => $data['filterable'] ?? true,
                'visibleOnProductDetailPage' => $data['visibleOnProductDetailPage'] ?? true,
                'position' => $data['position'] ?? 1,
                'customFields' => [PluginConstants::CUSTOM_FIELD_AI_MANAGED => true],
            ];

            if (!empty($data['translations'])) {
                $groupData['translations'] = $data['translations'];
            }

            if (!empty($data['options'])) {
                $groupData['options'] = array_map(function (array $option) {
                    $optionData = [
                        'id' => Uuid::randomHex(),
                        'name' => $option['name'],
                    ];

                    if (!empty($option['translations'])) {
                        $optionData['translations'] = $option['translations'];
                    }

                    return $optionData;
                }, $data['options']);
            }

            $this->propertyGroupRepository->create([$groupData], $context);
            $this->lookupService->clearCache();

            return $groupId;
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to create property group '{$data['name']}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a new property option within a group.
     * Does NOT check if it exists - use PropertyLookupService::findPropertyOption first if needed.
     *
     * @param string $groupId The parent group ID
     * @param string $optionName The option name
     * @param array<string, array{name: string}>|null $translations Optional translations in Shopware DAL format:
     *  ['en-GB' => ['name' => 'Watercolor'], 'da-DK' => ['name' => 'Akvarel']]
     * @return string The created option ID
     * @throws RuntimeException If creation fails
     */
    public function createPropertyOption(
        string $groupId,
        string $optionName,
        Context $context,
        ?array $translations = null
    ): string {
        $optionId = Uuid::randomHex();

        try {
            $data = [
                'id' => $optionId,
                'groupId' => $groupId,
                'name' => $optionName,
            ];

            if (!empty($translations)) {
                $data['translations'] = $translations;
            }

            $this->propertyGroupOptionRepository->create([$data], $context);

            // Clear cache since we added a new option
            $this->lookupService->clearCache();

            return $optionId;
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to create property option '{$optionName}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Assign property options to a product.
     * Only assigns existing options - skips any that aren't found.
     *
     * @param string $productId The product ID
     * @param array $mappedProperties Array of group names => option names or arrays of option names
     */
    public function assignPropertiesToProduct(string $productId, array $mappedProperties, Context $context): void
    {
        $propertyOptionIds = [];

        foreach ($mappedProperties as $groupKey => $values) {
            $valueArray = is_array($values) ? $values : [$values];

            foreach ($valueArray as $value) {
                $optionId = $this->lookupService->findPropertyOption($groupKey, $value, $context);

                if ($optionId !== null) {
                    $propertyOptionIds[] = ['id' => $optionId];
                }
                // Options not found are silently skipped - this is expected behavior
                // when AI suggests values that don't exist in the property group yet
            }
        }

        if (empty($propertyOptionIds)) {
            return;
        }
        $this->productRepository->update([
            [
                'id' => $productId,
                'properties' => $propertyOptionIds,
            ],
        ], $context);
    }
}
