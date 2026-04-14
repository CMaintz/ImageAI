<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

// phpcs:disable Generic.Files.LineLength.TooLong
/**
 * Adds batch_job_id foreign key to ai_analysis_result table
 * Links analysis results to their batch jobs for tracking
 */
class Migration1733200000AddBatchJobIdToAnalysisResult extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1733200000;
    }

    public function update(Connection $connection): void
    {
        // Check if table exists first
        $tableExists = $connection->fetchOne("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'ai_analysis_result'
        ");

        if (!$tableExists) {
            return;
        }

        // Check if column already exists
        $columnExists = $connection->fetchOne("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'ai_analysis_result'
            AND COLUMN_NAME = 'batch_job_id'
        ");

        if (!$columnExists) {
            // Add the batch_job_id column
            $connection->executeStatement(<<<'SQL'
ALTER TABLE `ai_analysis_result`
    ADD COLUMN `batch_job_id` BINARY(16) NULL AFTER `product_version_id`,
    ADD INDEX `idx.ai_analysis_result.batch_job_id` (`batch_job_id`);
SQL);
        }

        // Check if ai_batch_job table exists before adding foreign key
        $batchJobTableExists = $connection->fetchOne("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'ai_batch_job'
        ");

        if ($batchJobTableExists) {
            // Check if foreign key already exists
            $fkExists = $connection->fetchOne("
                SELECT COUNT(*)
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = 'ai_analysis_result'
                AND CONSTRAINT_NAME = 'fk.ai_analysis_result.batch_job'
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            ");

            if (!$fkExists) {
                // Add foreign key constraint (SET NULL on delete so results remain if job is deleted)
                $connection->executeStatement(<<<'SQL'
ALTER TABLE `ai_analysis_result`
    ADD CONSTRAINT `fk.ai_analysis_result.batch_job`
    FOREIGN KEY (`batch_job_id`)
    REFERENCES `ai_batch_job` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;
SQL);
            }
        }

        // Remove old broken index if it exists (original migration had index on non-existent column)
        try {
            $indexExists = $connection->fetchOne("
                SELECT COUNT(*)
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'ai_analysis_result'
                AND INDEX_NAME = 'idx.ai_analysis_result.batch_job_name'
            ");

            if ($indexExists) {
                $connection->executeStatement(<<<'SQL'
ALTER TABLE `ai_analysis_result` DROP INDEX `idx.ai_analysis_result.batch_job_name`;
SQL);
            }
        } catch (\Exception $e) {
            // Ignore - index might not exist
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive - column removal would lose data
    }
}
