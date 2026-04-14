<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Service\Analysis;

use CMaintz\ImageAi\Core\Content\AiAnalysisResult\Entity\AiAnalysisResultCollection;
use CMaintz\ImageAi\Core\Content\AiAnalysisResult\Entity\AiAnalysisResultEntity;
use CMaintz\ImageAi\Model\Enum\AiAnalysisStatusEnum;
use RuntimeException;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Throwable;

/**
 * Service responsible for persisting AI analysis results to database.
 * Uses AnalysisMapper for type-safe data mapping with DTOs.
 */
class AnalysisPersistenceService
{
    /**
     * @param EntityRepository<AiAnalysisResultCollection<AiAnalysisResultEntity>> $aiAnalysisResultRepository
     * @param EntityRepository<ProductCollection<ProductEntity>> $productRepository
     */
    public function __construct(
        private readonly EntityRepository $aiAnalysisResultRepository,
        private readonly EntityRepository $productRepository,
        private readonly AnalysisMapper $mapper,
        private readonly ProductUpdateAssembler $productUpdateAssembler
    ) {
    }

    /**
     * Process and persist API response results.
     * Low-confidence results are downgraded to PendingReview; AutoApproved ones are applied to products.
     *
     * @param array $apiResults Raw 'results' array from Gemini API response
     * @param AiAnalysisStatusEnum $defaultStatus Default status based on workflow config
     * @return array{successCount: int, failureCount: int}
     */
    public function persistApiResults(
        array $apiResults,
        AiAnalysisStatusEnum $defaultStatus,
        Context $context
    ): array {
        $successCount = 0;
        $failureCount = 0;
        $resultsToUpsert = [];
        $resultsToApply = [];

        foreach ($apiResults as $productResult) {
            $productId = $productResult['productId'] ?? null;
            $analysisResultId = $productResult['analysisResultId'] ?? null;

            if (!$productId || !$analysisResultId) {
                // Skip malformed results - missing IDs indicate response parsing issues
                $failureCount++;
                continue;
            }

            $entityData = $this->mapper->mapToEntityData(
                $analysisResultId,
                $productId,
                $defaultStatus,
                $productResult
            );

            $resultsToUpsert[] = $entityData;

            if ($entityData['status'] === AiAnalysisStatusEnum::AutoApproved->value) {
                $resultsToApply[] = [
                    'productId' => $productId,
                    'analysisResultId' => $analysisResultId,
                    'analysisData' => $productResult,
                ];
            }
        }

        if (!empty($resultsToUpsert)) {
            $upserted = $this->upsertResults($resultsToUpsert, $context);
            $successCount += $upserted;
            $failureCount += count($resultsToUpsert) - $upserted;
        }

        if (!empty($resultsToApply)) {
            $this->applyAnalysisToProducts($resultsToApply, $context);
        }

        return [
            'successCount' => $successCount,
            'failureCount' => $failureCount,
        ];
    }

    /**
     * Upserts results with a batch-first strategy, falling back to individual upserts.
     * Returns the number of successfully persisted records; failed items remain in
     * 'processing' status and will be caught by the stale-results cleanup task.
     */
    private function upsertResults(array $resultsToUpsert, Context $context): int
    {
        // Try batch first (fast path)
        try {
            $this->aiAnalysisResultRepository->upsert($resultsToUpsert, $context);
            return count($resultsToUpsert);
        } catch (Throwable) {
            // Batch failed, fall back to individual upserts
        }

        // Individual fallback - count successes, silently skip failures
        // (failed records stay in 'processing' and are cleaned up by JobCleanupTaskHandler)
        $successCount = 0;

        foreach ($resultsToUpsert as $entityData) {
            try {
                $this->aiAnalysisResultRepository->upsert([$entityData], $context);
                $successCount++;
            } catch (Throwable) {
                // Intentionally swallowed
            }
        }

        return $successCount;
    }

    /**
     * Create analysis result records upfront with 'processing' status.
     * Called when a batch job starts to reserve result IDs and prevent duplicates.
     *
     * @param array<string> $productIds Product IDs to create records for
     * @param string $batchJobId Batch job ID to link results to
     * @param Context $context Shopware context
     * @return array<string, string> Map of productId => analysisResultId for in-memory use during processing
     */
    public function createProcessingResults(array $productIds, string $batchJobId, Context $context): array
    {
        if (empty($productIds)) {
            return [];
        }

        $mapping = [];
        $records = [];

        foreach ($productIds as $productId) {
            $analysisResultId = Uuid::randomHex();
            $mapping[$productId] = $analysisResultId;

            $records[] = [
                'id' => $analysisResultId,
                'productId' => $productId,
                'status' => AiAnalysisStatusEnum::Processing->value,
                'batchJobId' => $batchJobId,
            ];
        }

        $this->aiAnalysisResultRepository->create($records, $context);
        return $mapping;
    }

    public function createFailedAnalysisResults(array $failedProducts, Context $context): void
    {
        if (empty($failedProducts)) {
            return;
        }

        $data = [];
        foreach ($failedProducts as $productId => $failureInfo) {
            $error = $failureInfo['error'];
            $analysisResultId = $failureInfo['analysisResultId'] ?? Uuid::randomHex();

            $data[] = [
                'id' => $analysisResultId,
                'productId' => $productId,
                'status' => AiAnalysisStatusEnum::Failed->value,
                'errorMessage' => $error,
            ];
        }

        $this->aiAnalysisResultRepository->upsert($data, $context);
    }

    /**
     * Batch upsert analysis results.
     *
     * When $onlyUpdateProcessing is true, only results still in "processing" status are updated.
     * This prevents queue retries from overwriting results that already succeeded.
     */
    public function batchUpsertAnalysisResults(
        array $results,
        Context $context,
        bool $onlyUpdateProcessing = false
    ): void {
        if (empty($results)) {
            return;
        }
        if ($onlyUpdateProcessing) {
            $results = $this->filterToProcessingOnly($results, $context);

            if (empty($results)) {
                // All results already have non-processing status
                return;
            }
        }

        $upsertData = [];
        foreach ($results as $result) {
            $upsertData[] = $this->mapper->mapToEntityData(
                $result['analysisResultId'],
                $result['productId'],
                $result['status'],
                $result['analysisData'] ?? null,
                $result['errorMessage'] ?? null
            );
        }

        $this->aiAnalysisResultRepository->upsert($upsertData, $context);
    }

    /**
     * Filter results to only include those still in "processing" status.
     */
    private function filterToProcessingOnly(array $results, Context $context): array
    {
        $analysisResultIds = array_column($results, 'analysisResultId');

        if (empty($analysisResultIds)) {
            return $results;
        }

        $criteria = new Criteria($analysisResultIds);
        $criteria->addFilter(new EqualsFilter('status', AiAnalysisStatusEnum::Processing->value));

        $processingResults = $this->aiAnalysisResultRepository->search($criteria, $context);
        $processingIds = array_flip($processingResults->getIds());

        return array_filter(
            $results,
            fn($result) => isset($processingIds[$result['analysisResultId']])
        );
    }

    /**
     * Apply analysis results to multiple products in batch
     *
     * Uses ProductUpdateAssembler to build product updates, then batch updates all products.
     *
     * @param array<array{productId: string, analysisData: array}> $productsWithAnalysis
     * @param Context $context Shopware context
     * @return int Number of products updated
     * @throws RuntimeException If any assembly or update fails
     */
    public function applyAnalysisToProducts(array $productsWithAnalysis, Context $context): int
    {
        if (empty($productsWithAnalysis)) {
            return 0;
        }

        $productUpdates = [];
        $errors = [];

        foreach ($productsWithAnalysis as $item) {
            try {
                $updateData = $this->productUpdateAssembler->assembleFromAnalysisData(
                    $item['productId'],
                    $item['analysisData'],
                    $context
                );
                if ($this->productUpdateAssembler->hasUpdateData($updateData)) {
                    $productUpdates[] = $updateData;
                }
            } catch (Throwable $e) {
                $errors[] = sprintf('Product %s: %s', $item['productId'], $e->getMessage());
            }
        }

        if (!empty($productUpdates)) {
            $this->productRepository->update($productUpdates, $context);
        }

        if (!empty($errors)) {
            throw new RuntimeException(sprintf(
                'Failed to apply %d of %d analysis results: %s',
                count($errors),
                count($productsWithAnalysis),
                implode('; ', $errors)
            ));
        }

        return count($productUpdates);
    }
}
