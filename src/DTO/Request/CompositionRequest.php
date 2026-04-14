<?php declare(strict_types=1);

namespace CMaintz\ImageAi\DTO\Request;

use CMaintz\ImageAi\DTO\FrameData;
use InvalidArgumentException;

/**
 * DTO for Gemini API image composition requests
 *
 * Represents a request to composite a product image into an environment scene.
 * Optionally includes frame reference data to guide frame generation.
 * Prompt construction should be done by the calling service before
 * creating this request - this DTO only handles API payload conversion.
 */
class CompositionRequest
{
    /**
     * @param string $prompt Complete composition prompt
     * @param string $productImageBase64 Product image as base64 encoded string
     * @param string $productMimeType MIME type of the product image
     * @param string $environmentImageBase64 Environment image as base64 encoded string
     * @param string $environmentMimeType MIME type of the environment image
     * @param FrameData|null $frameData Optional frame data including images and dimensions
     */
    public function __construct(
        private readonly string $prompt,
        private readonly string $productImageBase64,
        private readonly string $productMimeType,
        private readonly string $environmentImageBase64,
        private readonly string $environmentMimeType,
        private readonly ?FrameData $frameData = null,
    ) {
        $this->validate();
    }

    public function hasFrameImage(): bool
    {
        return $this->frameData !== null;
    }

    public function getFrameData(): ?FrameData
    {
        return $this->frameData;
    }

    /**
     * Convert to Gemini API payload format
     *
     * Creates a payload structure for image-to-image composition.
     * Uses responseModalities: ['Image'] for image-only output.
     * Aspect ratio is controlled via prompt instruction (match environment image).
     *
     * Image order in payload:
     * 1. Product/artwork image (the art to place in scene)
     * 2. Environment/room image (the scene to place art into)
     * 3. Frame corner image (optional - reference for frame appearance)
     * 4. Frame edge image (optional - additional reference)
     *
     * @return array API-ready payload
     */
    public function toApiPayload(): array
    {
        $parts = [
            ['text' => $this->prompt],
            [
                'inline_data' => [
                    'mime_type' => $this->productMimeType,
                    'data' => $this->productImageBase64,
                ],
            ],
            [
                'inline_data' => [
                    'mime_type' => $this->environmentMimeType,
                    'data' => $this->environmentImageBase64,
                ],
            ],
        ];

        // Add frame reference images if provided
        if ($this->frameData !== null) {
            // Add corner image (primary frame reference)
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $this->frameData->mimeType,
                    'data' => $this->frameData->cornerImageBase64,
                ],
            ];

            // Add edge image if available (secondary frame reference)
            if ($this->frameData->edgeImageBase64 !== null) {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $this->frameData->mimeType,
                        'data' => $this->frameData->edgeImageBase64,
                    ],
                ];
            }
        }

        return [
            'contents' => [
                ['parts' => $parts],
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'topK' => 32,
                'topP' => 1,
                'maxOutputTokens' => 4096,
                'responseModalities' => ['Image'],
            ],
        ];
    }

    /**
     * Validate construction parameters
     *
     * @throws InvalidArgumentException If parameters are invalid
     */
    private function validate(): void
    {
        if (empty(trim($this->prompt))) {
            throw new InvalidArgumentException('Prompt cannot be empty');
        }

        if (empty($this->productImageBase64)) {
            throw new InvalidArgumentException('Product image data cannot be empty');
        }

        if (empty($this->environmentImageBase64)) {
            throw new InvalidArgumentException('Environment image data cannot be empty');
        }
    }
}
