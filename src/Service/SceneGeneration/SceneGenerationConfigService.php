<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Service\SceneGeneration;

use CMaintz\ImageAi\Core\Content\AiSceneGenerationConfig\AiSceneGenerationConfigCollection;
use CMaintz\ImageAi\Core\Content\AiSceneGenerationConfig\AiSceneGenerationConfigEntity;
use CMaintz\ImageAi\Service\Media\MediaFolderScanner;
use RuntimeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Service for managing scene generation configuration
 *
 * This handles the singleton SceneGenerationConfig entity that stores
 * all available options for scene generation.
 *
 * When scene types are added/removed, corresponding media folders are
 * automatically created/kept (folders are never deleted to preserve images).
 */
class SceneGenerationConfigService
{
    /**
     * @param EntityRepository<AiSceneGenerationConfigCollection> $sceneConfigRepository
     */
    public function __construct(
        private readonly EntityRepository $sceneConfigRepository,
        private readonly MediaFolderScanner $mediaFolderScanner,
    ) {
    }

    public function getConfig(Context $context): ?AiSceneGenerationConfigEntity
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        /** @var AiSceneGenerationConfigEntity|null $config */
        $config = $this->sceneConfigRepository->search($criteria, $context)->first();

        return $config;
    }

    public function ensureConfigExists(Context $context): AiSceneGenerationConfigEntity
    {
        $config = $this->getConfig($context);
        if (!$config) {
            throw new RuntimeException('Failed to create scene generation config');
        }
        return $config;
    }

    private const array ALLOWED_CONFIG_FIELDS = [
        'cameraLensOptions',
        'perspectiveOptions',
        'cameraAngleOptions',
        'interiorStyleOptions',
        'lightingOptions',
        'styleOptions',
        'stylingOptions',
        'aspectRatioOptions',
        'moodOptions',
        'colorPaletteOptions',
        'compositionOptions',
        'sceneTypeOptions',
    ];

    public function updateConfig(array $data, Context $context): void
    {
        $config = $this->ensureConfigExists($context);

        $updateData = ['id' => $config->id];

        foreach (self::ALLOWED_CONFIG_FIELDS as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (isset($data['sceneTypeOptions'])) {
            $this->mediaFolderScanner->syncSceneFolders($data['sceneTypeOptions'], $context);
        }

        $this->sceneConfigRepository->update([$updateData], $context);
    }
}
