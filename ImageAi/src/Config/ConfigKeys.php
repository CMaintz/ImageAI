<?php declare(strict_types=1);

namespace Illux\ImageAi\Config;

/**
 * Centralized configuration keys for IlluxImageAi plugin
 * Single source of truth for all system config keys
 */
class ConfigKeys
{
    private const string PREFIX = PluginConstants::CONFIG_PREFIX;

    // API Configuration
    public const string API_KEY = self::PREFIX . 'apiKey';
    public const string API_MODEL = self::PREFIX . 'apiModel';
    public const string API_BASE_URL = self::PREFIX . 'apiBaseUrl';
    public const string API_VERSION = self::PREFIX . 'apiVersion';
    public const string IMAGE_GENERATION_MODEL = self::PREFIX . 'imageGenerationModel';

    // Content Configuration (what to generate, languages, lengths)
    public const string INCLUDE_SEO_ANALYSIS = self::PREFIX . 'includeSeoAnalysis';
    public const string INCLUDE_PRODUCT_DESCRIPTION = self::PREFIX . 'includeProductDescription';
    public const string META_TITLE_MAX_LENGTH = self::PREFIX . 'metaTitleMaxLength';
    public const string META_DESCRIPTION_MAX_LENGTH = self::PREFIX . 'metaDescriptionMaxLength';
    public const string DESCRIPTION_MAX_LENGTH = self::PREFIX . 'descriptionMaxLength';
    public const string KEYWORD_COUNT = self::PREFIX . 'keywordCount';
    public const string KEYWORDS_MAX_CHARACTER_LENGTH = self::PREFIX . 'keywordsMaxCharacterLength';
    public const string CONTENT_TONE = self::PREFIX . 'tone';
    public const string ANALYSIS_LANGUAGES = self::PREFIX . 'analysisLanguages';

    // Workflow Configuration (approval, scheduling, filtering)
    public const string ENABLE_APPROVAL_WORKFLOW = self::PREFIX . 'enableApprovalWorkflow';
    public const string SCHEDULED_TASK_ENABLED = self::PREFIX . 'scheduledTaskEnabled';
    public const string SCHEDULED_TASK_INTERVAL = self::PREFIX . 'scheduledTaskInterval'; // In hours
    public const string ELIGIBLE_PRODUCT_TYPES = self::PREFIX . 'eligibleProductTypes';

    // Confidence Configuration
    public const string ENABLE_CONFIDENCE_THRESHOLD = self::PREFIX . 'enableConfidenceThreshold';
    public const string LOW_CONFIDENCE_THRESHOLD = self::PREFIX . 'lowConfidenceThreshold';

    // Field Weights (0.0-1.0)
    public const string FIELD_WEIGHT_META_TITLE = self::PREFIX . 'fieldWeightMetaTitle';
    public const string FIELD_WEIGHT_META_DESCRIPTION = self::PREFIX . 'fieldWeightMetaDescription';
    public const string FIELD_WEIGHT_PRODUCT_DESCRIPTION = self::PREFIX . 'fieldWeightProductDescription';
    public const string FIELD_WEIGHT_SEO_KEYWORDS = self::PREFIX . 'fieldWeightSeoKeywords';
    public const string FIELD_WEIGHT_PROPERTIES = self::PREFIX . 'fieldWeightProperties';

    // Content Patterns (newline-separated strings)
    public const string GENERIC_PATTERNS = self::PREFIX . 'genericPatterns';
    public const string HEDGING_WORDS = self::PREFIX . 'hedgingWords';

    // Property Settings
    public const string IDEAL_OPTIONS_PER_PROPERTY = self::PREFIX . 'idealOptionsPerProperty';
    public const string EMPTY_PROPERTY_PENALTY = self::PREFIX . 'emptyPropertyPenalty';
    public const string EXCESS_OPTION_PENALTY = self::PREFIX . 'excessOptionPenalty';

    // Length Settings
    public const string MIN_LENGTH_RATIO = self::PREFIX . 'minLengthRatio';

    // Content Penalties
    public const string SHORT_TITLE_PENALTY = self::PREFIX . 'shortTitlePenalty';
    public const string LONG_TITLE_PENALTY = self::PREFIX . 'longTitlePenalty';
    public const string SHORT_DESCRIPTION_PENALTY = self::PREFIX . 'shortDescriptionPenalty';
    public const string FEW_KEYWORDS_PENALTY = self::PREFIX . 'fewKeywordsPenalty';
    public const string GENERIC_CONTENT_PENALTY = self::PREFIX . 'genericContentPenalty';
    public const string HEDGING_PENALTY_PER_INSTANCE = self::PREFIX . 'hedgingPenaltyPerInstance';
    public const string HEDGING_PENALTY_MAX = self::PREFIX . 'hedgingPenaltyMax';
    public const string DUPLICATE_CONTENT_PENALTY = self::PREFIX . 'duplicateContentPenalty';
    public const string NO_PROPERTIES_PENALTY = self::PREFIX . 'noPropertiesPenalty';

    // Quality Adjustments
    public const string LOW_PROPERTY_MATCH_PENALTY = self::PREFIX . 'lowPropertyMatchPenalty';
    public const string MAX_TOTAL_PENALTY = self::PREFIX . 'maxTotalPenalty';

    // Time Tracking Configuration
    public const string MINUTES_SAVED_PER_PROPERTY = self::PREFIX . 'minutesSavedPerProperty';
    public const string MINUTES_SAVED_PER_SEO = self::PREFIX . 'minutesSavedPerSeo';
    public const string MINUTES_SAVED_FOR_BASE_DESCRIPTION = self::PREFIX . 'minutesSavedForBaseDescription';
    public const string MINUTES_SAVED_PER_ADDITIONAL_TRANSLATION =
        self::PREFIX . 'minutesSavedPerAdditionalTranslation';
}
