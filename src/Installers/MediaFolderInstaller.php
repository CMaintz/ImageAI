<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Installers;

use CMaintz\ImageAi\Config\PluginConstants;
use RuntimeException;
use Shopware\Core\Content\Media\Aggregate\MediaDefaultFolder\MediaDefaultFolderCollection;
use Shopware\Core\Content\Media\Aggregate\MediaDefaultFolder\MediaDefaultFolderEntity;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderEntity;
use Shopware\Core\Content\Media\Aggregate\MediaFolderConfiguration\MediaFolderConfigurationCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Throwable;

/**
 * Creates media folder structure for AI-generated environment scenes
 */
class MediaFolderInstaller
{
    private const array SCENE_FOLDERS = [
        'Commercial Office',
        'Lobby',
        'Kitchen',
        'Home Office',
        'Cafeteria',
        'Restaurant',
        'Living Room',
        'Bedroom',
        'Dining Room',
        'Hallway',
        'Reading Nook',
        'Loft',
        'Café',
    ];

    /**
     * @param EntityRepository<MediaFolderCollection> $mediaFolderRepository
     * @param EntityRepository<MediaDefaultFolderCollection> $mediaDefaultFolderRepository
     * @param EntityRepository<MediaFolderConfigurationCollection> $mediaFolderConfigurationRepository
     */
    public function __construct(
        private readonly EntityRepository $mediaFolderRepository,
        private readonly EntityRepository $mediaDefaultFolderRepository,
        private readonly EntityRepository $mediaFolderConfigurationRepository,
    ) {
    }

    /**
     * Install media folder structure for environment scenes
     */
    public function install(Context $context): void
    {
        try {
            // Get or create default media folder entity for AI scenes
            $defaultFolderId = $this->getOrCreateDefaultFolder($context);
            error_log('[MediaFolderInstaller] Got default folder ID: ' . $defaultFolderId);

            // Create parent media folder
            $parentFolderId = $this->getOrCreateParentFolder($defaultFolderId, $context);
            error_log('[MediaFolderInstaller] Got parent folder ID: ' . $parentFolderId);

            if (!$parentFolderId) {
                throw new RuntimeException('Failed to create or get parent folder ID');
            }

            // Verify parent folder actually exists in database before creating children
            $criteria = new Criteria([$parentFolderId]);
            $criteria->addAssociation('configuration');
            /** @var MediaFolderEntity|null $parentFolder */
            $parentFolder = $this->mediaFolderRepository->search($criteria, $context)->first();

            if ($parentFolder === null) {
                throw new RuntimeException(sprintf('Parent folder with ID %s not found in database', $parentFolderId));
            }

            $parentConfigId = $parentFolder->getConfigurationId();
            if (!$parentConfigId) {
                throw new RuntimeException(sprintf('Parent folder %s has no configuration ID', $parentFolderId));
            }

            error_log('[MediaFolderInstaller] Verified parent folder exists: ' . $parentFolderId .
                ' (name: ' . ($parentFolder->getName() ?? 'Unknown') . ', configId: ' . $parentConfigId . ')');

            // Create scene subfolders
            $createdCount = 0;
            foreach (self::SCENE_FOLDERS as $sceneName) {
                try {
                    $subfolderId = $this->getOrCreateSceneFolder(
                        $sceneName,
                        $parentFolderId,
                        $parentConfigId,
                        $context
                    );
                    error_log('[MediaFolderInstaller] Created/got subfolder "' . $sceneName .
                        '" with ID: ' . $subfolderId);
                    $createdCount++;
                } catch (Throwable $e) {
                    // Log error but continue with other folders
                    error_log('[MediaFolderInstaller] Failed to create scene folder "' . $sceneName .
                        '": ' . $e->getMessage());
                }
            }
            error_log('[MediaFolderInstaller] Created/verified ' . $createdCount . ' of ' .
                count(self::SCENE_FOLDERS) . ' subfolders');

            // Final verification: check how many subfolders actually exist
            $verifyCriteria = new Criteria();
            $verifyCriteria->addFilter(new EqualsFilter('parentId', $parentFolderId));
            $actualSubfolders = $this->mediaFolderRepository->search($verifyCriteria, $context);
            error_log('[MediaFolderInstaller] Database verification shows ' . $actualSubfolders->count() .
                ' subfolders under parent');

            /** @var MediaFolderEntity $subfolder */
            foreach ($actualSubfolders as $subfolder) {
                error_log('[MediaFolderInstaller]   - Subfolder in DB: ' . ($subfolder->getName() ?? 'Unknown') .
                    ' (ID: ' . $subfolder->getId() . ')');
            }
        } catch (Throwable $e) {
            error_log('[MediaFolderInstaller] error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Uninstall media folders (optional - removes folders if keepUserData is false)
     */
    public function uninstall(Context $context): void
    {
        // Find parent folder
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', PluginConstants::SCENE_PARENT_FOLDER_NAME));
        $criteria->addAssociation('children');

        /** @var MediaFolderEntity|null $parentFolder */
        $parentFolder = $this->mediaFolderRepository->search($criteria, $context)->first();

        if ($parentFolder !== null) {
            // Delete parent folder (will cascade delete children)
            $this->mediaFolderRepository->delete([['id' => $parentFolder->getId()]], $context);
        }

        // Delete default folder
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('entity', PluginConstants::SCENE_FOLDER_TECHNICAL_NAME));
        /** @var MediaDefaultFolderEntity|null $defaultFolder */
        $defaultFolder = $this->mediaDefaultFolderRepository->search($criteria, $context)->first();

        if ($defaultFolder !== null) {
            $this->mediaDefaultFolderRepository->delete([['id' => $defaultFolder->getId()]], $context);
        }
    }

    private function getOrCreateDefaultFolder(Context $context): string
    {
        // Check if default folder already exists
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('entity', PluginConstants::SCENE_FOLDER_TECHNICAL_NAME));

        /** @var MediaDefaultFolderEntity|null $existingDefaultFolder */
        $existingDefaultFolder = $this->mediaDefaultFolderRepository->search($criteria, $context)->first();

        if ($existingDefaultFolder) {
            return $existingDefaultFolder->getId();
        }

        // Create new default folder
        $defaultFolderId = Uuid::randomHex();
        $this->mediaDefaultFolderRepository->create([
            [
                'id' => $defaultFolderId,
                'entity' => PluginConstants::SCENE_FOLDER_TECHNICAL_NAME,
                'associationFields' => [],
            ],
        ], $context);

        return $defaultFolderId;
    }

    private function getOrCreateParentFolder(string $defaultFolderId, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', PluginConstants::SCENE_PARENT_FOLDER_NAME));
        $criteria->addAssociation('configuration');

        /** @var MediaFolderEntity|null $existingFolder */
        $existingFolder = $this->mediaFolderRepository->search($criteria, $context)->first();

        if ($existingFolder !== null) {
            $configId = $existingFolder->getConfigurationId();

            // If missing we new create config + update folder
            if (!$configId) {
                $configId = $this->createFolderConfiguration($context);

                $this->mediaFolderRepository->update([
                    [
                        'id' => $existingFolder->getId(),
                        'useParentConfiguration' => false,
                        'configurationId' => $configId,
                    ]
                ], $context);
            }

            return $existingFolder->getId();
        }
        // Create folder fresh
        $configurationId = $this->createFolderConfiguration($context);
        $parentFolderId = Uuid::randomHex();

        $this->mediaFolderRepository->create([
            [
                'id' => $parentFolderId,
                'name' => PluginConstants::SCENE_PARENT_FOLDER_NAME,
                'useParentConfiguration' => false,
                'configurationId' => $configurationId,
                'defaultFolderId' => $defaultFolderId,
            ],
        ], $context);

        return $parentFolderId;
    }

    private function createFolderConfiguration(Context $context): string
    {
        $configId = Uuid::randomHex();

        $this->mediaFolderConfigurationRepository->create([
            [
                'id' => $configId,
                'createThumbnails' => true,
                'keepAspectRatio' => true,
                'thumbnailQuality' => 90,
                'private' => false,
            ]
        ], $context);

        return $configId;
    }

    private function getOrCreateSceneFolder(
        string $sceneName,
        string $parentFolderId,
        string $configurationId,
        Context $context
    ): string {
        error_log('[MediaFolderInstaller] getOrCreateSceneFolder called for "'
            . $sceneName . '" with parent ID: ' . $parentFolderId . ', config ID: ' . $configurationId);

        // Check if scene folder already exists
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $sceneName));
        $criteria->addFilter(new EqualsFilter('parentId', $parentFolderId));

        /** @var MediaFolderEntity|null $existingFolder */
        $existingFolder = $this->mediaFolderRepository->search($criteria, $context)->first();

        if ($existingFolder !== null) {
            error_log('[MediaFolderInstaller] Subfolder "' . $sceneName . '" already exists with ID: '
                . $existingFolder->getId());
            return $existingFolder->getId();
        }

        error_log('[MediaFolderInstaller] Creating new subfolder "' . $sceneName . '" with parent ID: '
            . $parentFolderId . ' and config ID: ' . $configurationId);

        // Create scene subfolder
        $sceneFolderId = Uuid::randomHex();

        try {
            $this->mediaFolderRepository->create([
                [
                    'id' => $sceneFolderId,
                    'name' => $sceneName,
                    'parentId' => $parentFolderId,
                    'useParentConfiguration' => true,
                    'configurationId' => $configurationId,
                ],
            ], $context);

            error_log('[MediaFolderInstaller] Successfully created subfolder "'
                . $sceneName . '" with ID: ' . $sceneFolderId);
        } catch (Throwable $e) {
            error_log('[MediaFolderInstaller] Error creating subfolder "' . $sceneName . '": ' . $e->getMessage());
            throw $e;
        }

        return $sceneFolderId;
    }
}
