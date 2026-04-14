<?php declare(strict_types=1);

namespace CMaintz\ImageAi\DTO\Request;

use CMaintz\ImageAi\DTO\Image\ResolvedProductImage;

/**
 * Simple DTO for Gemini API batch analysis requests
 * Represents ONE API request containing MULTIPLE products.
 * All products are analyzed in a single API call.
 *
 * Note: Uses ResolvedProductImage which contains pre-resolved base64 data.
 * Image resolution (URL fetching, file reading) is done by ProductImageResolver
 * before creating this DTO.
 */
class AnalysisRequest
{
    /**
     * @param ResolvedProductImage[] $resolvedImages Array of pre-resolved images with base64 data
     * @param string $systemInstruction System Instruction for LLM
     * @param string $prompt Analysis prompt asking for all languages
     * @param array $schema Response schema structure
     */
    public function __construct(
        private readonly array $resolvedImages,
        private readonly string $systemInstruction,
        private readonly string $prompt,
        private readonly array $schema
    ) {
    }

    /**
     * Convert to Gemini API payload format
     *
     * Creates a single request with all product images as inline_data parts (base64)
     *
     * Each image is preceded by a text label identifying its productId and analysisResultId.
     * This ensures the model can correctly associate each image with its IDs,
     * rather than relying on positional inference from the prompt mapping.
     */
    public function toApiPayload(): array
    {
        $parts = [
            ['text' => $this->prompt],
        ];

        foreach ($this->resolvedImages as $index => $resolvedImage) {
            // Add explicit text label before each image to ensure correct ID association
            $imageNum = $index + 1;
            $parts[] = ['text' => "[IMAGE {$imageNum}] productId=\"{$resolvedImage->productId}\"
            , analysisResultId=\"{$resolvedImage->analysisResultId}\""];
            $parts[] = $resolvedImage->toInlineDataPart();
        }
//TODO Need to try testing analysis with thinking disabled and with varying thinking budgets to compare output quality
        return [
            'system_instruction' => [
                'parts' => [
                    ['text' => $this->systemInstruction]
                ]
            ],
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->schema,
                'mediaResolution' => 'MEDIA_RESOLUTION_HIGH',
                'thinkingConfig' => [
                    'thinkingBudget' => -1,
                ],
            ],
        ];
    }
}
