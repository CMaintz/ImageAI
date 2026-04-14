<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Media;

use Illux\ImageAi\Config\PluginConstants;
use RuntimeException;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailCollection;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailEntity;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Central service for reading media files.
 *
 * Handles both HTTP URL fetching (for live/CDN environments) and local filesystem
 * access (for development). This is the single source of truth for media retrieval.
 *
 * Includes thumbnail selection logic to optimize payload size while maintaining
 * sufficient image quality.
 */
class MediaFileReader
{
    /**
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    public function __construct(
        private readonly EntityRepository $mediaRepository,
        private readonly string $projectDir,
        private readonly ?HttpClientInterface $httpClient = null
    ) {
    }

    public function getMediaById(string $mediaId, Context $context): MediaEntity
    {
        $criteria = new Criteria([$mediaId]);
        $criteria->addAssociation('thumbnails');
        /** @var MediaEntity|null $media */
        $media = $this->mediaRepository->search($criteria, $context)->first();

        if ($media === null) {
            throw new RuntimeException("Media not found: {$mediaId}");
        }

        return $media;
    }

    /**
     * Read media file contents - tries HTTP URL first, falls back to filesystem
     */
    public function readMediaFile(MediaEntity $media, int $targetWidth = PluginConstants::TARGET_IMAGE_WIDTH): string
    {
        $imageUrl = $this->getOptimalImageUrl($media, $targetWidth);

        $imageData = $this->fetchFromUrl($imageUrl);
        if ($imageData !== null) {
            return $imageData;
        }

        return $this->readFromFilesystem($media);
    }

    public function readMediaFileAsBase64(
        MediaEntity $media,
        int $targetWidth = PluginConstants::TARGET_IMAGE_WIDTH
    ): string {
        return base64_encode($this->readMediaFile($media, $targetWidth));
    }

    /**
     * Get optimal image URL for fetching.
     * Prefers Shopware thumbnails (around target width) for smaller payload size.
     * Falls back to original URL with width parameter if no suitable thumbnail.
     */
    public function getOptimalImageUrl(
        MediaEntity $media,
        int $targetWidth = PluginConstants::TARGET_IMAGE_WIDTH
    ): string {
        $thumbnails = $media->getThumbnails();

        if ($thumbnails !== null && $thumbnails->count() > 0) {
            $thumbnail = $this->findBestThumbnail($thumbnails, $targetWidth);
            if ($thumbnail !== null) {
                $thumbnailUrl = $thumbnail->getUrl();
                if (!empty($thumbnailUrl)) {
                    return $thumbnailUrl;
                }
            }
        }

        return $this->buildResizedUrl($media->getUrl(), $targetWidth);
    }

    /**
     * Try to fetch image from local filesystem using a URL path.
     * Used as fallback when HTTP fetch fails (e.g., in parallel batch operations).
     */
    public function tryLocalFallbackForUrl(string $imageUrl): ?string
    {
        $parsedUrl = parse_url($imageUrl);
        if (!isset($parsedUrl['path'])) {
            return null;
        }

        $path = $parsedUrl['path'];
        $possiblePaths = [
            $this->projectDir . '/public' . $path,
            $this->projectDir . '/public/' . ltrim($path, '/'),
            'public' . $path,
            'public/' . ltrim($path, '/'),
        ];

        foreach ($possiblePaths as $localPath) {
            if (file_exists($localPath) && is_file($localPath)) {
                $imageData = @file_get_contents($localPath);
                if ($imageData !== false) {
                    return $imageData;
                }
            }
        }

        return null;
    }

    private function findBestThumbnail(MediaThumbnailCollection $thumbnails, int $targetWidth): ?MediaThumbnailEntity
    {
        $idealCandidate = null;
        $idealDiff = PHP_INT_MAX;
        $fallbackCandidate = null;
        $fallbackMinDim = 0;

        foreach ($thumbnails as $thumbnail) {
            $width = $thumbnail->getWidth();
            $height = $thumbnail->getHeight();
            $maxDim = max($width, $height);
            $minDim = min($width, $height);

            if ($width >= $targetWidth
                && $height >= $targetWidth
                && $maxDim <= PluginConstants::MAX_THUMBNAIL_DIMENSION
            ) {
                $diff = $maxDim - $targetWidth;
                if ($diff < $idealDiff) {
                    $idealDiff = $diff;
                    $idealCandidate = $thumbnail;
                }
            }

            if ($minDim > $fallbackMinDim) {
                $fallbackMinDim = $minDim;
                $fallbackCandidate = $thumbnail;
            }
        }

        return $idealCandidate ?? $fallbackCandidate;
    }

    private function buildResizedUrl(string $baseUrl, int $targetWidth): string
    {
        $cleanUrl = preg_replace('/[?&]width=\d+/', '', $baseUrl) ?? $baseUrl;
        $separator = str_contains($cleanUrl, '?') ? '&' : '?';

        return $cleanUrl . $separator . 'width=' . $targetWidth;
    }

    /**
     * Fetch image data from URL using HttpClient or file_get_contents
     */
    private function fetchFromUrl(string $imageUrl): ?string
    {
        // Try with HttpClient if available
        if ($this->httpClient !== null) {
            try {
                $response = $this->httpClient->request('GET', $imageUrl, [
                    'timeout' => PluginConstants::API_TIMEOUT_IMAGE_FETCH,
                ]);
                return $response->getContent();
            } catch (Throwable) {
                // HttpClient failed, try file_get_contents
            }
        }
        $imageData = @file_get_contents($imageUrl);
        if ($imageData !== false) {
            return $imageData;
        }
        return null;
    }

    private function readFromFilesystem(MediaEntity $media): string
    {
        $path = $media->getPath();

        if (!$path) {
            throw new RuntimeException('Media has no path: ' . $media->getId());
        }

        $possiblePaths = [
            $this->projectDir . '/public/' . $path,
            $this->projectDir . '/public/' . ltrim($path, '/'),
            'public/' . $path,
            'public/' . ltrim($path, '/'),
        ];

        foreach ($possiblePaths as $fullPath) {
            if (file_exists($fullPath) && is_file($fullPath)) {
                $imageData = @file_get_contents($fullPath);
                if ($imageData !== false) {
                    return $imageData;
                }
            }
        }

        throw new RuntimeException(
            "Media file not found. Tried HTTP URL and filesystem paths. Media ID: {$media->getId()}"
        );
    }
}
