<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Core\Content\AiSceneGenerationConfig;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AiSceneGenerationConfigEntity>
 *
 * @method void                                 add(AiSceneGenerationConfigEntity $entity)
 * @method void                                 set(string $key, AiSceneGenerationConfigEntity $entity)
 * @method AiSceneGenerationConfigEntity[]      getIterator()
 * @method AiSceneGenerationConfigEntity[]      getElements()
 * @method AiSceneGenerationConfigEntity|null   get(string $key)
 * @method AiSceneGenerationConfigEntity|null   first()
 * @method AiSceneGenerationConfigEntity|null   last()
 */
class AiSceneGenerationConfigCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AiSceneGenerationConfigEntity::class;
    }
}
