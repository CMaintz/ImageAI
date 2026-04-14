<?php declare(strict_types=1);

namespace Illux\ImageAi\DTO\Request;

use InvalidArgumentException;

/**
 * DTO for Gemini API image generation requests
 *
 * Represents a single image generation request with all parameters
 * needed for the Gemini image generation API.
 *
 * Prompt construction should be done by the calling service before
 * creating this request - this DTO only handles API payload conversion.
 */
class GenerationRequest
{
    private const array VALID_ASPECT_RATIOS = ['1:1', '16:9', '9:16', '4:3', '3:4'];

    /**
     * @param string $prompt Scene generation prompt (user content)
     * @param string $systemInstruction System instruction for the model
     * @param string $aspectRatio Aspect ratio for generated image
     * @param int $numberOfImages Number of images to generate (1-8)
     */
    public function __construct(
        private readonly string $prompt,
        private readonly string $systemInstruction = '',
        private readonly string $aspectRatio = '16:9',
        private readonly int $numberOfImages = 1,
    ) {
        $this->validate();
    }

    /**
     * Convert to Gemini API payload format
     *
     * Creates a payload structure compatible with Gemini 2.5 Flash Image generation.
     * Uses responseModalities: ['Image'] for image-only output.
     * System instruction is passed separately from user content.
     *
     * @return array API-ready payload
     */
    public function toApiPayload(): array
    {
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $this->prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['Image'],
                'imageConfig' => [
                    'aspectRatio' => $this->aspectRatio,
                ],
            ],
        ];

        if (!empty($this->systemInstruction)) {
            $payload['system_instruction'] = [
                'parts' => [
                    ['text' => $this->systemInstruction]
                ]
            ];
        }

        return $payload;
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

        if (!in_array($this->aspectRatio, self::VALID_ASPECT_RATIOS, true)) {
            throw new InvalidArgumentException(
                'Invalid aspect ratio: ' . $this->aspectRatio .
                '. Valid options: ' . implode(', ', self::VALID_ASPECT_RATIOS)
            );
        }

        if ($this->numberOfImages < 1 || $this->numberOfImages > 8) {
            throw new InvalidArgumentException(
                'Number of images must be between 1 and 8, got: ' . $this->numberOfImages
            );
        }
    }
}
