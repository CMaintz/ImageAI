<?php declare(strict_types=1);

namespace Illux\ImageAi\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1733000000AiAnalysis extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1733000000;
    }

    public function update(Connection $connection): void
    {
        // Create main analysis result table
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ai_analysis_result` (
    `id` BINARY(16) NOT NULL,
    `product_id` BINARY(16) NOT NULL,
    `product_version_id` BINARY(16) NOT NULL,
    `status` VARCHAR(255) NOT NULL,
    `total_confidence_score` NUMERIC(10,2) DEFAULT NULL,
    `suggested_property_option_candidates` JSON DEFAULT NULL COMMENT 'New PropertyOptions suggested by AI',
    `analyzed_properties` JSON DEFAULT NULL COMMENT 'Property Options to apply to the product',
    `error_message` TEXT DEFAULT NULL COMMENT 'Error message when analysis fails',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx.ai_analysis_result.product_id` (`product_id`, `product_version_id`),
    KEY `idx.ai_analysis_result.status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // Add foreign key constraint only if it doesn't exist
        $fkExists = $connection->fetchOne("
            SELECT COUNT(*)
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME = 'ai_analysis_result'
            AND CONSTRAINT_NAME = 'fk.ai_analysis_result.product'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ");

        if (!$fkExists) {
            $connection->executeStatement(<<<'SQL'
ALTER TABLE `ai_analysis_result`
    ADD CONSTRAINT `fk.ai_analysis_result.product`
    FOREIGN KEY (`product_id`, `product_version_id`)
    REFERENCES `product` (`id`, `version_id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE;
SQL);
        }

        // Create translation table for multi-language analysis data
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ai_analysis_result_translation` (
    `ai_analysis_result_id` BINARY(16) NOT NULL,
    `language_id` BINARY(16) NOT NULL,
    `meta_title` VARCHAR(255) NULL COMMENT 'AI-generated meta title for SEO',
    `meta_description` TEXT NULL COMMENT 'AI-generated meta description for SEO',
    `seo_keywords` TEXT NULL COMMENT 'AI-generated SEO keywords (comma-separated)',
    `product_description` TEXT NULL COMMENT 'AI-generated product description',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`ai_analysis_result_id`, `language_id`),
    CONSTRAINT `fk.ai_analysis_result_translation.ai_analysis_result_id`
        FOREIGN KEY (`ai_analysis_result_id`)
        REFERENCES `ai_analysis_result` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.ai_analysis_result_translation.language_id`
        FOREIGN KEY (`language_id`)
        REFERENCES `language` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Drop tables on uninstall
        $connection->executeStatement('DROP TABLE IF EXISTS `ai_analysis_result_translation`');
        $connection->executeStatement('DROP TABLE IF EXISTS `ai_analysis_result`');
    }
}
