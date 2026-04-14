<?php declare(strict_types=1);

namespace Illux\ImageAi\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Drops the analysis_result_mapping column from ai_batch_job table.
 * This column was redundant - the mapping is now passed through the queue message
 * instead of being stored on the batch job entity.
 */
class Migration1764700000DropAnalysisResultMapping extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1764700000;
    }

    public function update(Connection $connection): void
    {
        // Non-destructive - do nothing here
    }

    public function updateDestructive(Connection $connection): void
    {
        // Check if column exists before dropping
        $columnExists = $connection->fetchOne("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'ai_batch_job'
            AND COLUMN_NAME = 'analysis_result_mapping'
        ");

        if ($columnExists) {
            $connection->executeStatement(<<<'SQL'
ALTER TABLE `ai_batch_job` DROP COLUMN `analysis_result_mapping`;
SQL);
        }
    }
}
