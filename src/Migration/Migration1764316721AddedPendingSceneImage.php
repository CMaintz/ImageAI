<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1764316721AddedPendingSceneImage extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1764316721;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement("
            CREATE TABLE IF NOT EXISTS `ai_pending_scene_image` (
                `id`                  BINARY(16)     NOT NULL,
                `scene_type`          VARCHAR(255)   NOT NULL,
                `image_data`          LONGTEXT       NOT NULL,
                `mime_type`           VARCHAR(255)   NOT NULL,
                `prompt`              LONGTEXT       NOT NULL,
                `system_instruction`  LONGTEXT       NOT NULL,
                `generation_params`   JSON           NOT NULL,
                `config`              JSON           NOT NULL,
                `status`              VARCHAR(255)   NOT NULL,
                `media_id`            BINARY(16)     NULL,
                `approved_at`         DATETIME(3)    NULL,
                `rejected_at`         DATETIME(3)    NULL,
                `created_at`          DATETIME(3)    NOT NULL,
                `updated_at`          DATETIME(3)    NULL,

                PRIMARY KEY (`id`),
                CONSTRAINT `json.pending_scene_image.generation_params` CHECK (JSON_VALID(`generation_params`)),
                CONSTRAINT `json.pending_scene_image.config` CHECK (JSON_VALID(`config`)),

                CONSTRAINT `fk.pending_scene_image.media_id`
                    FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
}
