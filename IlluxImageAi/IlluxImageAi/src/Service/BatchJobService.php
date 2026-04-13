<?php declare(strict_types=1);

namespace Illux\ImageAi\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Illux\ImageAi\Core\Content\AiBatchJob\AiBatchJobCollection;
use Illux\ImageAi\Core\Content\AiBatchJob\AiBatchJobEntity;
use Illux\ImageAi\Model\Enum\BatchJobStatusEnum;
use Illux\ImageAi\Model\Enum\BatchJobTypeEnum;
use Illux\ImageAi\Service\Analysis\AnalysisPersistenceService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

class BatchJobService
{
    /**
     * @param EntityRepository<AiBatchJobCollection<AiBatchJobEntity>> $batchJobRepository
     */
    public function __construct(
        private readonly EntityRepository $batchJobRepository,
        private readonly AnalysisPersistenceService $analysisPersistence,
        private readonly Connection $connection
    ) {
    }

    /**
     * Create a new batch job for analysis.
     * Also creates analysis result records upfront with status 'processing'
     * to prevent duplicates on message redelivery.
     *
     * @return array{job: AiBatchJobEntity, analysisResultMapping: array<string, string>}
     */
    public function createAnalysisJob(
        array $productIds,
        Context $context,
        ?array $metadataFilters = null
    ): array {
        $batchJobId = Uuid::randomHex();

        $this->create(
            type: BatchJobTypeEnum::Analysis,
            totalItems: count($productIds),
            context: $context,
            productIds: $productIds,
            metadataFilters: $metadataFilters,
            id: $batchJobId
        );

        // Create analysis result records upfront - mapping returned for in-memory use
        $mapping = $this->analysisPersistence->createProcessingResults($productIds, $batchJobId, $context);

        /** @var AiBatchJobEntity $job */
        $job = $this->get($batchJobId, $context);

        return [
            'job' => $job,
            'analysisResultMapping' => $mapping,
        ];
    }

    public function createSceneGenerationJob(
        array $config,
        int $totalItems,
        Context $context
    ): AiBatchJobEntity {
        return $this->create(
            type: BatchJobTypeEnum::SceneGeneration,
            totalItems: $totalItems,
            context: $context,
            config: $config
        );
    }

    private function create(
        BatchJobTypeEnum $type,
        int $totalItems,
        Context $context,
        ?array $productIds = null,
        ?array $config = null,
        ?array $metadataFilters = null,
        ?string $id = null
    ): AiBatchJobEntity {
        $id = $id ?? Uuid::randomHex();

        $this->batchJobRepository->create([[
            'id' => $id,
            'type' => $type->value,
            'status' => BatchJobStatusEnum::Queued->value,
            'totalItems' => $totalItems,
            'processedItems' => 0,
            'successCount' => 0,
            'failureCount' => 0,
            'productIds' => $productIds,
            'config' => $config,
            'metadataFilters' => $metadataFilters,
        ]], $context);

        /** @var AiBatchJobEntity */
        return $this->get($id, $context);
    }

    public function get(string $id, Context $context): ?AiBatchJobEntity
    {
        $criteria = new Criteria([$id]);

        /** @var AiBatchJobCollection $result */
        $result = $this->batchJobRepository->search($criteria, $context)->getEntities();

        return $result->first();
    }

    /**
     * Get fresh batch job progress directly from database (bypasses DAL cache).
     * Calculates actual success/failure counts from linked analysis results
     * to ensure accuracy even if increment calls were missed.
     * @return array<string, mixed>|null
     */
    public function getFreshProgress(string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT
                LOWER(HEX(id)) as id,
                status,
                type,
                total_items as totalItems,
                error_message as errorMessage,
                started_at as startedAt,
                completed_at as completedAt,
                created_at as createdAt
             FROM ai_batch_job
             WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );

        if ($row === false) {
            return null;
        }

        $counts = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending_review' OR status = 'approved' OR status = 'auto_approved'
                    THEN 1 ELSE 0 END) as successCount,
                SUM(CASE WHEN status = 'failed' OR status = 'rejected' THEN 1 ELSE 0 END) as failureCount,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processingCount
             FROM ai_analysis_result
             WHERE batch_job_id = :batchJobId",
            ['batchJobId' => Uuid::fromHexToBytes($id)]
        );

        $successCount = (int) ($counts['successCount'] ?? 0);
        $failureCount = (int) ($counts['failureCount'] ?? 0);
        $processingCount = (int) ($counts['processingCount'] ?? 0);
        $processedItems = $successCount + $failureCount;

        $row['processedItems'] = $processedItems;
        $row['successCount'] = $successCount;
        $row['failureCount'] = $failureCount;
        $row['processingCount'] = $processingCount;

        return $row;
    }

    public function markProcessing(string $id, Context $context): void
    {
        $this->batchJobRepository->update([[
            'id' => $id,
            'status' => BatchJobStatusEnum::Processing->value,
            'startedAt' => new DateTimeImmutable(),
        ]], $context);
    }

    public function incrementProgress(
        string $id,
        int $processedItems,
        int $successCount,
        int $failureCount,
        Context $context
    ): void {
        //uses LEAST() to cap values at total_items, preventing overflow from message redelivery
        $this->connection->executeStatement(
            'UPDATE ai_batch_job
             SET processed_items = LEAST(processed_items + :processed, total_items),
                 success_count = LEAST(success_count + :success, total_items),
                 failure_count = LEAST(failure_count + :failure, total_items),
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => Uuid::fromHexToBytes($id),
                'processed' => $processedItems,
                'success' => $successCount,
                'failure' => $failureCount,
            ]
        );
    }

    public function markCompleted(string $id, Context $context): void
    {
        $this->batchJobRepository->update([[
            'id' => $id,
            'status' => BatchJobStatusEnum::Completed->value,
            'completedAt' => new DateTimeImmutable(),
        ]], $context);
    }

    public function markFailed(string $id, string $errorMessage, Context $context): void
    {
        $this->batchJobRepository->update([[
            'id' => $id,
            'status' => BatchJobStatusEnum::Failed->value,
            'errorMessage' => $errorMessage,
            'completedAt' => new DateTimeImmutable(),
        ]], $context);
    }

    public function markCancelled(string $id, Context $context): void
    {
        $this->batchJobRepository->update([[
            'id' => $id,
            'status' => BatchJobStatusEnum::Cancelled->value,
            'completedAt' => new DateTimeImmutable(),
        ]], $context);
    }

    public function isCancelled(string $id, Context $context): bool
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM ai_batch_job WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );

        return $status === BatchJobStatusEnum::Cancelled->value;
    }

    /**
     * Atomically check for an active analysis job and create a new one only if none exists.
     *
     * Uses a MySQL advisory lock to close the TOCTOU window between checking and creating,
     * preventing duplicate concurrent jobs when multiple requests arrive simultaneously.
     *
     * @return array{job: AiBatchJobEntity, analysisResultMapping: array<string, string>}|null
     *   Returns null if an active job already exists or the lock could not be acquired.
     */
    public function createAnalysisJobIfNoneActive(
        array $productIds,
        Context $context,
        ?array $metadataFilters = null
    ): ?array {
        $lockName = 'illux_ai_create_analysis_job';
        $acquired = (bool) $this->connection->fetchOne('SELECT GET_LOCK(?, 5)', [$lockName]);

        if (!$acquired) {
            // Another process holds the lock and is currently creating a job
            return null;
        }

        try {
            if ($this->getActiveAnalysisJob($context) !== null) {
                return null;
            }

            return $this->createAnalysisJob($productIds, $context, $metadataFilters);
        } finally {
            $this->connection->executeStatement('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    public function getActiveAnalysisJob(Context $context): ?AiBatchJobEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('type', BatchJobTypeEnum::Analysis->value),
            new EqualsAnyFilter('status', [
                BatchJobStatusEnum::Queued->value,
                BatchJobStatusEnum::Processing->value,
            ]),
        ]));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        return $this->batchJobRepository->search($criteria, $context)->first();
    }
}
