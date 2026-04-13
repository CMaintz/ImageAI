<?php declare(strict_types=1);

namespace Illux\ImageAi\Queue\Handler;

use Illux\ImageAi\Config\PluginConstants;
use Illux\ImageAi\Queue\Message\AnalyzeBatchMessage;
use Illux\ImageAi\Orchestrator\AnalysisOrchestrator;
use Illux\ImageAi\Service\BatchJobService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler(handles: AnalyzeBatchMessage::class)]
final class AnalyzeBatchHandler
{
    private const int MEMORY_THRESHOLD_MB = 128;

    public function __construct(
        private readonly AnalysisOrchestrator $batchOrchestrationService,
        private readonly BatchJobService $batchJobService,
        private readonly LoggerInterface $logger
    ) {
    }

    private function checkMemoryAndCleanup(): void
    {
        $memoryUsageMb = memory_get_usage(true) / 1024 / 1024;

        if ($memoryUsageMb > self::MEMORY_THRESHOLD_MB) {
            $collected = gc_collect_cycles();
            $newMemoryMb = memory_get_usage(true) / 1024 / 1024;

            $this->logger->debug('[IlluxImageAi] Memory cleanup triggered', [
                'beforeMb' => round($memoryUsageMb, 2),
                'afterMb' => round($newMemoryMb, 2),
                'freedMb' => round($memoryUsageMb - $newMemoryMb, 2),
                'cyclesCollected' => $collected,
            ]);
        }
    }

    /**
     * Distributes items evenly across the minimum number of chunks needed.
     * Example: 102 items with max 50 → 3 chunks of 34 (not 50, 50, 2)
     * @param array<string> $items
     * @return array<array<string>>
     */
    private function createBalancedChunks(array $items): array
    {
        $total = count($items);
        if ($total === 0) {
            return [];
        }

        $numChunks = (int) ceil($total / PluginConstants::HANDLER_CHUNK_SIZE);
        $optimalChunkSize = max(1, (int) ceil($total / $numChunks));

        return array_chunk($items, $optimalChunkSize);
    }

    public function __invoke(AnalyzeBatchMessage $message): void
    {
        $context = Context::createCLIContext();
        $batchJobId = $message->batchJobId;
        $productIds = $message->productIds;

        // Check if job is still in a valid state (queued/processing)
        // This prevents message redelivery from reprocessing completed/cancelled jobs
        $batchJob = $this->batchJobService->get($batchJobId, $context);
        if ($batchJob === null) {
            $this->logger->warning('[IlluxImageAi] Batch job not found, skipping', [
                'batchJobId' => $batchJobId,
            ]);
            return;
        }

        $status = $batchJob->status->value;
        if (!in_array($status, ['queued', 'processing'], true)) {
            $this->logger->warning('[IlluxImageAi] Batch job is not in processable state, skipping', [
                'batchJobId' => $batchJobId,
                'status' => $status,
            ]);
            return;
        }

        $this->batchJobService->markProcessing($batchJobId, $context);

        $analysisResultMapping = $message->analysisResultMapping;

        try {
            // Process in balanced chunks (evenly distributed across min batches needed)
            $chunks = $this->createBalancedChunks($productIds);
            $totalSuccessCount = 0;
            $totalFailureCount = 0;

            foreach ($chunks as $chunkIndex => $chunk) {
                if ($this->batchJobService->isCancelled($batchJobId, $context)) {
                    $this->logger->debug('[IlluxImageAi] Batch analysis job cancelled', [
                        'batchJobId' => $batchJobId,
                        'processedChunks' => $chunkIndex,
                        'totalChunks' => count($chunks),
                    ]);
                    return;
                }

                $result = $this->batchOrchestrationService->processSpecificProducts(
                    productIds: $chunk,
                    context: $context,
                    analysisResultMapping: $analysisResultMapping,
                    metadataFilters: $message->metadataFilters
                );

                $successCount = $result['successCount'] ?? 0;
                $failureCount = $result['failureCount'] ?? 0;

                $totalSuccessCount += $successCount;
                $totalFailureCount += $failureCount;

                $this->batchJobService->incrementProgress(
                    id: $batchJobId,
                    processedItems: count($chunk),
                    successCount: $successCount,
                    failureCount: $failureCount,
                    context: $context
                );

                $this->checkMemoryAndCleanup();
            }

            $this->batchJobService->markCompleted($batchJobId, $context);
        } catch (Throwable $e) {
            $this->logger->error('[IlluxImageAi] Batch analysis job failed', [
                'batchJobId' => $batchJobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->batchJobService->markFailed($batchJobId, $e->getMessage(), $context);
        }
    }
}
