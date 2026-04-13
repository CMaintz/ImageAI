<?php declare(strict_types=1);

namespace Illux\ImageAi\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Centralized configuration service for IlluxImageAi plugin
 * Single source of truth for all configuration access
 *
 * Provides typed configuration value objects and caches them for performance
 */
class IlluxConfiguration
{
    private ?ApiConfiguration $apiConfig = null;
    private ?ContentConfiguration $contentConfig = null;
    private ?WorkflowConfiguration $workflowConfig = null;
    private ?ConfidenceConfiguration $confidenceConfig = null;
    private ?TimeTrackingConfiguration $timeTrackingConfig = null;

    public function __construct(
        private readonly SystemConfigService $sysConfig,
    ) {
    }

    public function getApiConfig(): ApiConfiguration
    {
        if ($this->apiConfig === null) {
            $apiKey = $this->sysConfig->getString(ConfigKeys::API_KEY);
            $apiModel = $this->sysConfig->getString(ConfigKeys::API_MODEL);
            $apiBaseUrl = $this->sysConfig->getString(ConfigKeys::API_BASE_URL);
            $apiVersion = $this->sysConfig->getString(ConfigKeys::API_VERSION);
            $imageGenModel = $this->sysConfig->getString(ConfigKeys::IMAGE_GENERATION_MODEL);

            $this->apiConfig = new ApiConfiguration(
                apiKey: $apiKey,
                apiModel: $apiModel ?: PluginConstants::DEFAULT_API_MODEL,
                apiBaseUrl: $apiBaseUrl ?: PluginConstants::DEFAULT_API_BASE_URL,
                apiVersion: $apiVersion ?: PluginConstants::DEFAULT_API_VERSION,
                imageGenerationModel: $imageGenModel ?: PluginConstants::DEFAULT_IMAGE_GEN_MODEL
            );
        }

        return $this->apiConfig;
    }

    public function getContentConfig(): ContentConfiguration
    {
        if ($this->contentConfig === null) {
            $languages = $this->sysConfig->get(ConfigKeys::ANALYSIS_LANGUAGES);
            if (empty($languages) || !is_array($languages)) {
                $languages = PluginConstants::DEFAULT_LANGUAGES;
            }

            $includeSeo = $this->sysConfig->getBool(ConfigKeys::INCLUDE_SEO_ANALYSIS);
            $includeDesc = $this->sysConfig->getBool(ConfigKeys::INCLUDE_PRODUCT_DESCRIPTION);
            $metaTitleLen = $this->sysConfig->getInt(ConfigKeys::META_TITLE_MAX_LENGTH);
            $metaDescLen = $this->sysConfig->getInt(ConfigKeys::META_DESCRIPTION_MAX_LENGTH);
            $descLen = $this->sysConfig->getInt(ConfigKeys::DESCRIPTION_MAX_LENGTH);
            $keywordCnt = $this->sysConfig->getInt(ConfigKeys::KEYWORD_COUNT);
            $keywordsMaxChars = $this->sysConfig->getInt(ConfigKeys::KEYWORDS_MAX_CHARACTER_LENGTH);
            $tone = $this->sysConfig->getString(ConfigKeys::CONTENT_TONE);

            $this->contentConfig = new ContentConfiguration(
                includeSeoAnalysis: $includeSeo ?: true,
                includeProductDescription: $includeDesc ?: true,
                metaTitleMaxLength: $metaTitleLen ?: PluginConstants::DEFAULT_META_TITLE_LENGTH,
                metaDescriptionMaxLength: $metaDescLen ?: PluginConstants::DEFAULT_META_DESC_LENGTH,
                descriptionMaxLength: $descLen ?: PluginConstants::DEFAULT_DESCRIPTION_LENGTH,
                keywordCount: $keywordCnt ?: PluginConstants::DEFAULT_KEYWORD_COUNT,
                keywordsMaxCharacterLength: $keywordsMaxChars ?: PluginConstants::DEFAULT_KEYWORDS_MAX_CHARS,
                contentTone: $tone ?: PluginConstants::DEFAULT_CONTENT_TONE,
                analysisLanguages: $languages,
            );
        }

        return $this->contentConfig;
    }

    public function getWorkflowConfig(): WorkflowConfiguration
    {
        if ($this->workflowConfig === null) {
            $eligibleTypesString = $this->sysConfig->getString(ConfigKeys::ELIGIBLE_PRODUCT_TYPES);
            $eligibleProductTypes = !empty($eligibleTypesString)
                ? array_map('trim', explode(',', $eligibleTypesString))
                : ['Illux Artwork'];

            $enableApproval = $this->sysConfig->getBool(ConfigKeys::ENABLE_APPROVAL_WORKFLOW);
            $enableScheduled = $this->sysConfig->getBool(ConfigKeys::SCHEDULED_TASK_ENABLED);
            $scheduleIntHours = $this->sysConfig->getInt(ConfigKeys::SCHEDULED_TASK_INTERVAL);

            $this->workflowConfig = new WorkflowConfiguration(
                enableApprovalWorkflow: $enableApproval,
                enableScheduledAnalysis: $enableScheduled,
                scheduleIntervalHours: $scheduleIntHours ?: PluginConstants::DEFAULT_SCHEDULE_INTERVAL_HOURS,
                eligibleProductTypes: $eligibleProductTypes,
            );
        }

        return $this->workflowConfig;
    }

    public function getConfidenceConfig(): ConfidenceConfiguration
    {
        if ($this->confidenceConfig === null) {
            // Parse newline-separated patterns from config
            $genericPatternsRaw = $this->sysConfig->getString(ConfigKeys::GENERIC_PATTERNS);
            $genericPatterns = !empty($genericPatternsRaw)
                ? array_filter(array_map('trim', explode("\n", $genericPatternsRaw)))
                : PluginConstants::DEFAULT_GENERIC_PATTERNS;

            $hedgingWordsRaw = $this->sysConfig->getString(ConfigKeys::HEDGING_WORDS);
            $hedgingWords = !empty($hedgingWordsRaw)
                ? array_filter(array_map('trim', explode("\n", $hedgingWordsRaw)))
                : PluginConstants::DEFAULT_HEDGING_WORDS;

            $this->confidenceConfig = new ConfidenceConfiguration(
                enableConfidenceThreshold: $this->sysConfig->getBool(ConfigKeys::ENABLE_CONFIDENCE_THRESHOLD)
                    ?: PluginConstants::DEFAULT_ENABLE_CONFIDENCE_THRESHOLD,
                lowConfidenceThreshold: $this->sysConfig->getFloat(ConfigKeys::LOW_CONFIDENCE_THRESHOLD)
                    ?: PluginConstants::DEFAULT_LOW_CONFIDENCE_THRESHOLD,
                fieldWeightMetaTitle: $this->sysConfig->getFloat(ConfigKeys::FIELD_WEIGHT_META_TITLE)
                    ?: PluginConstants::DEFAULT_FIELD_WEIGHT_META_TITLE,
                fieldWeightMetaDescription: $this->sysConfig->getFloat(ConfigKeys::FIELD_WEIGHT_META_DESCRIPTION)
                    ?: PluginConstants::DEFAULT_FIELD_WEIGHT_META_DESCRIPTION,
                fieldWeightProductDescription: $this->sysConfig->getFloat(ConfigKeys::FIELD_WEIGHT_PRODUCT_DESCRIPTION)
                    ?: PluginConstants::DEFAULT_FIELD_WEIGHT_PRODUCT_DESCRIPTION,
                fieldWeightSeoKeywords: $this->sysConfig->getFloat(ConfigKeys::FIELD_WEIGHT_SEO_KEYWORDS)
                    ?: PluginConstants::DEFAULT_FIELD_WEIGHT_SEO_KEYWORDS,
                fieldWeightProperties: $this->sysConfig->getFloat(ConfigKeys::FIELD_WEIGHT_PROPERTIES)
                    ?: PluginConstants::DEFAULT_FIELD_WEIGHT_PROPERTIES,
                genericPatterns: $genericPatterns,
                hedgingWords: $hedgingWords,
                idealOptionsPerProperty: $this->sysConfig->getInt(ConfigKeys::IDEAL_OPTIONS_PER_PROPERTY)
                    ?: PluginConstants::DEFAULT_IDEAL_OPTIONS_PER_PROPERTY,
                emptyPropertyPenalty: $this->sysConfig->getFloat(ConfigKeys::EMPTY_PROPERTY_PENALTY)
                    ?: PluginConstants::DEFAULT_EMPTY_PROPERTY_PENALTY,
                excessOptionPenalty: $this->sysConfig->getFloat(ConfigKeys::EXCESS_OPTION_PENALTY)
                    ?: PluginConstants::DEFAULT_EXCESS_OPTION_PENALTY,
                minLengthRatio: $this->sysConfig->getFloat(ConfigKeys::MIN_LENGTH_RATIO)
                    ?: PluginConstants::DEFAULT_MIN_LENGTH_RATIO,
                shortTitlePenalty: $this->sysConfig->getFloat(ConfigKeys::SHORT_TITLE_PENALTY)
                    ?: PluginConstants::DEFAULT_SHORT_TITLE_PENALTY,
                longTitlePenalty: $this->sysConfig->getFloat(ConfigKeys::LONG_TITLE_PENALTY)
                    ?: PluginConstants::DEFAULT_LONG_TITLE_PENALTY,
                shortDescriptionPenalty: $this->sysConfig->getFloat(ConfigKeys::SHORT_DESCRIPTION_PENALTY)
                    ?: PluginConstants::DEFAULT_SHORT_DESCRIPTION_PENALTY,
                fewKeywordsPenalty: $this->sysConfig->getFloat(ConfigKeys::FEW_KEYWORDS_PENALTY)
                    ?: PluginConstants::DEFAULT_FEW_KEYWORDS_PENALTY,
                genericContentPenalty: $this->sysConfig->getFloat(ConfigKeys::GENERIC_CONTENT_PENALTY)
                    ?: PluginConstants::DEFAULT_GENERIC_CONTENT_PENALTY,
                hedgingPenaltyPerInstance: $this->sysConfig->getFloat(ConfigKeys::HEDGING_PENALTY_PER_INSTANCE)
                    ?: PluginConstants::DEFAULT_HEDGING_PENALTY_PER_INSTANCE,
                hedgingPenaltyMax: $this->sysConfig->getFloat(ConfigKeys::HEDGING_PENALTY_MAX)
                    ?: PluginConstants::DEFAULT_HEDGING_PENALTY_MAX,
                duplicateContentPenalty: $this->sysConfig->getFloat(ConfigKeys::DUPLICATE_CONTENT_PENALTY)
                    ?: PluginConstants::DEFAULT_DUPLICATE_CONTENT_PENALTY,
                noPropertiesPenalty: $this->sysConfig->getFloat(ConfigKeys::NO_PROPERTIES_PENALTY)
                    ?: PluginConstants::DEFAULT_NO_PROPERTIES_PENALTY,
                lowPropertyMatchPenalty: $this->sysConfig->getFloat(ConfigKeys::LOW_PROPERTY_MATCH_PENALTY)
                    ?: PluginConstants::DEFAULT_LOW_PROPERTY_MATCH_PENALTY,
                maxTotalPenalty: $this->sysConfig->getFloat(ConfigKeys::MAX_TOTAL_PENALTY)
                    ?: PluginConstants::DEFAULT_MAX_TOTAL_PENALTY,
            );
        }

        return $this->confidenceConfig;
    }

    public function getTimeTrackingConfig(): TimeTrackingConfiguration
    {
        if ($this->timeTrackingConfig === null) {
            $perProperty = $this->sysConfig->getInt(ConfigKeys::MINUTES_SAVED_PER_PROPERTY);
            $perSeo = $this->sysConfig->getInt(ConfigKeys::MINUTES_SAVED_PER_SEO);
            $forBaseDesc = $this->sysConfig->getInt(ConfigKeys::MINUTES_SAVED_FOR_BASE_DESCRIPTION);
            $perTranslation = $this->sysConfig->getInt(ConfigKeys::MINUTES_SAVED_PER_ADDITIONAL_TRANSLATION);

            $this->timeTrackingConfig = new TimeTrackingConfiguration(
                minutesSavedPerProperty: $perProperty ?: 2,
                minutesSavedPerSeo: $perSeo ?: 3,
                minutesSavedForBaseDescription: $forBaseDesc ?: 5,
                minutesSavedPerAdditionalTranslation: $perTranslation ?: 2
            );
        }

        return $this->timeTrackingConfig;
    }

    public function clearCache(): void
    {
        $this->apiConfig = null;
        $this->contentConfig = null;
        $this->workflowConfig = null;
        $this->confidenceConfig = null;
        $this->timeTrackingConfig = null;
    }

    public function validate(): array
    {
        $errors = [];

        $apiConfig = $this->getApiConfig();
        if (empty($apiConfig->apiKey)) {
            $errors[] = 'API key is required but not configured';
        }
        if (empty($apiConfig->apiModel)) {
            $errors[] = 'API model is required but not configured';
        }
        if (empty($apiConfig->apiBaseUrl)) {
            $errors[] = 'API base URL is required but not configured';
        }

        $workflowConfig = $this->getWorkflowConfig();
        if ($workflowConfig->scheduleIntervalHours < 1 || $workflowConfig->scheduleIntervalHours > 24) {
            $errors[] = 'Schedule interval must be between 1 and 24 hours';
        }

        $contentConfig = $this->getContentConfig();
        if (empty($contentConfig->analysisLanguages)) {
            $errors[] = 'At least one analysis language must be configured';
        }
        if ($contentConfig->metaTitleMaxLength < 10 || $contentConfig->metaTitleMaxLength > 255) {
            $errors[] = 'Meta title max length must be between 10 and 255';
        }
        if ($contentConfig->metaDescriptionMaxLength < 100 || $contentConfig->metaDescriptionMaxLength > 500) {
            $errors[] = 'Meta description max length must be between 100 and 500';
        }
        if ($contentConfig->descriptionMaxLength < 50 || $contentConfig->descriptionMaxLength > 5000) {
            $errors[] = 'Description max length must be between 50 and 5000';
        }
        if ($contentConfig->keywordCount < 1 || $contentConfig->keywordCount > 20) {
            $errors[] = 'Keyword count must be between 1 and 20';
        }

        $confidenceConfig = $this->getConfidenceConfig();
        if ($confidenceConfig->lowConfidenceThreshold < 0.0 || $confidenceConfig->lowConfidenceThreshold > 1.0) {
            $errors[] = 'Low confidence threshold must be between 0.0 and 1.0';
        }
        if ($confidenceConfig->maxTotalPenalty < 0.0 || $confidenceConfig->maxTotalPenalty > 1.0) {
            $errors[] = 'Max total penalty must be between 0.0 and 1.0';
        }

        return $errors;
    }

    public function isValid(): bool
    {
        return empty($this->validate());
    }
}
