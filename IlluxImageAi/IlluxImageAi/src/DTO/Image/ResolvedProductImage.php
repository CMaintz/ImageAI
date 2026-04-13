<?php declare(strict_types=1);

namespace Illux\ImageAi\DTO\Image;

/**
 * DTO representing a product image with pre-resolved base64 data
 *
 * This DTO always contains the actual base64-encoded image data,
 * ready to be sent to the API. Image resolution (URL fetching, file reading)
 * is handled by ProductImageResolver service before creating this DTO.
 */
class ResolvedProductImage
{
    public function __construct(
        public readonly string $productId,
        public readonly string $base64Data,
        public readonly string $mimeType,
        public readonly string $analysisResultId,
        public readonly string $originalUrl,
    ) {
    }

    /**
     * Convert to API payload format (inline_data part)
     */
    public function toInlineDataPart(): array
    {
        return [
            'inline_data' => [
                'mime_type' => $this->mimeType,
                'data' => $this->base64Data,
            ],
        ];
    }
}
