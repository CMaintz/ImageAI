<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Core\Content\AiAnalysisResult\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AiAnalysisResultEntity>
 *
 * @method void                         add(AiAnalysisResultEntity $entity)
 * @method void                         set(string $key, AiAnalysisResultEntity $entity)
 * @method AiAnalysisResultEntity[]     getIterator()
 * @method AiAnalysisResultEntity[]     getElements()
 * @method AiAnalysisResultEntity|null  get(string $key)
 * @method AiAnalysisResultEntity|null  first()
 * @method AiAnalysisResultEntity|null  last()
 */
class AiAnalysisResultCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AiAnalysisResultEntity::class;
    }
}
