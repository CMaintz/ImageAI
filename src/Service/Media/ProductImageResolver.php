<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Service\Media;

use CMaintz\ImageAi\Config\PluginConstants;
use CMaintz\ImageAi\DTO\Image\ResolvedProductImage;
use RuntimeException;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Resolves product cover images to base64-encoded data for AI analysis.
 */
class ProductImageResolver
{
    public function __construct(
        private readonly MediaFileReader $mediaFileReader,
        private readonly ?HttpClientInterface $httpClient = null
    ) {
    }

    /**
     * Resolve multiple product images in parallel (if HttpClient available)
     * @param ProductEntity[] $products
     * @param array<string, string> $analysisResultMapping Map of productId => analysisResultId
     * @return array{resolved: array<string, ResolvedProductImage>, failed: array<string, array>}
     */
    public function resolveProductImagesParallel(array $products, array $analysisResultMapping = []): array
    {
        if ($this->httpClient === null) {
            return $this->resolveProductImagesSequential($products, $analysisResultMapping);
        }

        $resolved = [];
        $failed = [];
        $responses = [];
        $productMeta = [];

        foreach ($products as $product) {
            $productId = $product->getId();

            try {
                $media = $product->getCover()?->getMedia();
                if (!$media) {
                    $failed[$productId] = [
                        'error' => 'Product has no cover image',
                        'product' => $product,
                        'analysisResultId' => $analysisResultMapping[$productId] ?? null,
                    ];
                    continue;
                }

                $imageUrl = $this->mediaFileReader->getOptimalImageUrl($media);

                $responses[$productId] = $this->httpClient->request('GET', $imageUrl, [
                    'timeout' => PluginConstants::API_TIMEOUT_IMAGE_FETCH,
                ]);

                $productMeta[$productId] = [
                    'mimeType' => $media->getMimeType() ?? 'image/jpeg',
                    'originalUrl' => $imageUrl,
                    'analysisResultId' => $analysisResultMapping[$productId] ?? Uuid::randomHex(),
                ];
            } catch (Throwable $e) {
                $failed[$productId] = [
                    'error' => 'Failed to prepare image request: ' . $e->getMessage(),
                    'product' => $product,
                ];
            }
        }

        foreach ($responses as $productId => $response) {
            try {
                $content = $response->getContent();
                $meta = $productMeta[$productId];

                $resolved[$productId] = new ResolvedProductImage(
                    productId: $productId,
                    base64Data: base64_encode($content),
                    mimeType: $meta['mimeType'],
                    analysisResultId: $meta['analysisResultId'],
                    originalUrl: $meta['originalUrl'],
                );
            } catch (TransportExceptionInterface $e) {
                $meta = $productMeta[$productId] ?? null;
                if ($meta !== null) {
                    $localContent = $this->mediaFileReader->tryLocalFallbackForUrl($meta['originalUrl']);
                    if ($localContent !== null) {
                        $resolved[$productId] = new ResolvedProductImage(
                            productId: $productId,
                            base64Data: base64_encode($localContent),
                            mimeType: $meta['mimeType'],
                            analysisResultId: $meta['analysisResultId'],
                            originalUrl: $meta['originalUrl'],
                        );
                        continue;
                    }
                }

                $failed[$productId] = [
                    'error' => 'HTTP transport error: ' . $e->getMessage(),
                    'product' => null,
                    'analysisResultId' => $meta['analysisResultId'] ?? null,
                ];
            } catch (Throwable $e) {
                $meta = $productMeta[$productId] ?? null;
                $failed[$productId] = [
                    'error' => 'Image fetch failed: ' . $e->getMessage(),
                    'product' => null,
                    'analysisResultId' => $meta['analysisResultId'] ?? null,
                ];
            }
        }

        return [
            'resolved' => $resolved,
            'failed' => $failed,
        ];
    }

    /**
     * Sequential fallback for environments without HttpClient
     * @param ProductEntity[] $products
     * @param array<string, string> $analysisResultMapping
     * @return array{resolved: array<string, ResolvedProductImage>, failed: array<string, array>}
     */
    public function resolveProductImagesSequential(array $products, array $analysisResultMapping = []): array
    {
        $resolved = [];
        $failed = [];

        foreach ($products as $product) {
            $productId = $product->getId();

            try {
                $analysisResultId = $analysisResultMapping[$productId] ?? Uuid::randomHex();
                $resolved[$productId] = $this->resolveProductImage($product, $analysisResultId);
            } catch (Throwable $e) {
                $failed[$productId] = [
                    'error' => 'Image resolution failed: ' . $e->getMessage(),
                    'product' => $product,
                    'analysisResultId' => $analysisResultMapping[$productId] ?? null,
                ];
            }
        }

        return [
            'resolved' => $resolved,
            'failed' => $failed,
        ];
    }

    public function resolveProductImage(ProductEntity $product, ?string $analysisResultId = null): ResolvedProductImage
    {
        $media = $product->getCover()?->getMedia();

        if (!$media) {
            throw new RuntimeException('Product has no cover image');
        }

        return new ResolvedProductImage(
            productId: $product->getId(),
            base64Data: $this->mediaFileReader->readMediaFileAsBase64($media),
            mimeType: $media->getMimeType() ?? 'image/jpeg',
            analysisResultId: $analysisResultId ?? Uuid::randomHex(),
            originalUrl: $this->mediaFileReader->getOptimalImageUrl($media),
        );
    }

    /**
     * Resolve product images in parallel and chunk into API-sized batches.
     * If a batch exceeds API limits, the Orchestrator's retry logic reduces batch size dynamically.
     *
     * @param ProductEntity[] $products
     * @param array<string, string> $analysisResultMapping Map of productId => analysisResultId
     * @return array{batches: ResolvedProductImage[][], failedProducts: array<string, array>}
     */
    public function resolveAndBatchProductImages(
        array $products,
        array $analysisResultMapping = []
    ): array {
        $resolvedImages = $this->resolveProductImagesParallel($products, $analysisResultMapping);

        $batches = array_chunk(
            array_values($resolvedImages['resolved']),
            PluginConstants::MAX_PRODUCTS_PER_API_BATCH
        );

        return [
            'batches' => $batches,
            'failedProducts' => $resolvedImages['failed'],
        ];
    }
}
