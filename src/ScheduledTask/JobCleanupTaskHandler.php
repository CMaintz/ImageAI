<?php declare(strict_types=1);

namespace CMaintz\ImageAi\ScheduledTask;

use DateTimeImmutable;
use CMaintz\ImageAi\Core\Content\AiAnalysisResult\Entity\AiAnalysisResultCollection;
use CMaintz\ImageAi\Model\Enum\AiAnalysisStatusEnum;
use CMaintz\ImageAi\Model\Enum\BatchJobStatusEnum;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use CMaintz\ImageAi\Core\Content\AiBatchJob\AiBatchJobCollection;
use CMaintz\ImageAi\Core\Content\AiPendingSceneImage\AiPendingSceneImageCollection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cleans up old batch jobs, pending scene images, and stale analysis results.
 * Retention periods are defined by the private constants below.
 */
#[AsMessageHandler(handles: JobCleanupTask::class)]
class JobCleanupTaskHandler extends ScheduledTaskHandler
{
    private const int COMPLETED_RETENTION_DAYS = 30;
    private const int FAILED_RETENTION_DAYS = 7;
    private const int CANCELLED_RETENTION_DAYS = 3;
    private const int PENDING_IMAGE_RETENTION_DAYS = 30;
    private const int STALE_PROCESSING_HOURS = 2;
    private const int BATCH_SIZE = 100;

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     * @param EntityRepository<AiBatchJobCollection> $batchJobRepository
     * @param EntityRepository<AiPendingSceneImageCollection> $pendingSceneImageRepository
     * @param EntityRepository<AiAnalysisResultCollection> $analysisResultRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $batchJobRepository,
        private readonly EntityRepository $pendingSceneImageRepository,
        private readonly EntityRepository $analysisResultRepository
    ) {
        parent::__construct($scheduledTaskRepository, $this->logger);
    }

    public function run(): void
    {
        $this->logger->info('[CMaintzImageAi] Starting job cleanup task');

        $context = Context::createCLIContext();

        $batchJobsDeleted = $this->cleanupBatchJobs($context);
        $pendingImagesDeleted = $this->cleanupPendingSceneImages($context);
        $staleResultsMarkedFailed = $this->markStaleProcessingResultsAsFailed($context);

        $this->logger->info('[CMaintzImageAi] Job cleanup task completed', [
            'batchJobsDeleted' => $batchJobsDeleted,
            'pendingImagesDeleted' => $pendingImagesDeleted,
            'staleResultsMarkedFailed' => $staleResultsMarkedFailed,
        ]);
    }

    private function cleanupBatchJobs(Context $context): int
    {
        $now = new DateTimeImmutable();
        $deletedCount = 0;

        $criteria = new Criteria();
        $criteria->setLimit(self::BATCH_SIZE);
        $criteria->addFilter(new OrFilter([
            new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('status', BatchJobStatusEnum::Completed->value),
                new RangeFilter('completedAt', [
                    RangeFilter::LTE => $now->modify('-' .
                        self::COMPLETED_RETENTION_DAYS . ' days')->format('Y-m-d H:i:s'),
                ]),
            ]),

            new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('status', BatchJobStatusEnum::Failed->value),
                new RangeFilter('createdAt', [
                    RangeFilter::LTE => $now->modify('-' .
                        self::FAILED_RETENTION_DAYS . ' days')->format('Y-m-d H:i:s'),
                ]),
            ]),

            new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('status', BatchJobStatusEnum::Cancelled->value),
                new RangeFilter('createdAt', [
                    RangeFilter::LTE => $now->modify('-' .
                        self::CANCELLED_RETENTION_DAYS . ' days')->format('Y-m-d H:i:s'),
                ]),
            ]),
        ]));

        do {
            $result = $this->batchJobRepository->searchIds($criteria, $context);
            $ids = $result->getIds();

            if (empty($ids)) {
                break;
            }

            $deleteData = array_map(fn($id) => ['id' => $id], $ids);
            $this->batchJobRepository->delete($deleteData, $context);
            $deletedCount += count($ids);

            $this->logger->debug('[CMaintzImageAi] Deleted batch of old jobs', [
                'count' => count($ids),
            ]);

            gc_collect_cycles();
        } while (count($ids) >= self::BATCH_SIZE);

        return $deletedCount;
    }

    private function cleanupPendingSceneImages(Context $context): int
    {
        $now = new DateTimeImmutable();
        $deletedCount = 0;

        $criteria = new Criteria();
        $criteria->setLimit(self::BATCH_SIZE);
        $criteria->addFilter(new RangeFilter('createdAt', [
            RangeFilter::LTE => $now->modify('-' . self::PENDING_IMAGE_RETENTION_DAYS . ' days')->format('Y-m-d H:i:s'),
        ]));

        do {
            $result = $this->pendingSceneImageRepository->searchIds($criteria, $context);
            $ids = $result->getIds();

            if (empty($ids)) {
                break;
            }

            $deleteData = array_map(fn($id) => ['id' => $id], $ids);
            $this->pendingSceneImageRepository->delete($deleteData, $context);
            $deletedCount += count($ids);

            $this->logger->debug('[CMaintzImageAi] Deleted batch of old pending images', [
                'count' => count($ids),
            ]);

            gc_collect_cycles();
        } while (count($ids) >= self::BATCH_SIZE);

        return $deletedCount;
    }

    /**
     * Mark analysis results stuck in "processing" status as failed.
     *
     * Results that have been processing for longer than STALE_PROCESSING_HOURS
     * are assumed to have failed silently (e.g., queue worker crash, timeout).
     */
    private function markStaleProcessingResultsAsFailed(Context $context): int
    {
        $now = new DateTimeImmutable();
        $updatedCount = 0;

        $criteria = new Criteria();
        $criteria->setLimit(self::BATCH_SIZE);
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('status', AiAnalysisStatusEnum::Processing->value),
            new RangeFilter('updatedAt', [
                RangeFilter::LTE => $now->modify('-' . self::STALE_PROCESSING_HOURS . ' hours')->format('Y-m-d H:i:s'),
            ]),
        ]));

        do {
            $result = $this->analysisResultRepository->searchIds($criteria, $context);
            $ids = $result->getIds();

            if (empty($ids)) {
                break;
            }

            $updateData = array_map(fn($id) => [
                'id' => $id,
                'status' => AiAnalysisStatusEnum::Failed->value,
                'errorMessage' => 'Analysis timed out - stuck in processing state for over '
                    . self::STALE_PROCESSING_HOURS . ' hour(s)',
            ], $ids);

            $this->analysisResultRepository->update($updateData, $context);
            $updatedCount += count($ids);

            $this->logger->debug('[CMaintzImageAi] Marked stale processing results as failed', [
                'count' => count($ids),
            ]);

            gc_collect_cycles();
        } while (count($ids) >= self::BATCH_SIZE);

        return $updatedCount;
    }
}
