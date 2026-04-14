<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Config;

/**
 * Centralized constants for CMaintzImageAi plugin
 * Single source of truth for all plugin-wide constants
 */
class PluginConstants
{
    public const string CONFIG_PREFIX = 'CMaintzImageAi.config.';

    // Language
    public const string PRIMARY_ANALYSIS_LANGUAGE = 'da-DK';

    // Cache Configuration
    public const int PROPERTY_CACHE_TTL_SECONDS = 3600;
    public const string CACHE_KEY_PROPERTY_OPTIONS = 'image_ai_property_options';
    public const string CACHE_KEY_PROPERTY_GROUPS = 'image_ai_property_groups';

    // Request Limits
    public const int MAX_PRODUCTS_PER_ADMIN_REQUEST = 500;
    public const int APPROVAL_BATCH_MAX_SIZE = 100;

    // Frame Configuration
    public const string FRAME_CORNER_IMAGE_TYPE = 'top_left_corner';

    // Unit Conversion
    public const float CM_PER_INCH = 2.54;

    // Custom Fields
    public const string CUSTOM_FIELD_SET_NAME = 'image_ai_property_group_set';
    public const string CUSTOM_FIELD_AI_MANAGED = 'image_ai_managed';

    // Media Folders
    public const string SCENE_PARENT_FOLDER_NAME = 'AI Environment Scenes';
    public const string SCENE_FOLDER_TECHNICAL_NAME = 'image_ai_environment_scenes';

    // User Upload Paths
    public const string GRAPHICAL_ASSISTANCE_UPLOAD_PATH = 'graphical-assistance/uploads';

    // Batch Processing
    public const int MAX_RETRIES = 3;
    public const int INITIAL_RETRY_DELAY_MS = 500;
    public const int SAFETY_LIMIT_PRODUCTS = 1000;
    public const int DEFAULT_SCHEDULE_INTERVAL_HOURS = 4; // Scheduled task interval in hours

    // API Payload Limits
    // Max images per API call - kept conservative to avoid size limits
    // If API returns size errors, Orchestrator retries with fewer images
    public const int MAX_PRODUCTS_PER_API_BATCH = 6;

    // Handler chunk size - should be multiple of MAX_PRODUCTS_PER_API_BATCH for even distribution
    // 30 = 5 API batches of 6 products each
    public const int HANDLER_CHUNK_SIZE = 30;

    // Image Dimensions
    public const int TARGET_IMAGE_WIDTH = 800;
    public const int MAX_THUMBNAIL_DIMENSION = 1200;

    // SEO Metadata
    public const int DEFAULT_META_TITLE_LENGTH = 56;
    public const int DEFAULT_META_DESC_LENGTH = 160;
    public const int DEFAULT_KEYWORD_COUNT = 5;
    public const int DEFAULT_KEYWORDS_MAX_CHARS = 255;

    // Product Description
    public const int DEFAULT_DESCRIPTION_LENGTH = 500;
    public const string DEFAULT_CONTENT_TONE = 'professional';

    // Languages
    public const array DEFAULT_LANGUAGES = ['en-GB', 'da-DK', 'nn-NO', 'sv-SE'];
    public const string DEFAULT_LANGUAGE = 'en-GB';

    // API
    public const string DEFAULT_API_MODEL = 'gemini-2.5-flash';
    public const string DEFAULT_IMAGE_GEN_MODEL = 'gemini-2.5-flash-image';
    public const string DEFAULT_API_VERSION = 'v1beta';
    public const string DEFAULT_API_BASE_URL = 'https://generativelanguage.googleapis.com';

    // Timeouts & durations (seconds)
    // Note: Symfony HttpClient 'timeout' controls BOTH connection AND idle timeout (time between chunks)
    // Gemini with thinking enabled can take 2+ minutes of idle time before streaming starts
    public const int API_TIMEOUT = 120;
    public const int API_TIMEOUT_BATCH = 120; // Idle timeout for batch analysis (thinking can take a while)
    public const int API_TIMEOUT_IMAGE_FETCH = 30;
    public const int API_TIMEOUT_HEAD_REQUEST = 3;
    public const int API_MAX_DURATION_BATCH = 240; // Total max time for batch request
    public const int API_MAX_DURATION_GENERATION = 180;

    // Confidence - Workflow
    public const bool DEFAULT_ENABLE_CONFIDENCE_THRESHOLD = true;
    public const float DEFAULT_LOW_CONFIDENCE_THRESHOLD = 0.8;

    // Confidence - Field Weights (should sum to ~1.0)
    public const float DEFAULT_FIELD_WEIGHT_META_TITLE = 0.25;
    public const float DEFAULT_FIELD_WEIGHT_META_DESCRIPTION = 0.25;
    public const float DEFAULT_FIELD_WEIGHT_PRODUCT_DESCRIPTION = 0.20;
    public const float DEFAULT_FIELD_WEIGHT_SEO_KEYWORDS = 0.15;
    public const float DEFAULT_FIELD_WEIGHT_PROPERTIES = 0.15;

    // Confidence - Content Patterns (Danish - analyzing da-DK content)
    public const array DEFAULT_GENERIC_PATTERNS = [
        '/dette (er et |)smukt/i',
        '/perfekt til enhver/i',
        '/fantastisk tilføjelse/i',
        '/høj.?kvalitet/i',
        '/\[.*\]/i',
        '/lorem ipsum/i',
        '/\{[^}]+\}/i',
        '/<[^>]+>/i',
    ];
    public const array DEFAULT_HEDGING_WORDS = [
        'muligvis',
        'måske',
        'kunne være',
        'ser ud til at',
        'synes at',
        'sandsynligvis',
        'formentlig',
        'tilsyneladende',
    ];

    // Confidence - Property Settings
    public const int DEFAULT_IDEAL_OPTIONS_PER_PROPERTY = 3;
    public const float DEFAULT_EMPTY_PROPERTY_PENALTY = 0.15;
    public const float DEFAULT_EXCESS_OPTION_PENALTY = 0.03;

    // Confidence - Length Settings
    public const float DEFAULT_MIN_LENGTH_RATIO = 0.33;

    // Confidence - Content Penalties
    public const float DEFAULT_SHORT_TITLE_PENALTY = 0.05;
    public const float DEFAULT_LONG_TITLE_PENALTY = 0.02;
    public const float DEFAULT_SHORT_DESCRIPTION_PENALTY = 0.05;
    public const float DEFAULT_FEW_KEYWORDS_PENALTY = 0.03;
    public const float DEFAULT_GENERIC_CONTENT_PENALTY = 0.08;
    public const float DEFAULT_HEDGING_PENALTY_PER_INSTANCE = 0.03;
    public const float DEFAULT_HEDGING_PENALTY_MAX = 0.10;
    public const float DEFAULT_DUPLICATE_CONTENT_PENALTY = 0.10;
    public const float DEFAULT_NO_PROPERTIES_PENALTY = 0.10;

    // Confidence - Quality Adjustments
    public const float DEFAULT_LOW_PROPERTY_MATCH_PENALTY = 0.05;
    public const float DEFAULT_MAX_TOTAL_PENALTY = 0.40;
}
