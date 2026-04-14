<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds confidence warnings field to ai_analysis_result table
 *
 * @internal
 */
class Migration1764600000ConfidenceWarnings extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1764600000;
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

        $columnExists = $connection->fetchOne("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'ai_analysis_result'
            AND COLUMN_NAME = 'confidence_warnings'
        ");

        if (!$columnExists) {
            $connection->executeStatement(<<<'SQL'
ALTER TABLE `ai_analysis_result`
    ADD COLUMN `confidence_warnings` JSON DEFAULT NULL
    COMMENT 'Warnings explaining confidence penalties applied'
    AFTER `total_confidence_score`;
SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // Column will be dropped when parent table is dropped
    }
}
