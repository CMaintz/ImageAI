<?php declare(strict_types=1);

namespace Illux\ImageAi\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

// phpcs:disable Generic.Files.LineLength.TooLong
/**
 * @internal
 */
class Migration1733100000BatchJob extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1733100000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement("
            CREATE TABLE IF NOT EXISTS `ai_batch_job` (
                `id`                       BINARY(16)     NOT NULL,
                `type`                     VARCHAR(255)   NOT NULL,
                `status`                   VARCHAR(255)   NOT NULL,
                `total_items`              INT            NOT NULL DEFAULT 0,
                `processed_items`          INT            NOT NULL DEFAULT 0,
                `success_count`            INT            NOT NULL DEFAULT 0,
                `failure_count`            INT            NOT NULL DEFAULT 0,
                `product_ids`              JSON           NULL,
                `config`                   JSON           NULL,
                `metadata_filters`         JSON           NULL,
                `analysis_result_mapping`  JSON           NULL,
                `error_message`            LONGTEXT       NULL,
                `started_at`               DATETIME(3)    NULL,
                `completed_at`             DATETIME(3)    NULL,
                `created_at`               DATETIME(3)    NOT NULL,
                `updated_at`               DATETIME(3)    NULL,

                PRIMARY KEY (`id`),
                INDEX `idx.batch_job.status` (`status`),
                INDEX `idx.batch_job.type` (`type`),
                CONSTRAINT `json.batch_job.product_ids` CHECK (JSON_VALID(`product_ids`) OR `product_ids` IS NULL),
                CONSTRAINT `json.batch_job.config` CHECK (JSON_VALID(`config`) OR `config` IS NULL),
                CONSTRAINT `json.batch_job.metadata_filters` CHECK (JSON_VALID(`metadata_filters`) OR `metadata_filters` IS NULL),
                CONSTRAINT `json.batch_job.analysis_result_mapping` CHECK (JSON_VALID(`analysis_result_mapping`) OR `analysis_result_mapping` IS NULL)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->addColumnIfNotExists($connection, 'ai_batch_job', 'analysis_result_mapping', 'JSON NULL AFTER `metadata_filters`');
    }

    private function addColumnIfNotExists(Connection $connection, string $table, string $column, string $definition): void
    {
        $columnExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        );

        if (!$columnExists) {
            $connection->executeStatement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}
