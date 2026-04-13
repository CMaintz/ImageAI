<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Property;

use Illux\ImageAi\Config\PluginConstants;
use Illux\ImageAi\Service\LanguageConfigurationService;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;

/**
 * Service for finding and caching property groups and options.
 *
 * Uses a two-tier caching strategy:
 * 1. Persistent cache (PSR-6) for cross-request performance (1 hour TTL)
 * 2. In-memory cache for within-request performance
 */
class PropertyLookupService
{
    /** @var PropertyGroupEntity[]|null In-memory cache for AI property groups */
    private ?array $propertyGroupCache = null;

    /** @var array<string, string> In-memory cache for option lookups: "GroupName::OptionName" => optionId */
    private array $optionCache = [];

    /** @var array<string, string> In-memory cache for group lookups: "GroupName" => groupId */
    private array $groupCache = [];

    /**
     * @param EntityRepository<PropertyGroupCollection<PropertyGroupEntity>> $propertyGroupRepository
     * @param EntityRepository<PropertyGroupOptionCollection<PropertyGroupOptionEntity>> $propertyGroupOptionRepository
     */
    public function __construct(
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly LanguageConfigurationService $languageConfigService,
        private readonly ?CacheItemPoolInterface $cache = null
    ) {
    }

    /**
     * Load all AI-managed property groups from Shopware.
     * AI property groups are identified by: customFields.illux_ai_managed = true
     *
     * @return PropertyGroupEntity[]
     */
    public function loadAiPropertyGroups(Context $context): array
    {
        if ($this->propertyGroupCache !== null) {
            return $this->propertyGroupCache;
        }

        $englishContext = $this->languageConfigService->createEnglishContext($context);

        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('customFields.' . PluginConstants::CUSTOM_FIELD_AI_MANAGED, true)
        );
        $criteria->addAssociation('options');

        $result = $this->propertyGroupRepository->search($criteria, $englishContext);

        $this->propertyGroupCache = $result->getEntities()->getElements();

        return $this->propertyGroupCache;
    }

    /**
     * Preload all property options for AI-managed groups into cache.
     *
     * Call this once before processing a batch of products to avoid N+1 queries.
     * First checks persistent cache, then falls back to database query.
     * Populates both optionCache and groupCache.
     *
     * @return int Number of options preloaded
     */
    public function preloadAllPropertyOptions(Context $context): int
    {
        // Try persistent cache first (fast path)
        if (!empty($this->optionCache) || $this->loadFromPersistentCache()) {
            return count($this->optionCache);
        }

        $propertyGroups = $this->loadAiPropertyGroups($context);

        if (empty($propertyGroups)) {
            return 0;
        }

        // Collect all group IDs and populate group cache (normalized: spaces)
        $groupIds = [];
        foreach ($propertyGroups as $group) {
            $groupIds[] = $group->getId();
            $groupName = $group->getName();
            $this->groupCache[$groupName] = $group->getId();
        }

        // Use English context since AI returns English names
        $englishContext = $this->languageConfigService->createEnglishContext($context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('groupId', $groupIds));
        $criteria->addAssociation('group');

        $allOptions = $this->propertyGroupOptionRepository->search($criteria, $englishContext);

        // Build lookup cache: "GroupName::OptionName" => optionId
        $preloadCount = 0;

        /** @var PropertyGroupOptionEntity $option */
        foreach ($allOptions as $option) {
            $groupEntity = $option->getGroup();
            if ($groupEntity === null) {
                continue;
            }

            $groupName = $groupEntity->getName();
            $optionName = $option->getName();

            // Cache exact match: "Group Name::Option Name"
            $cacheKey = $groupName . '::' . $optionName;
            $this->optionCache[$cacheKey] = $option->getId();

            // Cache case-insensitive fallback
            $cacheKeyLower = $groupName . '::' . strtolower($optionName ?? '');
            $this->optionCache[$cacheKeyLower] = $option->getId();

            $preloadCount++;
        }

        $this->saveToPersistentCache();
        return $preloadCount;
    }

    /**
     * Find a property group by name (searches using English context since AI returns English names)
     * @return string|null Group ID if found, null otherwise
     */
    public function findPropertyGroup(string $groupName, Context $context): ?string
    {
        // Normalize group name (underscores to spaces) for consistent cache keys
        $normalizedName = str_replace('_', ' ', $groupName);

        if (isset($this->groupCache[$normalizedName])) {
            return $this->groupCache[$normalizedName];
        }

        $englishContext = $this->languageConfigService->createEnglishContext($context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $normalizedName));

        /** @var PropertyGroupEntity|null $group */
        $group = $this->propertyGroupRepository->search($criteria, $englishContext)->first();

        if ($group !== null) {
            $this->groupCache[$normalizedName] = $group->getId();
            return $group->getId();
        }

        return null;
    }

    /**
     * Find a property option by group name and option name.
     * Uses English context since AI returns English names.
     * Uses case-insensitive matching as fallback.
     *
     * For best performance, call preloadAllPropertyOptions() before processing batches.
     *
     * @return string|null Option ID if found, null otherwise
     */
    public function findPropertyOption(string $groupName, string $optionName, Context $context): ?string
    {
        // Normalize group name (underscores to spaces) for consistent cache keys
        $normalizedGroupName = str_replace('_', ' ', $groupName);
        $cacheKey = $normalizedGroupName . '::' . $optionName;

        if (isset($this->optionCache[$cacheKey])) {
            return $this->optionCache[$cacheKey];
        }

        $cacheKeyLower = $normalizedGroupName . '::' . strtolower($optionName);
        if (isset($this->optionCache[$cacheKeyLower])) {
            $this->optionCache[$cacheKey] = $this->optionCache[$cacheKeyLower];
            return $this->optionCache[$cacheKeyLower];
        }

        // Cache miss - fallback to database query
        $englishContext = $this->languageConfigService->createEnglishContext($context);

        $criteria = new Criteria();
        $criteria->addAssociation('group');
        $criteria->addFilter(
            new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('group.name', $normalizedGroupName),
                new EqualsFilter('name', $optionName)
            ])
        );

        /** @var PropertyGroupOptionEntity|null $option */
        $option = $this->propertyGroupOptionRepository->search($criteria, $englishContext)->first();

        if ($option !== null) {
            $this->optionCache[$cacheKey] = $option->getId();
            return $option->getId();
        }

        $groupId = $this->findPropertyGroup($groupName, $context);
        if ($groupId === null) {
            return null;
        }

        return $this->findPropertyOptionCaseInsensitive($groupId, $optionName, $englishContext, $cacheKey);
    }

    /**
     * Convert property group keys and value names to Shopware property option IDs.
     * Only finds existing options - does NOT create anything.
     * @param array $properties Array like ['Medium' => ['options' => ['Watercolor', 'Oil'], 'confidence' => 0.95]]
     * @return string[] Array of property option IDs
     */
    public function getPropertyOptionIds(array $properties, Context $context): array
    {
        $optionIds = [];

        foreach ($properties as $groupKey => $propertyData) {
            $values = $propertyData['options'] ?? $propertyData;
            $valueArray = is_array($values) ? $values : [$values];

            foreach ($valueArray as $valueName) {
                $optionId = $this->findPropertyOption($groupKey, $valueName, $context);
                if ($optionId !== null) {
                    $optionIds[] = $optionId;
                }
                // Options not found are silently skipped - this is expected behavior
                // when AI suggests values that don't exist in the property group yet
            }
        }

        return array_unique($optionIds);
    }

    /**
     * Clear all internal caches (both in-memory and persistent)
     */
    public function clearCache(): void
    {
        $this->propertyGroupCache = null;
        $this->optionCache = [];
        $this->groupCache = [];

        if ($this->cache !== null) {
            $this->cache->deleteItem(PluginConstants::CACHE_KEY_PROPERTY_OPTIONS);
            $this->cache->deleteItem(PluginConstants::CACHE_KEY_PROPERTY_GROUPS);
        }
    }

    /**
     * Case-insensitive search for property option within a group.
     * Note: This is a fallback method used when preloadAllPropertyOptions() cache misses.
     * For best performance, ensure preloadAllPropertyOptions() is called before batch processing.
     */
    private function findPropertyOptionCaseInsensitive(
        string $groupId,
        string $optionName,
        Context $context,
        string $cacheKey
    ): ?string {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('groupId', $groupId));
        $criteria->setLimit(PluginConstants::SAFETY_LIMIT_PRODUCTS);

        $options = $this->propertyGroupOptionRepository->search($criteria, $context);

        if ($options->getTotal() >= PluginConstants::SAFETY_LIMIT_PRODUCTS) {
            throw new RuntimeException(sprintf(
                'Property option case-insensitive search hit safety limit (%d). ' .
                'Consider calling preloadAllPropertyOptions() before batch processing. ' .
                'GroupId: %s, OptionName: %s',
                PluginConstants::SAFETY_LIMIT_PRODUCTS,
                $groupId,
                $optionName
            ));
        }

        /** @var PropertyGroupOptionEntity $option */
        foreach ($options as $option) {
            if (strcasecmp($option->getName() ?? '', $optionName) === 0) {
                $this->optionCache[$cacheKey] = $option->getId();
                return $option->getId();
            }
        }

        return null;
    }

    /**
     * Try to load option cache from persistent storage.
     * Returns true if cache was found and loaded into memory.
     */
    private function loadFromPersistentCache(): bool
    {
        if ($this->cache === null) {
            return false;
        }

        $optionsItem = $this->cache->getItem(PluginConstants::CACHE_KEY_PROPERTY_OPTIONS);
        $groupsItem = $this->cache->getItem(PluginConstants::CACHE_KEY_PROPERTY_GROUPS);

        if ($optionsItem->isHit() && $groupsItem->isHit()) {
            $this->optionCache = $optionsItem->get() ?? [];
            $this->groupCache = $groupsItem->get() ?? [];

            return true;
        }

        return false;
    }

    /**
     * Save current in-memory cache to persistent storage.
     */
    private function saveToPersistentCache(): void
    {
        if ($this->cache === null) {
            return;
        }

        $optionsItem = $this->cache->getItem(PluginConstants::CACHE_KEY_PROPERTY_OPTIONS);
        $optionsItem->set($this->optionCache);
        $optionsItem->expiresAfter(PluginConstants::PROPERTY_CACHE_TTL_SECONDS);
        $this->cache->save($optionsItem);

        $groupsItem = $this->cache->getItem(PluginConstants::CACHE_KEY_PROPERTY_GROUPS);
        $groupsItem->set($this->groupCache);
        $groupsItem->expiresAfter(PluginConstants::PROPERTY_CACHE_TTL_SECONDS);
        $this->cache->save($groupsItem);
    }
}
