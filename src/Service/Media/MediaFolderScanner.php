<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Media;

use Illux\ImageAi\Config\PluginConstants;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Service for scanning media folders to discover available scene types
 * Looks for subfolders under "AI Environment Scenes" media folder.
 * Each subfolder represents a scene type (e.g., "Living Room", "Bedroom", "Kitchen").
 */
class MediaFolderScanner
{
    /**
     * @param EntityRepository<MediaFolderCollection> $mediaFolderRepository
     */
    public function __construct(
        private readonly EntityRepository $mediaFolderRepository
    ) {
    }

    /**
     * Get all available scene types from media folder structure
     *
     * Returns empty array if parent folder doesn't exist (not an error - folder not created yet).
     * Throws on actual database/query errors.
     *
     * @param Context $context
     * @return array<string, array{id: string, name: string, path: string}> Scene type data indexed by name
     */
    public function getAvailableSceneTypes(Context $context): array
    {
        $parentFolder = $this->findParentSceneFolder($context);

        if (!$parentFolder) {
            // Parent folder doesn't exist yet - valid state, not an error
            return [];
        }

        $subfolders = $this->getSubfolders($parentFolder->getId(), $context);

        $sceneTypes = [];
        foreach ($subfolders as $folder) {
            $name = $folder->getName();
            $sceneTypes[$name] = [
                'id' => $folder->getId(),
                'name' => $name,
                'path' => $this->getFolderPath($folder),
            ];
        }

        return $sceneTypes;
    }

    /**
     * Get media folder ID for a specific scene type
     * Auto-creates the folder if it doesn't exist.
     * @param string $sceneTypeName Name of the scene type
     * @param Context $context
     * @return string|null Folder ID or null if parent folder doesn't exist
     */
    public function getSceneFolderId(string $sceneTypeName, Context $context): ?string
    {
        $sceneTypes = $this->getAvailableSceneTypes($context);

        if (isset($sceneTypes[$sceneTypeName])) {
            return $sceneTypes[$sceneTypeName]['id'];
        }

        $lowerName = mb_strtolower($sceneTypeName);
        foreach ($sceneTypes as $name => $data) {
            if (mb_strtolower($name) === $lowerName) {
                return $data['id'];
            }
        }

        return $this->ensureSceneFolderExists($sceneTypeName, $context);
    }

    /**
     * Find the parent "AI Environment Scenes" folder
     * @param Context $context
     * @return MediaFolderEntity|null
     */
    private function findParentSceneFolder(Context $context): ?MediaFolderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', PluginConstants::SCENE_PARENT_FOLDER_NAME));
        $criteria->addAssociation('childCount');

        /** @var MediaFolderEntity|null $folder */
        $folder = $this->mediaFolderRepository->search($criteria, $context)->first();

        return $folder;
    }

    /**
     * Get all subfolders of a given folder
     * @param string $parentFolderId
     * @param Context $context
     * @return MediaFolderCollection
     */
    private function getSubfolders(string $parentFolderId, Context $context): MediaFolderCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $parentFolderId));
        $criteria->addAssociation('parent');

        /** @var MediaFolderCollection $folders */
        $folders = $this->mediaFolderRepository->search($criteria, $context)->getEntities();

        return $folders;
    }

    /**
     * Get the full path of a media folder
     * @param MediaFolderEntity $folder
     * @return string
     */
    private function getFolderPath(MediaFolderEntity $folder): string
    {
        $path = [$folder->getName()];

        $parent = $folder->getParent();
        while ($parent !== null) {
            array_unshift($path, $parent->getName());
            $parent = $parent->getParent();
        }

        return implode(' / ', $path);
    }

    /**
     * Check if the parent scene folder exists
     * @param Context $context
     * @return bool
     */
    public function parentSceneFolderExists(Context $context): bool
    {
        return $this->findParentSceneFolder($context) !== null;
    }

    /**
     * Create the parent scene folder if it doesn't exist
     * @param Context $context
     * @return string Folder ID
     */
    public function ensureParentSceneFolderExists(Context $context): string
    {
        $existingFolder = $this->findParentSceneFolder($context);

        if ($existingFolder) {
            return $existingFolder->getId();
        }

        $folderId = Uuid::randomHex();

        $this->mediaFolderRepository->create([
            [
                'id' => $folderId,
                'name' => PluginConstants::SCENE_PARENT_FOLDER_NAME,
                'useParentConfiguration' => false,
                'configuration' => [
                    'createThumbnails' => true,
                    'keepAspectRatio' => true,
                    'thumbnailQuality' => 90,
                ],
            ]
        ], $context);
        return $folderId;
    }

    /**
     * Create a scene subfolder if it doesn't exist
     * @param string $sceneName Name of the scene type (e.g., "Living Room")
     * @param Context $context
     * @return string|null Folder ID, or null if parent folder doesn't exist
     */
    public function ensureSceneFolderExists(string $sceneName, Context $context): ?string
    {
        $parentFolder = $this->findParentSceneFolder($context);

        if (!$parentFolder) {
            // Parent folder doesn't exist - caller should handle this
            return null;
        }

        $existingTypes = $this->getAvailableSceneTypes($context);
        if (isset($existingTypes[$sceneName])) {
            return $existingTypes[$sceneName]['id'];
        }

        $folderId = Uuid::randomHex();

        $this->mediaFolderRepository->create([
            [
                'id' => $folderId,
                'name' => $sceneName,
                'parentId' => $parentFolder->getId(),
                'useParentConfiguration' => true,
                'configurationId' => $parentFolder->getConfigurationId(),
            ]
        ], $context);

        return $folderId;
    }

    /**
     * Sync scene folders with scene type options
     * Creates folders for any scene types that don't have folders yet.
     * Does NOT delete folders (to preserve existing images).
     * @param array $sceneTypeOptions Array of [{label: string, description: string}, ...]
     * @param Context $context
     * @return array Created folder names
     */
    public function syncSceneFolders(array $sceneTypeOptions, Context $context): array
    {
        $existingTypes = $this->getAvailableSceneTypes($context);
        $created = [];

        foreach ($sceneTypeOptions as $sceneType) {
            $label = $sceneType['label'] ?? '';
            if (empty($label)) {
                continue;
            }

            if (!isset($existingTypes[$label])) {
                $folderId = $this->ensureSceneFolderExists($label, $context);
                if ($folderId) {
                    $created[] = $label;
                }
            }
        }

        return $created;
    }
}
