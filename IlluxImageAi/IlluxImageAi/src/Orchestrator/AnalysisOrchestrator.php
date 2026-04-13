<?php declare(strict_types=1);

namespace Illux\ImageAi\Orchestrator;

use Illux\ImageAi\Api\Gemini\GeminiClient;
use Illux\ImageAi\Config\ContentConfiguration;
use Illux\ImageAi\Config\IlluxConfiguration;
use Illux\ImageAi\Config\PluginConstants;
use Illux\ImageAi\DTO\Image\ResolvedProductImage;
use Illux\ImageAi\Factory\AnalysisRequestFactory;
use Illux\ImageAi\Model\Enum\AiAnalysisStatusEnum;
use Illux\ImageAi\Service\Analysis\AnalysisPersistenceService;
use Illux\ImageAi\Service\Analysis\ProductAnalysisService;
use Illux\ImageAi\Service\Media\ProductImageResolver;
use Illux\ImageAi\Service\Property\PropertyLookupService;
use Illux\ImageAi\Trait\RetryWithBackoffTrait;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Throwable;

/**
 * Orchestrates batch processing of product analysis.
 *
 * Handles retry logic with exponential backoff via RetryWithBackoffTrait.
 * Always works with arrays of products — never processes one-by-one in a loop.
 */
class AnalysisOrchestrator
{
    use RetryWithBackoffTrait;

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly GeminiClient $geminiClient,
        private readonly ProductAnalysisService $productAnalysisService,
        private readonly AnalysisPersistenceService $resultPersistence,
        private readonly EntityRepository $productRepository,
        private readonly IlluxConfiguration $config,
        private readonly AnalysisRequestFactory $requestFactory,
        private readonly ProductImageResolver $productImageResolver,
        private readonly PropertyLookupService $propertyLookupService
    ) {
    }

    /**
     * Process specific products by their IDs
     * @param array $productIds Array of product IDs to analyze
     * @param Context $context
     * @param array $analysisResultMapping Map of productId => analysisResultId (created upfront to prevent duplicates)
     * @param array|null $metadataFilters Override which metadata fields to generate
     * @return array Processing results
     */
    public function processSpecificProducts(
        array $productIds,
        Context $context,
        array $analysisResultMapping = [],
        ?array $metadataFilters = null
    ): array {
        if (empty($productIds)) {
            return [
                'success' => false,
                'message' => 'No product IDs provided',
                'totalProducts' => 0,
            ];
        }

        $this->propertyLookupService->preloadAllPropertyOptions($context);

        $criteria = new Criteria($productIds);
        $criteria->addAssociation('cover.media.thumbnails');

        $products = $this->productRepository->search($criteria, $context)->getElements();

        if (empty($products)) {
            return [
                'success' => false,
                'message' => 'No valid products found for the provided IDs',
                'totalProducts' => 0,
            ];
        }

        return $this->processBatches($products, $context, $analysisResultMapping, $metadataFilters);
    }

    /**
     * Process all unanalyzed products
     * @param Context $context
     * @param bool $includeAnalyzed Re-analyze already analyzed products
     * @param array|null $metadataFilters Override which metadata fields to generate (overrides config)
     * @return array Processing results
     */
    public function orchestrateProductAnalysis(
        Context $context,
        bool $includeAnalyzed = false,
        ?array $metadataFilters = null
    ): array {
        // Preloads all property options to avoid N+1 queries during batch processing
        $this->propertyLookupService->preloadAllPropertyOptions($context);

        $eligibleProducts = $this->productAnalysisService->findEligibleProducts($context, $includeAnalyzed);
        if (empty($eligibleProducts)) {
            return [
                'success' => true,
                'message' => 'No eligible products found',
                'totalProducts' => 0,
                'productCount' => 0,
                'processedProducts' => 0,
            ];
        }

        return $this->processBatches($eligibleProducts, $context, [], $metadataFilters);
    }

    /**
     * Build ContentConfiguration with any overrides applied.
     * @param array|null $metadataFilters Override filters from the request
     * @return ContentConfiguration
     */
    private function resolveContentConfig(?array $metadataFilters): ContentConfiguration
    {
        $contentConfig = $this->config->getContentConfig();

        if ($metadataFilters !== null) {
            $contentConfig = $contentConfig->withOverrides($metadataFilters);
        }

        return $contentConfig;
    }

    private function processBatches(
        array $products,
        Context $context,
        array $analysisResultMapping = [],
        ?array $metadataFilters = null
    ): array {
        $totalProducts = count($products);
        $processedCount = 0;
        $successCount = 0;
        $failureCount = 0;

        $contentConfig = $this->resolveContentConfig($metadataFilters);

        // Resolve images in parallel and chunk into API-sized batches
        $batchResult = $this->productImageResolver->resolveAndBatchProductImages(
            $products,
            $analysisResultMapping
        );

        $batches = $batchResult['batches'];
        $failedProducts = $batchResult['failedProducts'];

        $failureCount += count($failedProducts);
        if (!empty($failedProducts)) {
            $this->resultPersistence->createFailedAnalysisResults($failedProducts, $context);
        }

        // Deferred images from size errors carry over to the next batch
        $deferredFromPrevious = [];

        foreach ($batches as $batchIndex => $resolvedImages) {
            $currentImages = array_merge($deferredFromPrevious, $resolvedImages);
            $deferredFromPrevious = [];
            $batchDeferredImages = [];

            try {
                $batchProcessResult = $this->retryWithBackoff(
                    fn() => $this->processBatch($currentImages, $contentConfig, $context),
                    PluginConstants::MAX_RETRIES,
                    function (Throwable $e) use (&$currentImages, &$batchDeferredImages): bool {
                        $removedImages = $this->splitBatchInHalf($currentImages);
                        if (!empty($removedImages)) {
                            $batchDeferredImages = array_merge($batchDeferredImages, $removedImages);
                            return true;
                        }
                        return false;
                    }
                );

                $processedCount += count($currentImages);
                $successCount += $batchProcessResult['successCount'];
                $failureCount += $batchProcessResult['failureCount'];

                $deferredFromPrevious = $batchDeferredImages;
            } catch (Throwable $e) {
                $failedResults = [];
                /** @var ResolvedProductImage $img */
                foreach ($currentImages as $img) {
                    $failedResults[] = [
                        'analysisResultId' => $img->analysisResultId,
                        'productId' => $img->productId,
                        'status' => AiAnalysisStatusEnum::Failed,
                        'errorMessage' => 'Batch failed: ' . $e->getMessage(),
                    ];
                }
                try {
                    // Only update results still in "processing" status - don't overwrite successful results
                    $this->resultPersistence->batchUpsertAnalysisResults($failedResults, $context, true);
                } catch (Throwable) {
                    //TODO handle errors
                }

                $failureCount += count($currentImages);
                $processedCount += count($currentImages);

                // Deferred images go to the next batch - they weren't part of this failure
                $deferredFromPrevious = $batchDeferredImages;
                continue;
            }
        }

        // Each iteration may split further if the batch is still too large
        while (!empty($deferredFromPrevious)) {
            $finalBatchImages = $deferredFromPrevious;
            $finalBatchDeferred = [];

            try {
                $finalResult = $this->retryWithBackoff(
                    fn() => $this->processBatch($finalBatchImages, $contentConfig, $context),
                    PluginConstants::MAX_RETRIES,
                    function (Throwable $e) use (&$finalBatchImages, &$finalBatchDeferred): bool {
                        $removedImages = $this->splitBatchInHalf($finalBatchImages);
                        if (!empty($removedImages)) {
                            $finalBatchDeferred = array_merge($finalBatchDeferred, $removedImages);
                            return true;
                        }
                        return false;
                    }
                );

                $processedCount += count($finalBatchImages);
                $successCount += $finalResult['successCount'];
                $failureCount += $finalResult['failureCount'];

                $deferredFromPrevious = $finalBatchDeferred;
            } catch (Throwable $e) {
                $failedResults = [];
                /** @var ResolvedProductImage $img */
                foreach ($finalBatchImages as $img) {
                    $failedResults[] = [
                        'analysisResultId' => $img->analysisResultId,
                        'productId' => $img->productId,
                        'status' => AiAnalysisStatusEnum::Failed,
                        'errorMessage' => 'Final batch failed: ' . $e->getMessage(),
                    ];
                }
                try {
                    // Only update results still in "processing" status - don't overwrite successful results
                    $this->resultPersistence->batchUpsertAnalysisResults($failedResults, $context, true);
                } catch (Throwable) {
                    // Persistence failure - errors will be logged at entry point
                }
                $failureCount += count($finalBatchImages);
                $processedCount += count($finalBatchImages);

                $deferredFromPrevious = $finalBatchDeferred;
            }
        }

        return [
            'success' => true,
            'mode' => 'batch',
            'message' => "Processed {$successCount} products successfully, {$failureCount} failed",
            'totalProducts' => $totalProducts,
            'productCount' => $totalProducts,
            'processedProducts' => $processedCount,
            'successCount' => $successCount,
            'failureCount' => $failureCount,
        ];
    }

    /**
     * Send one API call for the given pre-resolved images and persist the response.
     * Retry logic is handled by the caller via retryWithBackoff().
     * @param ResolvedProductImage[] $resolvedImages
     * @return array{successCount: int, failureCount: int}
     */
    private function processBatch(
        array $resolvedImages,
        ContentConfiguration $contentConfig,
        Context $context
    ): array {
        if (empty($resolvedImages)) {
            return ['successCount' => 0, 'failureCount' => 0];
        }

        $request = $this->requestFactory->createBatchRequest($resolvedImages, $contentConfig, $context);
        $response = $this->geminiClient->analyzeBatch($request);

        return $this->processSuccessfulBatchResponse($response, $resolvedImages, $context);
    }

    /**
     * Process a successful API response
     * @param array $response API response
     * @param ResolvedProductImage[] $images Images that were sent
     * @param Context $context
     * @return array{successCount: int, failureCount: int}
     */
    private function processSuccessfulBatchResponse(
        array $response,
        array $images,
        Context $context
    ): array {
        if (!isset($response['results'])) {
            return [
                'successCount' => 0,
                'failureCount' => count($images),
            ];
        }

        $shouldAutoApply = $this->config->getWorkflowConfig()->shouldAutoApply();
        $defaultStatus = $shouldAutoApply ? AiAnalysisStatusEnum::AutoApproved : AiAnalysisStatusEnum::PendingReview;

        return $this->resultPersistence->persistApiResults($response['results'], $defaultStatus, $context);
    }

    /**
     * Split batch in half for size-related errors
     * Removes the second half of images and returns them for deferred processing
     * @param ResolvedProductImage[] $images Array modified in place (keeps first half)
     * @return ResolvedProductImage[] The removed second half, or empty if can't split further
     */
    private function splitBatchInHalf(array &$images): array
    {
        $count = count($images);
        if ($count <= 1) {
            return [];
        }

        $splitPoint = (int) ceil($count / 2);
        $removedImages = array_slice($images, $splitPoint);
        $images = array_slice($images, 0, $splitPoint);

        return $removedImages;
    }
}
