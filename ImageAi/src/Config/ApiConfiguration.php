<?php declare(strict_types=1);

namespace Illux\ImageAi\Config;

/**
 * Value object for API configuration
 * Immutable config for Google Gemini API settings
 */
readonly class ApiConfiguration
{
    public function __construct(
        public string $apiKey,
        public string $apiModel,
        public string $apiBaseUrl,
        public string $apiVersion,
        public string $imageGenerationModel
    ) {
    }

    /**
     * Check if API is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->apiModel);
    }

    /**
     * Get full URL for text/analysis API calls (uses apiModel)
     */
    public function getAnalysisUrl(): string
    {
        return $this->buildModelUrl($this->apiModel);
    }

    /**
     * Get full URL for image generation API calls (uses imageGenerationModel)
     */
    public function getImageGenerationUrl(): string
    {
        return $this->buildModelUrl($this->imageGenerationModel);
    }

    private function buildModelUrl(string $model): string
    {
        $baseUrl = rtrim($this->apiBaseUrl, '/');
        $version = ltrim($this->apiVersion, '/');

        return "{$baseUrl}/{$version}/models/{$model}:generateContent";
    }
}
