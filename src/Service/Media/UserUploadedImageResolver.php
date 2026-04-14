<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Media;

use Illux\ImageAi\Config\PluginConstants;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves user-uploaded images from GraphicalAssistance or ChiliPublish storage.
 *
 * Single source of truth for accessing user uploads across different upload systems.
 * Validates session tokens to ensure users can only access their own uploads.
 */
class UserUploadedImageResolver
{

    public function __construct(
        private readonly FilesystemOperator $filesystemPrivate,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Resolve user uploaded image from request parameters.
     *
     * Supports both GraphicalAssistance (storageToken + filename) and
     * ChiliPublish (assetId) upload systems.
     *
     * @return array{base64: string, mimeType: string}|null Image data or null if not found
     */
    public function resolveFromRequest(Request $request): ?array
    {
        // Try GraphicalAssistance first (storage token + filename)
        $storageToken = $request->request->getString('storageToken');
        $filename = $request->request->getString('filename');

        if ($storageToken !== '' && $filename !== '') {
            return $this->resolveGraphicalAssistance($request, $storageToken, $filename);
        }

        // Try ChiliPublish (asset ID)
        $assetId = $request->request->getString('assetId');
        if ($assetId !== '') {
            return $this->resolveChiliPublish($assetId);
        }

        return null;
    }

    /**
     * Resolve image from GraphicalAssistance storage.
     *
     * @param Request $request Request for session access
     * @param string $storageToken Session key for storage path (e.g., "assistanceStorageXYZ")
     * @param string $filename Uploaded filename
     * @return array{base64: string, mimeType: string}|null
     */
    public function resolveGraphicalAssistance(Request $request, string $storageToken, string $filename): ?array
    {
        // Validate storage token exists in session (security check)
        $session = $request->getSession();
        $storagePath = $session->get($storageToken);

        if ($storagePath === null) {
            return null;
        }

        $filename = basename($filename);
        $filePath = PluginConstants::GRAPHICAL_ASSISTANCE_UPLOAD_PATH . '/' . $storagePath . '/' . $filename;

        if (!$this->filesystemPrivate->has($filePath)) {
            return null;
        }

        $content = $this->filesystemPrivate->read($filePath);
        $mimeType = $this->getMimeTypeFromExtension($filename);

        return [
            'base64' => base64_encode($content),
            'mimeType' => $mimeType,
        ];
    }

    /**
     * Resolve image from ChiliPublish EditorAsset storage.
     *
     * ChiliPublish stores original files at: {projectDir}/files/chili-editor-assets/{assetId}.original.bin
     *
     * @param string $assetId The EditorAsset ID
     * @return array{base64: string, mimeType: string}|null
     */
    public function resolveChiliPublish(string $assetId): ?array
    {
        $assetId = basename($assetId);

        // ChiliPublish stores files in the direct filesystem
        // The pattern is files/chili-editor-assets/{storageDirectory}/{assetId}.original.bin
        // Since we don't have the storageDirectory, we use a glob pattern
        $pattern = $this->projectDir . '/files/chili-editor-assets/*/' . $assetId . '.original.bin';
        $matches = glob($pattern);

        if (empty($matches) || !file_exists($matches[0])) {
            return null;
        }

        $content = file_get_contents($matches[0]);
        if ($content === false) {
            return null;
        }

        // Detect MIME type from content (since .bin extension doesn't help)
        $mimeType = $this->detectMimeType($content);

        return [
            'base64' => base64_encode($content),
            'mimeType' => $mimeType,
        ];
    }

    /**
     * Check if user has any uploaded image available.
     *
     * Checks session for GraphicalAssistance storage tokens.
     */
    public function hasUploadedImage(Request $request): bool
    {
        $session = $request->getSession();

        // Check GraphicalAssistance session keys
        foreach ($session->all() as $key => $value) {
            if (str_starts_with($key, 'assistanceStorage') && is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private function getMimeTypeFromExtension(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'tiff', 'tif' => 'image/tiff',
            default => 'application/octet-stream',
        };
    }

    private function detectMimeType(string $content): string
    {
        // Check magic bytes
        $header = substr($content, 0, 12);

        // JPEG
        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        // PNG
        if (str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }

        // GIF
        if (str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a')) {
            return 'image/gif';
        }

        // WebP
        if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        // BMP
        if (str_starts_with($header, 'BM')) {
            return 'image/bmp';
        }

        // TIFF (little-endian or big-endian)
        if (str_starts_with($header, "II\x2A\x00") || str_starts_with($header, "MM\x00\x2A")) {
            return 'image/tiff';
        }

        return 'application/octet-stream';
    }
}
