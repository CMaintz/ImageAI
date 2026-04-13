<?php declare(strict_types=1);

namespace Illux\ImageAi\Factory;

use Illux\ImageAi\Config\ContentConfiguration;
use Illux\ImageAi\DTO\Image\ResolvedProductImage;
use Illux\ImageAi\DTO\Request\AnalysisRequest;
use Illux\ImageAi\Service\LanguageConfigurationService;
use Illux\ImageAi\Service\Prompt\PromptDirector;
use InvalidArgumentException;
use Shopware\Core\Framework\Context;

/**
 * Factory for creating AnalysisRequest objects
 *
 * Centralizes the creation logic for AnalysisRequest DTOs with validation.
 * Encapsulates the complex orchestration of prompt, schema, and instruction building.
 * Caches system instruction and schema within the request lifecycle.
 */
class AnalysisRequestFactory
{
    private ?string $cachedSystemInstruction = null;
    private ?array $cachedSchema = null;
    private ?string $cachedSchemaKey = null;

    public function __construct(
        private readonly PromptDirector $promptDirector,
        private readonly AnalysisSchemaFactory $schemaFactory,
        private readonly LanguageConfigurationService $languageConfigService
    ) {
    }

    /**
     * Create an AnalysisRequest for batch processing
     * @param ResolvedProductImage[] $resolvedImages Array of pre-resolved images with base64 data
     * @param ContentConfiguration $contentConfig Content configuration (with any overrides already applied)
     * @param Context $context Shopware context
     * @return AnalysisRequest
     * @throws InvalidArgumentException If resolved images array is empty
     */
    public function createBatchRequest(
        array $resolvedImages,
        ContentConfiguration $contentConfig,
        Context $context
    ): AnalysisRequest {
        $this->validateResolvedImages($resolvedImages);

        $languages = $this->languageConfigService->getAnalysisLanguages();
        $productCount = count($resolvedImages);

        // Cache system instruction (same for all batches)
        $systemInstruction = $this->getSystemInstruction();

        // Prompt varies per batch (different product count)
        // Note: Product IDs are now embedded directly before each image in AnalysisRequest::toApiPayload()
        $prompt = $this->promptDirector->buildBatchPrompt($languages, $productCount, $contentConfig);

        // Cache schema (same for all batches with same config)
        $schema = $this->getSchema($contentConfig, $context);

        return new AnalysisRequest(
            resolvedImages: $resolvedImages,
            systemInstruction: $systemInstruction,
            prompt: $prompt,
            schema: $schema
        );
    }

    private function getSystemInstruction(): string
    {
        if ($this->cachedSystemInstruction === null) {
            $this->cachedSystemInstruction = $this->promptDirector->buildBatchInstruction();
        }
        return $this->cachedSystemInstruction;
    }

    private function getSchema(ContentConfiguration $contentConfig, Context $context): array
    {
        // Cache key based on config that affects schema
        $schemaKey = md5(serialize($contentConfig) . $context->getLanguageId());

        if ($this->cachedSchema === null || $this->cachedSchemaKey !== $schemaKey) {
            $this->cachedSchema = $this->schemaFactory->buildBatchSchema($contentConfig, $context);
            $this->cachedSchemaKey = $schemaKey;
        }
        return $this->cachedSchema;
    }

    /**
     * Validate resolved images array
     * @param ResolvedProductImage[] $resolvedImages Array of ResolvedProductImage instances
     * @throws InvalidArgumentException If validation fails
     */
    private function validateResolvedImages(array $resolvedImages): void
    {
        if (empty($resolvedImages)) {
            throw new InvalidArgumentException('Resolved images array cannot be empty');
        }

        foreach ($resolvedImages as $index => $resolvedImage) {
            if (!$resolvedImage instanceof ResolvedProductImage) {
                throw new InvalidArgumentException(
                    "Item at index {$index} is not a ResolvedProductImage instance"
                );
            }
        }
    }
}
