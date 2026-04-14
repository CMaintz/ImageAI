<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Builder\Schema;

use CMaintz\ImageAi\Config\ContentConfiguration;
use CMaintz\ImageAi\Trait\SchemaTypeTrait;
use RuntimeException;
use Shopware\Core\Content\Property\PropertyGroupEntity;

// phpcs:disable Generic.Files.LineLength.TooLong

/**
 * Builder for constructing analysis JSON schemas using the Builder Pattern.
 *
 * Provides a fluent interface for building Gemini API response schemas step by step.
 * Conditionally includes SEO, description, and property schemas based on configuration.
 */
class AnalysisSchemaBuilder
{
    use SchemaTypeTrait;

    /** @var PropertyGroupEntity[] */
    private array $propertyGroups = [];
    private ?ContentConfiguration $contentConfig = null;

    public function __construct()
    {
    }

    /**
     * Set the AI-managed property groups for the schema
     * @param PropertyGroupEntity[] $propertyGroups
     * @return self For method chaining
     */
    public function setPropertyGroups(array $propertyGroups): self
    {
        $this->propertyGroups = $propertyGroups;
        return $this;
    }

    /**
     * Set content configuration for conditional schema generation
     * @param ContentConfiguration $config Content configuration
     * @return self For method chaining
     */
    public function setContentConfig(ContentConfiguration $config): self
    {
        $this->contentConfig = $config;
        return $this;
    }

    /**
     * Build the complete batch schema for Gemini API
     * @return array Complete JSON schema array
     * @throws RuntimeException If required fields are missing
     */
    public function build(): array
    {
        $this->validate();

        $resultSchema = $this->buildResultSchema();

        return self::object([
            'results' => self::array(
                $resultSchema,
                'Array of analysis results, one per product. Each result MUST include the exact productId and analysisResultId from the [IMAGE N] label preceding that image.'
            )
        ], ['results']);
    }

    private function buildResultSchema(): array
    {
        $config = $this->contentConfig ?? throw new RuntimeException('Content config not set');
        $propertiesSchema = $this->buildPropertiesSchema();

        $resultFields = [
            'productId' => self::string('Product ID from request'),
            'analysisResultId' => self::string('Result ID from request'),
            'properties' => $propertiesSchema,
        ];
        $requiredFields = ['productId', 'analysisResultId', 'properties'];

        if ($config->isContentGenerationEnabled()) {
            $analysisSchema = $this->buildAnalysisSchema();
            $resultFields['analysis-data'] = self::array(
                self::object([
                    'language' => self::string('ISO Language Code (e.g., "en-GB")'),
                    'analysis' => $analysisSchema,
                ], ['language', 'analysis']),
                'List of analysis results per language'
            );
            $requiredFields[] = 'analysis-data';
        }

        return self::object($resultFields, $requiredFields);
    }

    private function buildAnalysisSchema(): array
    {
        $config = $this->contentConfig ?? throw new RuntimeException('Content config not set');
        $analysisFields = [];
        $requiredFields = [];

        if ($config->includeSeoAnalysis) {
            $analysisFields['metaData'] = $this->buildSeoSchema();
            $requiredFields[] = 'metaData';
        }

        if ($config->includeProductDescription) {
            $analysisFields['descriptionData'] = $this->buildDescriptionSchema();
            $requiredFields[] = 'descriptionData';
        }

        return self::object($analysisFields, $requiredFields);
    }

    private function buildSeoSchema(): array
    {
        $config = $this->contentConfig ?? throw new RuntimeException('Content config not set');
        $metaTitleLength = $config->metaTitleMaxLength;
        $metaDescLength = $config->metaDescriptionMaxLength;
        $keywordCount = $config->keywordCount;

        return self::object([
            'metaTitleData' => self::object([
                'metaTitle' => self::string("SEO title (max $metaTitleLength chars)"),
                'confidence' => self::number('Confidence score 0-1'),
            ], ['metaTitle', 'confidence']),

            'metaDescriptionData' => self::object([
                'metaDescription' => self::string("SEO description (max $metaDescLength chars)"),
                'confidence' => self::number('Confidence score 0-1'),
            ], ['metaDescription', 'confidence']),

            'seoKeywordsData' => self::object([
                'seoKeywords' => self::array(
                    self::string('Keyword'),
                    "Flat array of $keywordCount SEO keywords"
                ),
                'confidence' => self::number('Confidence score 0-1'),
            ], ['seoKeywords', 'confidence']),
        ], ['metaTitleData', 'metaDescriptionData', 'seoKeywordsData']);
    }

    private function buildDescriptionSchema(): array
    {
        $config = $this->contentConfig ?? throw new RuntimeException('Content config not set');
        $descLength = $config->descriptionMaxLength;

        return self::object([
            'description' => self::string("Product description (max $descLength chars)"),
            'confidence' => self::number('Confidence score 0-1'),
        ], ['description', 'confidence']);
    }

    /**
     * Build the properties schema from AI-managed property groups
     * @throws RuntimeException If no valid property groups are available
     */
    private function buildPropertiesSchema(): array
    {
        if (empty($this->propertyGroups)) {
            throw new RuntimeException(
                'No AI-managed property groups found. Please ensure property groups are installed ' .
                'and have the "image_ai_managed" custom field set to true.'
            );
        }

        $schemaProperties = [];
        $skippedGroups = [];

        foreach ($this->propertyGroups as $group) {
            $rawName = $group->getName() ?? '';
            $schemaKey = str_replace(' ', '_', $rawName);

            $options = [];
            if ($group->getOptions()) {
                foreach ($group->getOptions() as $option) {
                    $options[] = $option->getName();
                }
            }

            if (empty($options)) {
                $skippedGroups[] = $rawName;
                continue;
            }

            $schemaProperties[$schemaKey] = self::object([
                'options' => self::array(
                    [
                        'type' => 'string',
                        'enum' => $options,
                    ],
                    "Selected {$rawName} options from existing list (1-3 recommended)"
                ),
                'suggestedOptions' => self::array(
                    self::string('Suggested option name'),
                    "New {$rawName} options to suggest if no existing option matches well"
                ),
                'confidence' => self::number('Confidence score 0-1 for this property classification'),
            ], ['options', 'suggestedOptions', 'confidence']);
        }

        if (empty($schemaProperties)) {
            throw new RuntimeException(
                'All AI-managed property groups have no options. Please add options to your property groups. ' .
                'Skipped groups: ' . implode(', ', $skippedGroups)
            );
        }

        return self::object($schemaProperties);
    }

    /**
     * Validate that all required fields are set
     * @throws RuntimeException If validation fails
     * @phpstan-assert !null $this->contentConfig
     */
    private function validate(): void
    {
        if ($this->contentConfig === null) {
            throw new RuntimeException('Content configuration must be set before building schema');
        }
    }

    /**
     * Reset the builder to initial state
     * @return self For method chaining
     */
    public function reset(): self
    {
        $this->propertyGroups = [];
        $this->contentConfig = null;
        return $this;
    }
}
