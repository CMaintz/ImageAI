<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Core\Content\AiBatchJob;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AiBatchJobEntity>
 *
 * @method void                  add(AiBatchJobEntity $entity)
 * @method void                  set(string $key, AiBatchJobEntity $entity)
 * @method AiBatchJobEntity[]    getIterator()
 * @method AiBatchJobEntity[]    getElements()
 * @method AiBatchJobEntity|null get(string $key)
 * @method AiBatchJobEntity|null first()
 * @method AiBatchJobEntity|null last()
 */
class AiBatchJobCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AiBatchJobEntity::class;
    }
}
