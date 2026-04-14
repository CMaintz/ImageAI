<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds performance indexes for common query patterns:
 * - Language filtering on analysis translations
 * - Status + date composite queries on analysis results
 * - Time-based queries on batch jobs
 * - Status filtering on pending scene images
 *
 * @internal
 */
class Migration1764500000AddPerformanceIndexes extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1764500000;
    }

    public function update(Connection $connection): void
    {
        // Index for filtering analysis translations by language (prevents N+1 when loading by language)
        $this->addIndexIfNotExists(
            $connection,
            'ai_analysis_result_translation',
            'idx.ai_analysis_result_translation.language_id',
            '(`language_id`)'
        );

        // Composite index for status + date queries (common in dashboard/listing queries)
        $this->addIndexIfNotExists(
            $connection,
            'ai_analysis_result',
            'idx.ai_analysis_result.status_created',
            '(`status`, `created_at` DESC)'
        );

        // Composite index for product lookup by status (used in ProductAnalysisService filtering)
        $this->addIndexIfNotExists(
            $connection,
            'ai_analysis_result',
            'idx.ai_analysis_result.status_product',
            '(`status`, `product_id`)'
        );

        // Index for batch job lookup by product (used in eligibility checks)
        $this->addIndexIfNotExists(
            $connection,
            'ai_analysis_result',
            'idx.ai_analysis_result.product_id',
            '(`product_id`)'
        );

        // Index for time-based batch job queries (recent jobs, historical queries)
        $this->addIndexIfNotExists(
            $connection,
            'ai_batch_job',
            'idx.ai_batch_job.created_at',
            '(`created_at` DESC)'
        );

        // Composite index for batch job status + type (used in getActiveJobs queries)
        $this->addIndexIfNotExists(
            $connection,
            'ai_batch_job',
            'idx.ai_batch_job.status_type',
            '(`status`, `type`)'
        );

        // Composite index for batch job cleanup queries (completed_at for old job removal)
        $this->addIndexIfNotExists(
            $connection,
            'ai_batch_job',
            'idx.ai_batch_job.status_completed',
            '(`status`, `completed_at`)'
        );

        // Index for status filtering on pending scene images
        $this->addIndexIfNotExists(
            $connection,
            'ai_pending_scene_image',
            'idx.ai_pending_scene_image.status',
            '(`status`)'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Indexes can be safely dropped - they don't affect data integrity
    }

    private function addIndexIfNotExists(
        Connection $connection,
        string $table,
        string $indexName,
        string $columns
    ): void {
        // Check if table exists first
        $tableExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name = ?",
            [$table]
        );

        if (!$tableExists) {
            return;
        }

        $indexExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE()
             AND table_name = ?
             AND index_name = ?",
            [$table, $indexName]
        );

        if (!$indexExists) {
            $connection->executeStatement(
                "CREATE INDEX `{$indexName}` ON `{$table}` {$columns}"
            );
        }
    }
}
