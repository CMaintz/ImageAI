<?php declare(strict_types=1);

namespace Illux\ImageAi\Installers;

use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\Language\LanguageEntity;

class PropertyGroupInstaller
{
    /**
     * @param EntityRepository<PropertyGroupCollection> $propertyGroupRepository
     * @param EntityRepository<LanguageCollection> $languageRepository
     * @param EntityRepository<PropertyGroupOptionCollection> $propertyGroupOptionRepository
     */
    public function __construct(
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $languageRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
    ) {
    }

    public function install(Context $context): void
    {
        $this->createDefaultPropertyGroups($context);
    }

    public function uninstall(Context $context): void
    {
        // Property groups are left in place on uninstall
        // They may contain user data (products assigned to these groups)
    }

    /**
     * Create default AI property groups with options and translations
     */
    private function createDefaultPropertyGroups(Context $context): void
    {
        // Get language IDs for translations
        $languageIds = $this->getLanguageIds($context);

        $propertyGroups = $this->getPropertyGroupDefinitions();

        foreach ($propertyGroups as $groupData) {
            // Check if property group already exists
            $existingGroupId = $this->getPropertyGroupId($groupData['name'], $context);

            // Build translations for property group with customFields
            $groupTranslations = [];
            foreach ($languageIds as $locale => $languageId) {
                if (isset($groupData['translations'][$locale])) {
                    $groupTranslations[$languageId] = [
                        'name' => $groupData['translations'][$locale]['group_name'],
                        'customFields' => [
                            'illux_ai_managed' => true,
                        ],
                    ];
                }
            }

            $options = [];
            foreach ($groupData['options'] as $optionName) {
                $optionTranslations = [];
                foreach ($languageIds as $locale => $languageId) {
                    if (isset($groupData['translations'][$locale]['options'][$optionName])) {
                        $optionTranslations[$languageId] = [
                            'name' => $groupData['translations'][$locale]['options'][$optionName],
                        ];
                    }
                }

                $options[] = [
                    'name' => $optionName,
                    'translations' => $optionTranslations,
                ];
            }

            try {
                if ($existingGroupId) {
                    $updateData = [
                        'id' => $existingGroupId,
                        'translations' => $groupTranslations,
                    ];
                    $this->propertyGroupRepository->update([$updateData], $context);
                    $this->addMissingOptions($existingGroupId, $groupData, $languageIds, $context);
                    error_log('[PropertyGroupInstaller] Updated existing property group with custom fields: '
                         . $groupData['name']);
                } else {
                    // Create new group with translations and options, including customFields
                    $createData = [
                        [
                            'name' => $groupData['name'],
                            'displayType' => 'text',
                            'sortingType' => 'alphanumeric',
                            'filterable' => true,
                            'visibleOnProductDetailPage' => false,
                            'position' => $groupData['position'],
                            'translations' => $groupTranslations,
                            'options' => $options,
                        ],
                    ];
                    $this->propertyGroupRepository->create($createData, $context);
                    error_log('[PropertyGroupInstaller] Created new property group with custom fields: '
                        . $groupData['name']);
                }
            } catch (WriteException $e) {
                error_log('[PropertyGroupInstaller] Error saving property group '
                    . $groupData['name'] . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Get language IDs indexed by locale code
     * @return array<string, string> Locale code => Language ID
     */
    private function getLanguageIds(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('locale');

        $languages = $this->languageRepository->search($criteria, $context);

        $languageIds = [];
        /** @var LanguageEntity $language */
        foreach ($languages as $language) {
            $locale = $language->getLocale();
            if ($locale !== null && in_array($locale->getCode(), ['en-GB', 'da-DK', 'nn-NO', 'sv-SE'])) {
                $languageIds[$locale->getCode()] = $language->getId();
            }
        }

        return $languageIds;
    }

    /**
     * Get property group ID by checking the en-GB translated name
     *
     * Returns the ID of the property group if it exists, null otherwise.
     */
    private function getPropertyGroupId(string $technicalName, Context $context): ?string
    {
        $languageIds = $this->getLanguageIds($context);

        if (!isset($languageIds['en-GB'])) {
            error_log('[PropertyGroupInstaller] en-GB language not found, falling back to technical name check');
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('name', $technicalName));
            $criteria->setLimit(1);
            $result = $this->propertyGroupRepository->searchIds($criteria, $context);
            return $result->firstId();
        }

        $enGbLanguageId = $languageIds['en-GB'];

        // Get the expected translated name from definitions
        $groupData = $this->findGroupDataByTechnicalName($technicalName);
        if (!$groupData || !isset($groupData['translations']['en-GB']['group_name'])) {
            error_log('[PropertyGroupInstaller] No en-GB translation found for: ' . $technicalName);
            return null;
        }

        $translatedName = $groupData['translations']['en-GB']['group_name'];
        error_log('[PropertyGroupInstaller] Searching for property group with en-GB name: ' . $translatedName);

        // Search for AI-managed property groups and check their en-GB translation
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addFilter(new EqualsFilter('customFields.illux_ai_managed', true));

        $propertyGroups = $this->propertyGroupRepository->search($criteria, $context);

        /** @var PropertyGroupEntity $group */
        foreach ($propertyGroups as $group) {
            $translations = $group->getTranslations();
            if ($translations !== null) {
                foreach ($translations as $translation) {
                    if ($translation->getLanguageId() === $enGbLanguageId &&
                        $translation->getName() === $translatedName) {
                        error_log('[PropertyGroupInstaller] Found existing property group: '
                            . $translatedName . ' (ID: ' . $group->getId() . ')');
                        return $group->getId();
                    }
                }
            }
        }

        error_log('[PropertyGroupInstaller] No existing property group found for: ' . $translatedName);
        return null;
    }

    /**
     * Find group data by technical name
     */
    private function findGroupDataByTechnicalName(string $technicalName): ?array
    {
        $allGroups = $this->getPropertyGroupDefinitions();

        foreach ($allGroups as $groupData) {
            if ($groupData['name'] === $technicalName) {
                return $groupData;
            }
        }

        return null;
    }

    /**
     * Add missing options to an existing property group
     * Compares the desired options with existing ones (by en-GB translated name) and only creates new options.
     */
    private function addMissingOptions(string $groupId, array $groupData, array $languageIds, Context $context): void
    {
        if (!isset($languageIds['en-GB'])) {
            error_log('[PropertyGroupInstaller] en-GB language not found, cannot check for duplicate options');
            return;
        }

        $enGbLanguageId = $languageIds['en-GB'];

        // Get existing options for this group with translations
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('groupId', $groupId));
        $criteria->addAssociation('translations');

        $existingOptions = $this->propertyGroupOptionRepository->search($criteria, $context);

        // Build a set of existing option names in en-GB
        $existingEnGbNames = [];
        /** @var PropertyGroupOptionEntity $option */
        foreach ($existingOptions as $option) {
            $translations = $option->getTranslations();
            if ($translations !== null) {
                foreach ($translations as $translation) {
                    if ($translation->getLanguageId() === $enGbLanguageId) {
                        $existingEnGbNames[] = $translation->getName();
                        break;
                    }
                }
            }
        }

        error_log('[PropertyGroupInstaller] Existing options in group (en-GB names): '
            . implode(', ', $existingEnGbNames));

        // Find options that don't exist yet
        $newOptions = [];
        foreach ($groupData['options'] as $optionName) {
            // Get the en-GB translated name for this option
            $enGbOptionName = $groupData['translations']['en-GB']['options'][$optionName] ?? $optionName;

            if (in_array($enGbOptionName, $existingEnGbNames, true)) {
                error_log('[PropertyGroupInstaller] Option already exists, skipping: ' . $enGbOptionName);
                continue;
            }

            error_log('[PropertyGroupInstaller] Adding new option: ' . $enGbOptionName);

            $optionTranslations = [];
            foreach ($languageIds as $locale => $languageId) {
                if (isset($groupData['translations'][$locale]['options'][$optionName])) {
                    $optionTranslations[$languageId] = [
                        'name' => $groupData['translations'][$locale]['options'][$optionName],
                    ];
                }
            }

            $newOptions[] = [
                'groupId' => $groupId,
                'name' => $optionName,
                'translations' => $optionTranslations,
            ];
        }

        if (!empty($newOptions)) {
            try {
                $this->propertyGroupOptionRepository->create($newOptions, $context);

                error_log('[PropertyGroupInstaller] Added ' . count($newOptions)
                    . ' missing options to property group: ' . $groupData['name']);
            } catch (WriteException $e) {
                error_log('[PropertyGroupInstaller] Error while adding options to property group '
                    . $groupData['name'] . ': ' . $e->getMessage());
            }
        } else {
            error_log('[PropertyGroupInstaller] No missing options for property group: ' . $groupData['name']);
        }
    }

    /**
     * Get all property group definitions with translations
     */
    private function getPropertyGroupDefinitions(): array
    {
        return [
            $this->getTechniqueDefinition(),
            $this->getSubjectDefinition(),
            $this->getStyleDefinition(),
            $this->getThemeMoodDefinition(),
            $this->getAestheticDefinition(),
            $this->getMotifDefinition(),
            $this->getColorDefinition(),
        ];
    }

    private function getTechniqueDefinition(): array
    {
        return [
            'name' => 'Technique',
            'position' => 1,
            'options' => [
                'Drawing',
                'Painting',
                'Digital Art',
                'Illustration',
                'Photography',
                'Mixed Media',
                'Collage',
                'Printmaking',
            ],
            'translations' => [
                'en-GB' => [
                    'group_name' => 'Technique',
                    'options' => [
                        'Drawing' => 'Drawing',
                        'Painting' => 'Painting',
                        'Digital Art' => 'Digital Art',
                        'Illustration' => 'Illustration',
                        'Photography' => 'Photography',
                        'Mixed Media' => 'Mixed Media',
                        'Collage' => 'Collage',
                        'Printmaking' => 'Printmaking',
                    ],
                ],
                'da-DK' => [
                    'group_name' => 'Teknik',
                    'options' => [
                        'Drawing' => 'Tegning',
                        'Painting' => 'Maleri',
                        'Digital Art' => 'Digital kunst',
                        'Illustration' => 'Illustration',
                        'Photography' => 'Fotografi',
                        'Mixed Media' => 'Blandede medier',
                        'Collage' => 'Collage',
                        'Printmaking' => 'Grafik',
                    ],
                ],
                'nn-NO' => [
                    'group_name' => 'Teknikk',
                    'options' => [
                        'Drawing' => 'Tegning',
                        'Painting' => 'Maleri',
                        'Digital Art' => 'Digital kunst',
                        'Illustration' => 'Illustrasjon',
                        'Photography' => 'Fotografi',
                        'Mixed Media' => 'Blandet media',
                        'Collage' => 'Collage',
                        'Printmaking' => 'Grafikk',
                    ],
                ],
                'sv-SE' => [
                    'group_name' => 'Teknik',
                    'options' => [
                        'Drawing' => 'Teckning',
                        'Painting' => 'Målning',
                        'Digital Art' => 'Digital konst',
                        'Illustration' => 'Illustration',
                        'Photography' => 'Fotografi',
                        'Mixed Media' => 'Blandteknik',
                        'Collage' => 'Collage',
                        'Printmaking' => 'Grafik',
                    ],
                ],
            ],
        ];
    }

    private function getSubjectDefinition(): array
    {
        return [
            'name' => 'Subject',
            'position' => 2,
            'options' => [
                'Abstract',
                'Animals & Wildlife',
                'Architecture',
                'Botanical',
                'Fantasy',
                'Figurative',
                'Landscapes',
                'Portraits',
                'Seascapes',
                'Still Life',
                'Urban',
            ],
            'translations' => [
                'en-GB' => [
                    'group_name' => 'Subject',
                    'options' => [
                        'Abstract' => 'Abstract',
                        'Animals & Wildlife' => 'Animals & Wildlife',
                        'Architecture' => 'Architecture',
                        'Botanical' => 'Botanical',
                        'Fantasy' => 'Fantasy',
                        'Figurative' => 'Figurative',
                        'Landscapes' => 'Landscapes',
                        'Portraits' => 'Portraits',
                        'Seascapes' => 'Seascapes',
                        'Still Life' => 'Still Life',
                        'Urban' => 'Urban',
                    ],
                ],
                'da-DK' => [
                    'group_name' => 'Emne',
                    'options' => [
                        'Abstract' => 'Abstrakt',
                        'Animals & Wildlife' => 'Dyr og dyreliv',
                        'Architecture' => 'Arkitektur',
                        'Botanical' => 'Botanisk',
                        'Fantasy' => 'Fantasy',
                        'Figurative' => 'Figurativt',
                        'Landscapes' => 'Landskaber',
                        'Portraits' => 'Portrætter',
                        'Seascapes' => 'Havlandskaber',
                        'Still Life' => 'Stilleben',
                        'Urban' => 'Urbant',
                    ],
                ],
                'nn-NO' => [
                    'group_name' => 'Emne',
                    'options' => [
                        'Abstract' => 'Abstrakt',
                        'Animals & Wildlife' => 'Dyr og dyreliv',
                        'Architecture' => 'Arkitektur',
                        'Botanical' => 'Botanisk',
                        'Fantasy' => 'Fantasy',
                        'Figurative' => 'Figurativ',
                        'Landscapes' => 'Landskap',
                        'Portraits' => 'Portretter',
                        'Seascapes' => 'Havlandskap',
                        'Still Life' => 'Stilleben',
                        'Urban' => 'Urban',
                    ],
                ],
                'sv-SE' => [
                    'group_name' => 'Ämne',
                    'options' => [
                        'Abstract' => 'Abstrakt',
                        'Animals & Wildlife' => 'Djur och vilda djur',
                        'Architecture' => 'Arkitektur',
                        'Botanical' => 'Botanisk',
                        'Fantasy' => 'Fantasy',
                        'Figurative' => 'Figurativ',
                        'Landscapes' => 'Landskap',
                        'Portraits' => 'Porträtt',
                        'Seascapes' => 'Havslanskap',
                        'Still Life' => 'Stilleben',
                        'Urban' => 'Urban',
                    ],
                ],
            ],
        ];
    }

    private function getStyleDefinition(): array
    {
        return [
            'name' => 'Style',
            'position' => 3,
            'options' => [
                'Realism',
                'Impressionism',
                'Expressionism',
                'Abstract',
                'Minimalism',
                'Modern',
                'Contemporary',
                'Pop Art',
                'Surrealism',
                'Vintage',
                'Cubism',
            ],
            'translations' => [
                'en-GB' => [
                    'group_name' => 'Style',
                    'options' => [
                        'Realism' => 'Realism',
                        'Impressionism' => 'Impressionism',
                        'Expressionism' => 'Expressionism',
                        'Abstract' => 'Abstract',
                        'Minimalism' => 'Minimalism',
                        'Modern' => 'Modern',
                        'Contemporary' => 'Contemporary',
                        'Pop Art' => 'Pop Art',
                        'Surrealism' => 'Surrealism',
                        'Vintage' => 'Vintage',
                        'Cubism' => 'Cubism',
                    ],
                ],
                'da-DK' => [
                    'group_name' => 'Stil',
                    'options' => [
                        'Realism' => 'Realisme',
                        'Impressionism' => 'Impressionisme',
                        'Expressionism' => 'Ekspressionisme',
                        'Abstract' => 'Abstrakt',
                        'Minimalism' => 'Minimalisme',
                        'Modern' => 'Moderne',
                        'Contemporary' => 'Moderne',
                        'Pop Art' => 'Pop art',
                        'Surrealism' => 'Surrealisme',
                        'Vintage' => 'Vintage',
                        'Cubism' => 'Kubisme',
                    ],
                ],
                'nn-NO' => [
                    'group_name' => 'Stil',
                    'options' => [
                        'Realism' => 'Realisme',
                        'Impressionism' => 'Impresjonisme',
                        'Expressionism' => 'Ekspresjonisme',
                        'Abstract' => 'Abstrakt',
                        'Minimalism' => 'Minimalisme',
                        'Modern' => 'Moderne',
                        'Contemporary' => 'Samtidskunst',
                        'Pop Art' => 'Popkunst',
                        'Surrealism' => 'Surrealisme',
                        'Vintage' => 'Vintage',
                        'Cubism' => 'Kubisme',
                    ],
                ],
                'sv-SE' => [
                    'group_name' => 'Stil',
                    'options' => [
                        'Realism' => 'Realism',
                        'Impressionism' => 'Impressionism',
                        'Expressionism' => 'Expressionism',
                        'Abstract' => 'Abstrakt',
                        'Minimalism' => 'Minimalism',
                        'Modern' => 'Modern',
                        'Contemporary' => 'Samtida',
                        'Pop Art' => 'Popkonst',
                        'Surrealism' => 'Surrealism',
                        'Vintage' => 'Vintage',
                        'Cubism' => 'Kubism',
                    ],
                ],
            ],
        ];
    }

    private function getThemeMoodDefinition(): array
    {
        return [
            'name' => 'ThemeMood',
            'position' => 4,
            'options' => [
                'Adventure',
                'Calm',
                'Dark',
                'Dramatic',
                'Energetic',
                'Inspirational',
                'Melancholic',
                'Peaceful',
                'Playful',
                'Romantic',
                'Spiritual',
                'Whimsical',
            ],
            'translations' => [
                'en-GB' => [
                    'group_name' => 'Theme / Mood',
                    'options' => [
                        'Adventure' => 'Adventure',
                        'Calm' => 'Calm',
                        'Dark' => 'Dark',
                        'Dramatic' => 'Dramatic',
                        'Energetic' => 'Energetic',
                        'Inspirational' => 'Inspirational',
                        'Melancholic' => 'Melancholic',
                        'Peaceful' => 'Peaceful',
                        'Playful' => 'Playful',
                        'Romantic' => 'Romantic',
                        'Spiritual' => 'Spiritual',
                        'Whimsical' => 'Whimsical',
                    ],
                ],
                'da-DK' => [
                    'group_name' => 'Tema/stemning',
                    'options' => [
                        'Adventure' => 'Eventyr',
                        'Calm' => 'Rolig',
                        'Dark' => 'Mørk',
                        'Dramatic' => 'Dramatisk',
                        'Energetic' => 'Energisk',
                        'Inspirational' => 'Inspirerende',
                        'Melancholic' => 'Melankolsk',
                        'Peaceful' => 'Fredelig',
                        'Playful' => 'Legende',
                        'Romantic' => 'Romantisk',
                        'Spiritual' => 'Spirituel',
                        'Whimsical' => 'Lunefuld',
                    ],
                ],
                'nn-NO' => [
                    'group_name' => 'Tema/stemning',
                    'options' => [
                        'Adventure' => 'Eventyr',
                        'Calm' => 'Rolig',
                        'Dark' => 'Mørk',
                        'Dramatic' => 'Dramatisk',
                        'Energetic' => 'Energisk',
                        'Inspirational' => 'Inspirerende',
                        'Melancholic' => 'Melankolsk',
                        'Peaceful' => 'Fredelig',
                        'Playful' => 'Leken',
                        'Romantic' => 'Romantisk',
                        'Spiritual' => 'Spirituell',
                        'Whimsical' => 'Lunefull',
                    ],
                ],
                'sv-SE' => [
                    'group_name' => 'Tema/stämning',
                    'options' => [
                        'Adventure' => 'Äventyr',
                        'Calm' => 'Lugn',
                        'Dark' => 'Mörk',
                        'Dramatic' => 'Dramatisk',
                        'Energetic' => 'Energisk',
                        'Inspirational' => 'Inspirerande',
                        'Melancholic' => 'Melankolisk',
                        'Peaceful' => 'Fridfull',
                        'Playful' => 'Lekfull',
                        'Romantic' => 'Romantisk',
                        'Spiritual' => 'Spirituell',
                        'Whimsical' => 'Lunefull',
                    ],
                ],
            ],
        ];
    }

    private function getAestheticDefinition(): array
    {
        return [
            'name' => 'Aesthetic',
            'position' => 5,
            'options' => [
                'Black & White',
                'Monochrome',
                'Pastel',
                'Vibrant',
                'Earthy',
                'Bold & Graphic',
                'Soft & Muted',
                'High Contrast',
            ],
            'translations' => [
                'en-GB' => [
                    'group_name' => 'Aesthetic',
                    'options' => [
                        'Black & White' => 'Black & White',
                        'Monochrome' => 'Monochrome',
                        'Pastel' => 'Pastel',
                        'Vibrant' => 'Vibrant',
                        'Earthy' => 'Earthy',
                        'Bold & Graphic' => 'Bold & Graphic',
                        'Soft & Muted' => 'Soft & Muted',
                        'High Contrast' => 'High Contrast',
                    ],
                ],
                'da-DK' => [
                    'group_name' => 'Æstetik',
                    'options' => [
                        'Black & White' => 'Sort & hvid',
                        'Monochrome' => 'Monokrom',
                        'Pastel' => 'Pastel',
                        'Vibrant' => 'Levende',
                        'Earthy' => 'Jordfarvet',
                        'Bold & Graphic' => 'Dristig & grafisk',
                        'Soft & Muted' => 'Blød & dæmpet',
                        'High Contrast' => 'Høj kontrast',
                    ],
                ],
                'nn-NO' => [
                    'group_name' => 'Estetikk',
                    'options' => [
                        'Black & White' => 'Svart & hvitt',
                        'Monochrome' => 'Monokrom',
                        'Pastel' => 'Pastell',
                        'Vibrant' => 'Livlig',
                        'Earthy' => 'Jordnær',
                        'Bold & Graphic' => 'Dristig & grafisk',
                        'Soft & Muted' => 'Myk & dempet',
                        'High Contrast' => 'Høy kontrast',
                    ],
                ],
                'sv-SE' => [
                    'group_name' => 'Estetik',
                    'options' => [
                        'Black & White' => 'Svart & vitt',
                        'Monochrome' => 'Monokrom',
                        'Pastel' => 'Pastell',
                        'Vibrant' => 'Livfull',
                        'Earthy' => 'Jordnära',
                        'Bold & Graphic' => 'Djärv & grafisk',
                        'Soft & Muted' => 'Mjuk & dämpad',
                        'High Contrast' => 'Hög kontrast',
                    ],
                ],
            ],
        ];
    }

    private function getMotifDefinition(): array
    {
        return [
            'name' => 'Motif',
            'position' => 6,
            'options' => [
                'Geometric',
                'Organic',
                'Floral',
                'Nature',
                'People',
                'Animals',
                'Objects',
                'Patterns',
                'Textures',
            ],
            'translations' => [
                'en-GB' => [
                    'group_name' => 'Motif',
                    'options' => [
                        'Geometric' => 'Geometric',
                        'Organic' => 'Organic',
                        'Floral' => 'Floral',
                        'Nature' => 'Nature',
                        'People' => 'People',
                        'Animals' => 'Animals',
                        'Objects' => 'Objects',
                        'Patterns' => 'Patterns',
                        'Textures' => 'Textures',
                    ],
                ],
                'da-DK' => [
                    'group_name' => 'Motiv',
                    'options' => [
                        'Geometric' => 'Geometrisk',
                        'Organic' => 'Organisk',
                        'Floral' => 'Blomster',
                        'Nature' => 'Natur',
                        'People' => 'Mennesker',
                        'Animals' => 'Dyr',
                        'Objects' => 'Objekter',
                        'Patterns' => 'Mønstre',
                        'Textures' => 'Teksturer',
                    ],
                ],
                'nn-NO' => [
                    'group_name' => 'Motiv',
                    'options' => [
                        'Geometric' => 'Geometrisk',
                        'Organic' => 'Organisk',
                        'Floral' => 'Blomstermotiv',
                        'Nature' => 'Natur',
                        'People' => 'Mennesker',
                        'Animals' => 'Dyr',
                        'Objects' => 'Objekter',
                        'Patterns' => 'Mønstre',
                        'Textures' => 'Teksturer',
                    ],
                ],
                'sv-SE' => [
                    'group_name' => 'Motiv',
                    'options' => [
                        'Geometric' => 'Geometrisk',
                        'Organic' => 'Organisk',
                        'Floral' => 'Blommor',
                        'Nature' => 'Natur',
                        'People' => 'Människor',
                        'Animals' => 'Djur',
                        'Objects' => 'Objekt',
                        'Patterns' => 'Mönster',
                        'Textures' => 'Texturer',
                    ],
                ],
            ],
        ];
    }

    private function getColorDefinition(): array
    {
        return [
            'name' => 'Colors',
            'position' => 7,
            'options' => [
                'Red',
                'Blue',
                'Green',
                'Yellow',
                'Orange',
                'Purple',
                'Pink',
                'Brown',
                'Black',
                'White',
                'Grey',
                'Gold',
                'Silver',
            ],
            'translations' => [
                'en-GB' => [
                    'group_name' => 'Dominant Colours',
                    'options' => [
                        'Red' => 'Red',
                        'Blue' => 'Blue',
                        'Green' => 'Green',
                        'Yellow' => 'Yellow',
                        'Orange' => 'Orange',
                        'Purple' => 'Purple',
                        'Pink' => 'Pink',
                        'Brown' => 'Brown',
                        'Black' => 'Black',
                        'White' => 'White',
                        'Grey' => 'Grey',
                        'Gold' => 'Gold',
                        'Silver' => 'Silver',
                    ],
                ],
                'da-DK' => [
                    'group_name' => 'Dominerende farver',
                    'options' => [
                        'Red' => 'Rød',
                        'Blue' => 'Blå',
                        'Green' => 'Grøn',
                        'Yellow' => 'Gul',
                        'Orange' => 'Orange',
                        'Purple' => 'Lilla',
                        'Pink' => 'Pink',
                        'Brown' => 'Brun',
                        'Black' => 'Sort',
                        'White' => 'Hvid',
                        'Grey' => 'Grå',
                        'Gold' => 'Guld',
                        'Silver' => 'Sølv',
                    ],
                ],
                'nn-NO' => [
                    'group_name' => 'Dominerende farger',
                    'options' => [
                        'Red' => 'Rød',
                        'Blue' => 'Blå',
                        'Green' => 'Grønn',
                        'Yellow' => 'Gul',
                        'Orange' => 'Oransje',
                        'Purple' => 'Lilla',
                        'Pink' => 'Rosa',
                        'Brown' => 'Brun',
                        'Black' => 'Svart',
                        'White' => 'Hvit',
                        'Grey' => 'Grå',
                        'Gold' => 'Gull',
                        'Silver' => 'Sølv',
                    ],
                ],
                'sv-SE' => [
                    'group_name' => 'Dominerande färger',
                    'options' => [
                        'Red' => 'Röd',
                        'Blue' => 'Blå',
                        'Green' => 'Grön',
                        'Yellow' => 'Gul',
                        'Orange' => 'Orange',
                        'Purple' => 'Lila',
                        'Pink' => 'Rosa',
                        'Brown' => 'Brun',
                        'Black' => 'Svart',
                        'White' => 'Vit',
                        'Grey' => 'Grå',
                        'Gold' => 'Guld',
                        'Silver' => 'Silver',
                    ],
                ],
            ],
        ];
    }
}
