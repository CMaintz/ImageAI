<?php declare(strict_types=1);

namespace Illux\ImageAi\Service;

use Illux\ImageAi\Config\PluginConstants;
use Illux\ImageAi\Config\IlluxConfiguration;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\Language\LanguageEntity;

/**
 * Centralized language configuration service
 * Single source of truth for language names and configuration
 */
class LanguageConfigurationService
{
    /**
     * Static mapping of language codes to human-readable names
     *
     * Kept as a constant for fast lookup without DB queries and as a fallback
     * when languages aren't installed in the Shopware instance.
     * This is used for prompt building where we need consistent English names.
     *
     * @var array<string, string>
     */
    private const array LANGUAGE_NAMES = [
        'en-GB' => 'English',
        'de-DE' => 'German',
        'da-DK' => 'Danish',
        'fr-FR' => 'French',
        'es-ES' => 'Spanish',
        'it-IT' => 'Italian',
        'nl-NL' => 'Dutch',
        'pl-PL' => 'Polish',
        'nn-NO' => 'Norwegian',
        'nb-NO' => 'Norwegian',
        'sv-SE' => 'Swedish',
    ];
    /** @var array<string, string|null> */
    private array $languageIdCache = [];

    /**
     * @param EntityRepository<LanguageCollection> $languageRepository
     */
    public function __construct(
        private readonly IlluxConfiguration $config,
        private readonly EntityRepository $languageRepository
    ) {
    }

    public function getLanguageName(string $languageCode): string
    {
        return self::LANGUAGE_NAMES[$languageCode] ?? 'English';
    }

    public function getSupportedLanguages(): array
    {
        return self::LANGUAGE_NAMES;
    }

    public function getAnalysisLanguages(): array
    {
        return $this->config->getContentConfig()->analysisLanguages;
    }

    public function getDefaultLanguage(): string
    {
        return PluginConstants::DEFAULT_LANGUAGE;
    }

    public function isLanguageSupported(string $languageCode): bool
    {
        return isset(self::LANGUAGE_NAMES[$languageCode]);
    }

    public function formatLanguageList(array $languageCodes): string
    {
        return implode(', ', array_map(
            fn($code) => $this->getLanguageName($code) . " ($code)",
            $languageCodes
        ));
    }

    /**
     * Get Shopware language ID by language code
     *
     * Consolidates repeated language lookup logic across services.
     * Uses caching to avoid repeated database queries within the same request.
     *
     * @param string $languageCode ISO language code (e.g., 'en-GB')
     * @param Context $context Shopware context
     * @return string|null Language ID or null if not found
     */
    public function getLanguageIdByCode(string $languageCode, Context $context): ?string
    {
        if (isset($this->languageIdCache[$languageCode])) {
            return $this->languageIdCache[$languageCode];
        }

        $criteria = new Criteria();
        $criteria->addAssociation('locale');
        $criteria->addFilter(new EqualsFilter('locale.code', $languageCode));
        $criteria->setLimit(1);

        /** @var LanguageEntity|null $language */
        $language = $this->languageRepository->search($criteria, $context)->first();
        $languageId = $language?->getId();

        $this->languageIdCache[$languageCode] = $languageId;

        return $languageId;
    }

    /**
     * Get multiple language IDs by language codes in a single query
     *
     * More efficient than multiple getLanguageIdByCode() calls.
     * Returns a map of language code => language ID.
     *
     * @param array<string> $languageCodes Array of language codes
     * @param Context $context Shopware context
     * @return array<string, string> Map of language code => language ID (codes not found are omitted)
     */
    public function getLanguageIdsByMultipleCodes(array $languageCodes, Context $context): array
    {
        /** @var array<string> $uncachedCodes */
        $uncachedCodes = [];
        /** @var array<string, string> $result */
        $result = [];

        // Check cache first
        foreach ($languageCodes as $code) {
            if (isset($this->languageIdCache[$code]) && $this->languageIdCache[$code] !== null) {
                $result[$code] = $this->languageIdCache[$code];
            } else {
                $uncachedCodes[] = $code;
            }
        }

        // If all were cached, return early
        if (empty($uncachedCodes)) {
            return $result;
        }

        // Fetch uncached languages in a single query (filtered by locale codes)
        $criteria = new Criteria();
        $criteria->addAssociation('locale');
        $criteria->addFilter(new EqualsAnyFilter('locale.code', $uncachedCodes));

        $languages = $this->languageRepository->search($criteria, $context);

        // Build result map and update cache
        /** @var LanguageEntity $language */
        foreach ($languages as $language) {
            $locale = $language->getLocale();
            if ($locale !== null && in_array($locale->getCode(), $uncachedCodes, true)) {
                $code = $locale->getCode();
                $result[$code] = $language->getId();
                $this->languageIdCache[$code] = $language->getId();
            }
        }

        foreach ($uncachedCodes as $code) {
            if (!isset($result[$code])) {
                $this->languageIdCache[$code] = null;
            }
        }

        return $result;
    }

    /**
     * Clear the language ID cache
     *
     * Useful for testing or when language configuration changes.
     */
    public function clearLanguageIdCache(): void
    {
        $this->languageIdCache = [];
    }

    /**
     * Create a context with a specific language set
     *
     * @param Context $originalContext The original context to clone
     * @param string $languageCode The language code to use (e.g., 'en-GB')
     * @return Context A new context with the specified language, or original if language not found
     */
    public function createContextForLanguage(Context $originalContext, string $languageCode): Context
    {
        $languageId = $this->getLanguageIdByCode($languageCode, $originalContext);

        if ($languageId === null) {
            return $originalContext;
        }

        return new Context(
            $originalContext->getSource(),
            $originalContext->getRuleIds(),
            $originalContext->getCurrencyId(),
            [$languageId],
            $originalContext->getVersionId(),
            $originalContext->getCurrencyFactor(),
            $originalContext->considerInheritance(),
            $originalContext->getTaxState(),
            $originalContext->getRounding()
        );
    }

    /**
     * Create an English context for API consistency
     *
     * @param Context $originalContext The original context
     * @return Context Context with English language, or original if English not found
     */
    public function createEnglishContext(Context $originalContext): Context
    {
        return $this->createContextForLanguage($originalContext, 'en-GB');
    }
}
