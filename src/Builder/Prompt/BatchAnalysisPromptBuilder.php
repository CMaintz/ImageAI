<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Builder\Prompt;

use CMaintz\ImageAi\Config\ContentConfiguration;
use RuntimeException;

// phpcs:disable Generic.Files.LineLength.TooLong

/**
 * Builder for constructing batch analysis prompts using the Builder Pattern
 *
 * Provides a fluent interface for building complex AI prompts step by step.
 * Separates prompt construction from representation and allows flexible composition.
 */
class BatchAnalysisPromptBuilder
{
    /** @var array<string> */
    private array $languages = [];

    /** @var array<string, string> */
    private array $languageNames = [];

    private int $productCount = 0;

    private ?ContentConfiguration $contentConfig = null;

    /** @var array<string> */
    private array $taskLines = [];

    private string $exampleStructure = '';

    /**
     * Set the languages for content generation
     * @param array $languages Array of language codes (e.g., ['en-GB', 'da-DK'])
     * @return self For method chaining
     */
    public function setLanguages(array $languages): self
    {
        $this->languages = $languages;
        return $this;
    }

    /**
     * Set human-readable language names for display
     * @param array $languageNames Map of code => name
     * @return self For method chaining
     */
    public function setLanguageNames(array $languageNames): self
    {
        $this->languageNames = $languageNames;
        return $this;
    }

    /**
     * Set the number of products being analyzed
     * @param int $count Number of products
     * @return self For method chaining
     */
    public function setProductCount(int $count): self
    {
        $this->productCount = $count;
        return $this;
    }

    /**
     * Set content configuration for dynamic task generation
     * @param ContentConfiguration $config Content configuration
     * @return self For method chaining
     */
    public function setContentConfig(ContentConfiguration $config): self
    {
        $this->contentConfig = $config;
        return $this;
    }

    /**
     * Build the complete prompt
     * @return string Complete prompt text
     * @throws RuntimeException If required fields are missing
     */
    public function build(): string
    {
        $this->validate();
        $this->prepareSections();

        $config = $this->contentConfig ?? throw new RuntimeException('Content config not set');
        $hasSeo = $config->includeSeoAnalysis;
        $hasDescription = $config->includeProductDescription;

        $languageCodeList = implode(', ', $this->languages);
        $languageNameList = implode(', ', $this->languageNames);
        $taskList = implode("\n", $this->taskLines);

        $sections = [];

        // Introduction - varies based on what content we're generating
        if ($hasSeo || $hasDescription) {
            $sections[] = <<<INTRO
You are an expert SEO copywriter and art curator for e-commerce.

You will receive {$this->productCount} artwork image(s). For EACH image, you must perform the
following tasks and generate content for ALL of the following languages:
**Languages:** {$languageNameList}

**Tasks for EACH language:**
{$taskList}
INTRO;
        } else {
            $sections[] = <<<INTRO
You are an expert art curator for e-commerce.

You will receive {$this->productCount} artwork image(s). For EACH image, classify
the artwork properties in ENGLISH.

**Tasks:**
{$taskList}
INTRO;
        }

        // Content rules - only if generating multilingual content (SEO, prod desc)
        if ($hasSeo || $hasDescription) {
            $contentRules = [];
            if ($hasSeo) {
                $contentRules[] = "- SEO content (titles, descriptions, keywords) MUST be translated into EACH requested language ({$languageCodeList}).";
                $contentRules[] = "- Keywords must be a simple flat array of strings (e.g., [\"keyword1\", \"keyword2\"]).";
            }
            if ($hasDescription) {
                $contentRules[] = "- Product descriptions MUST be translated into EACH requested language ({$languageCodeList}).";
            }
            $contentRules[] = "- **Properties** must be returned in ENGLISH ONLY and be identical for all language entries of the same product.";

            $sections[] = "**IMPORTANT - CONTENT RULES:**\n" . implode("\n", $contentRules);
        }

        // Confidence scoring guidelines - always included
        $sections[] = <<<CONFIDENCE
**CONFIDENCE SCORING GUIDELINES:**
- Confidence scores must be numbers between 0 and 1, reflecting your certainty about each piece of content.
- Use the FULL range of confidence values with nuance:
  - **0.90-1.00**: High confidence - you are very certain this is accurate and appropriate
  - **0.80-0.89**: Good confidence - likely correct with minor uncertainty
  - **0.70-0.79**: Moderate confidence - reasonable guess but notable uncertainty
  - **0.60-0.69**: Low confidence - significant uncertainty, may need review
  - **Below 0.60**: Very low confidence - educated guess at best
- Be honest about uncertainty. If you're unsure about a classification or description, reflect that in your confidence score.
- Different fields can have different confidence levels (e.g., you might be 0.95 confident about the medium but only 0.70 confident about the artistic style).
CONFIDENCE;

        // SEO quality guidelines - only if generating SEO content
        if ($hasSeo) {
            $metaTitleMax = $config->metaTitleMaxLength;
            $metaDescMax = $config->metaDescriptionMaxLength;
            $keywordCount = $config->keywordCount;
            $keywordsMaxChars = $config->keywordsMaxCharacterLength;

            $sections[] = <<<SEO_QUALITY
**SEO CONTENT GUIDELINES:**
- **Meta Title** (max {$metaTitleMax} characters): Write compelling titles that capture attention. Place the primary keyword or main subject at the BEGINNING of the title for optimal SEO.
- **Meta Description** (max {$metaDescMax} characters): Summarize the artwork and entice clicks. Be descriptive and engaging.
- **Keywords**: Generate exactly {$keywordCount} relevant, specific keywords that customers might search for. The total character count of ALL keywords combined must not exceed {$keywordsMaxChars} characters.
- Strictly adhere to all character length limits specified above.
SEO_QUALITY;
        }

        // Description quality guidelines - only if generating descriptions
        if ($hasDescription) {
            $descMax = $config->descriptionMaxLength;

            $sections[] = <<<DESC_QUALITY
**DESCRIPTION QUALITY GUIDELINES:**
- Write compelling, descriptive content that helps customers understand and appreciate the artwork (max {$descMax} characters)
- Use clear, vivid language appropriate for e-commerce
- When mentioning geographic locations (rivers, landmarks, cities, regions, mountains, etc.), include the country name for clarity (e.g., "Seine, France" instead of just "Seine", "Mont Blanc, France" instead of just "Mont Blanc")
- Include relevant details about the artwork's style, subject matter, mood, and colors
- Use the configured content tone (professional, casual, or enthusiastic)
- Strictly adhere to the character length limit specified above
DESC_QUALITY;
        }

        // Property classification - always included
        $sections[] = <<<PROPERTIES
**PROPERTY CLASSIFICATION:**
- For each property group (medium, subject, style, theme, aesthetic), you are provided with a list of existing options in the schema.
- Select 1-3 options from the provided list that best match the artwork.
- **Suggesting new options:** ONLY suggest new options when NO existing option is semantically close to what you observe. Be conservative with suggestions, ensuring they have relevance for product-filtering.
  - DO NOT suggest options that are semantically similar to existing ones. For example: Do NOT suggest "Forest" if "Nature" exists, do NOT suggest "Seaside" if "Coastal" exists, do NOT suggest "Handmade" if "Crafted" exists.
  - ONLY suggest when the concept is truly distinct and missing from the options. For example: Suggesting "Gouache"  when only "Watercolor" and "Oil" are available IS appropriate because gouache is a distinct medium.
  - New suggestions should be clear, concise, and in English.
  - You can use BOTH `options` (existing matches) AND `suggestedOptions` (new proposals) for the same property if appropriate.
  - Your confidence score for properties should reflect how certain you are about the classification, regardless of whether you used existing or suggested options.
PROPERTIES;

        // Image labeling - always included
        $sections[] = <<<LABELING
**IMPORTANT - IMAGE LABELING:**
- Each image is preceded by a text label in the format: `[IMAGE N] productId="xxx", analysisResultId="yyy"`
- You MUST use the exact `productId` and `analysisResultId` values from the label immediately preceding each image when creating that product's result object.
- Do NOT rely on position or ordering - always copy the IDs directly from the label.
LABELING;

        // Response structure - varies based on content generation
        if ($hasSeo || $hasDescription) {
            $sections[] = <<<RESPONSE
**IMPORTANT - RESPONSE STRUCTURE:**
- You MUST follow the JSON schema provided.
- The root must be an object: {"results": [...]}
- The "results" array must contain {$this->productCount} objects, one for each product.
- Each result object must have: `productId`, `analysisResultId`, `properties`, and `analysis-data`.
- The `productId` and `analysisResultId` in each result MUST exactly match the values
  from the `[IMAGE N]` label that preceded that image.
- The `analysis-data` field MUST be an ARRAY containing one object for each language.
- Each language object MUST have a "language" key and an "analysis" key.

{$this->exampleStructure}
RESPONSE;
        } else {
            $sections[] = <<<RESPONSE
**IMPORTANT - RESPONSE STRUCTURE:**
- You MUST follow the JSON schema provided.
- The root must be an object: {"results": [...]}
- The "results" array must contain {$this->productCount} objects, one for each product.
- Each result object must have: `productId`, `analysisResultId`, and `properties`.
- The `productId` and `analysisResultId` in each result MUST exactly match the values
  from the `[IMAGE N]` label that preceded that image.
RESPONSE;
        }

        return implode("\n\n", $sections);
    }

    /**
     * Validate that all required fields are set
     * @throws RuntimeException If validation fails
     * @phpstan-assert !null $this->contentConfig
     */
    private function validate(): void
    {
        if (empty($this->languages)) {
            throw new RuntimeException('Languages must be set before building prompt');
        }

        if ($this->productCount <= 0) {
            throw new RuntimeException('Product count must be greater than 0');
        }

        if ($this->contentConfig === null) {
            throw new RuntimeException('Content configuration must be set before building prompt');
        }
    }

    /**
     * Prepare all prompt sections
     */
    private function prepareSections(): void
    {
        $this->prepareTaskLines();
        $this->prepareExampleStructure();
    }

    /**
     * Prepare dynamic task lines based on SEO configuration
     */
    private function prepareTaskLines(): void
    {
        $config = $this->contentConfig ?? throw new RuntimeException('Content config not set');
        $this->taskLines = [];

        if ($config->includeSeoAnalysis) {
            $this->taskLines[] = "- **SEO Meta Title** (max {$config->metaTitleMaxLength} chars, primary keyword at front)";
            $this->taskLines[] = "- **SEO Meta Description** (max {$config->metaDescriptionMaxLength} chars)";
            $this->taskLines[] = "- **SEO Keywords** ({$config->keywordCount} keywords, max {$config->keywordsMaxCharacterLength} chars total)";
        }

        if ($config->includeProductDescription) {
            $this->taskLines[] = "- **Store Description** (max {$config->descriptionMaxLength} chars)";
        }

        if (!empty($this->taskLines)) {
            $this->taskLines[] = "- **Confidence Scores** (a number from 0-1) for each generated piece of content.";
        }

        $this->taskLines[] = "- **Properties** (classify the artwork using the provided options)";
    }

    /**
     * Prepare example structure section
     */
    private function prepareExampleStructure(): void
    {
        // TODO "metaData" and "descriptionData" should only be present if they were requested and
        //  are in the schema; so we need conditionals here for building the example structure.
        $this->exampleStructure = <<<EXAMPLE
Example structure for ONE result object in the "results" array:
{
  "productId": "abc123",
  "analysisResultId": "xyz789",
  "properties": { ... },
  "analysis-data": [
    {
      "language": "en-GB",
      "analysis": {
        // "metaData" and "descriptionData" will only be present
        // if they were requested and are in the schema
        "metaData": {
          "metaTitleData": {"metaTitle": "...", "confidence": 0.95},
          "metaDescriptionData": {"metaDescription": "...", "confidence": 0.9},
          "seoKeywordsData": {"seoKeywords": ["art", "modern"], "confidence": 0.85}
        },
        "descriptionData": {"description": "...", "confidence": 0.9}
      }
    },
    {
      "language": "da-DK",
      "analysis": { ...same structure, but in Danish... }
    }
  ]
}
EXAMPLE;
    }

    /**
     * Reset the builder to initial state
     *
     * @return self For method chaining
     */
    public function reset(): self
    {
        $this->languages = [];
        $this->languageNames = [];
        $this->productCount = 0;
        $this->contentConfig = null;
        $this->taskLines = [];
        $this->exampleStructure = '';
        return $this;
    }
}
