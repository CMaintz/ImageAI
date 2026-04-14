<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Core\Content\AiPendingSceneImage;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AiPendingSceneImageEntity>
 */
class AiPendingSceneImageCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AiPendingSceneImageEntity::class;
    }
}
