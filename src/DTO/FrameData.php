<?php declare(strict_types=1);

namespace CMaintz\ImageAi\DTO;

/**
 * DTO containing frame reference data for AI composition.
 *
 * Includes frame images (corner and edge) and physical dimensions
 * to help the AI generate accurate frame representations.
 */
final class FrameData
{
    public function __construct(
        /** Frame corner image as base64 (top_left_corner) */
        public readonly string $cornerImageBase64,
        /** MIME type of frame images */
        public readonly string $mimeType,
        /** Frame edge image as base64 (top_middle), optional */
        public readonly ?string $edgeImageBase64 = null,
        /** Frame profile width in cm */
        public readonly ?float $spanCm = null,
        /** How much frame overlaps the image in cm */
        public readonly ?float $overlapCm = null,
        /** Label/name of the frame (e.g., "Mat Sort Eg") */
        public readonly ?string $name = null,
    ) {
    }

    /**
     * Calculate visible frame width (span minus overlap)
     */
    public function getVisibleWidthCm(): ?float
    {
        if ($this->spanCm === null) {
            return null;
        }

        return $this->spanCm - ($this->overlapCm ?? 0);
    }

    /**
     * Create from resolver result array
     *
     * @param array{
     *     frameCornerImageBase64: string|null,
     *     frameEdgeImageBase64: string|null,
     *     frameMimeType: string|null,
     *     frameSpanCm: float|null,
     *     frameOverlapCm: float|null,
     *     frameName: string|null
     * } $data
     */
    public static function fromResolverResult(array $data): ?self
    {
        if ($data['frameCornerImageBase64'] === null) {
            return null;
        }

        return new self(
            cornerImageBase64: $data['frameCornerImageBase64'],
            mimeType: $data['frameMimeType'] ?? 'image/jpeg',
            edgeImageBase64: $data['frameEdgeImageBase64'],
            spanCm: $data['frameSpanCm'],
            overlapCm: $data['frameOverlapCm'],
            name: $data['frameName'],
        );
    }

    /**
     * Check if we have dimension data
     */
    public function hasDimensions(): bool
    {
        return $this->spanCm !== null;
    }

    /**
     * Convert to array for serialization (e.g., session storage)
     */
    public function toArray(): array
    {
        return [
            'cornerImageBase64' => $this->cornerImageBase64,
            'mimeType' => $this->mimeType,
            'edgeImageBase64' => $this->edgeImageBase64,
            'spanCm' => $this->spanCm,
            'overlapCm' => $this->overlapCm,
            'name' => $this->name,
        ];
    }

    /**
     * Create from serialized array
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || !isset($data['cornerImageBase64'])) {
            return null;
        }

        return new self(
            cornerImageBase64: $data['cornerImageBase64'],
            mimeType: $data['mimeType'] ?? 'image/jpeg',
            edgeImageBase64: $data['edgeImageBase64'] ?? null,
            spanCm: $data['spanCm'] ?? null,
            overlapCm: $data['overlapCm'] ?? null,
            name: $data['name'] ?? null,
        );
    }
}
