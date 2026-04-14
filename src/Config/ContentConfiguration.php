<?php declare(strict_types=1);

namespace Illux\ImageAi\Config;

/**
 * Value object for content generation configuration
 *
 * Immutable configuration for analysis output settings.
 * Controls what content is generated, in which languages, and with what constraints.
 *
 * @phpstan-immutable
 */
readonly class ContentConfiguration
{
    /**
     * @param bool $includeSeoAnalysis Whether to generate SEO metadata (title, description, keywords)
     * @param bool $includeProductDescription Whether to generate product descriptions
     * @param int $metaTitleMaxLength Maximum length for meta titles (30-70 chars, recommended: 56)
     * @param int $metaDescriptionMaxLength Maximum length for meta descriptions (50-320 chars, recommended: 160)
     * @param int $descriptionMaxLength Maximum length for product descriptions (100-2000 chars)
     * @param int $keywordCount Number of SEO keywords to generate (3-5)
     * @param int $keywordsMaxCharacterLength Maximum total character count for all keywords combined (default: 255)
     * @param string $contentTone Tone for generated content (e.g., 'professional', 'casual')
     * @param string[] $analysisLanguages Language codes for content generation (e.g., ['en-GB', 'da-DK'])
     */
    public function __construct(
        public bool $includeSeoAnalysis,
        public bool $includeProductDescription,
        public int $metaTitleMaxLength,
        public int $metaDescriptionMaxLength,
        public int $descriptionMaxLength,
        public int $keywordCount,
        public int $keywordsMaxCharacterLength,
        public string $contentTone,
        public array $analysisLanguages
    ) {
    }

    public function isContentGenerationEnabled(): bool
    {
        return $this->includeSeoAnalysis || $this->includeProductDescription;
    }

    /**
     * Create a new instance with overridden values from metadata filters
     * @param array $filters Metadata filters from user request
     * @return self New instance with overridden values
     */
    public function withOverrides(array $filters): self
    {
        $includeSeoAnalysis = $this->includeSeoAnalysis;
        $includeProductDescription = $this->includeProductDescription;

        if (isset($filters['includeDescription'])) {
            $includeProductDescription = (bool) $filters['includeDescription'];
        }
        if (isset($filters['includeSeoAnalysis'])) {
            $includeSeoAnalysis = (bool) $filters['includeSeoAnalysis'];
        }

        return new self(
            includeSeoAnalysis: $includeSeoAnalysis,
            includeProductDescription: $includeProductDescription,
            metaTitleMaxLength: $this->metaTitleMaxLength,
            metaDescriptionMaxLength: $this->metaDescriptionMaxLength,
            descriptionMaxLength: $this->descriptionMaxLength,
            keywordCount: $this->keywordCount,
            keywordsMaxCharacterLength: $this->keywordsMaxCharacterLength,
            contentTone: $this->contentTone,
            analysisLanguages: $this->analysisLanguages
        );
    }
}
