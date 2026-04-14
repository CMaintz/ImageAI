<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Service\Composition;

use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;

/**
 * Selects environment scenes from media folders for composition.
 *
 * Each room type folder is expected to contain a single scene image.
 * Retrieves images from user-selected room type folders.
 */
class EnvironmentSceneSelector
{
    private const int MEDIA_LIMIT = 500;

    /**
     * @param EntityRepository<MediaCollection<MediaEntity>> $mediaRepository
     */
    public function __construct(
        private readonly EntityRepository $mediaRepository
    ) {
    }

    /**
     * Get environment images from specified folder IDs.
     *
     * Each folder is expected to contain exactly one scene image.
     * Takes the first (and only expected) image from each folder.
     *
     * @param array<array{folderId: string, name: string}> $roomFolders Array of folder data with folderId and name
     * @return array<string, MediaEntity> Map of [sceneName => MediaEntity]
     */
    public function getEnvironmentImagesByFolders(array $roomFolders, Context $context): array
    {
        $folderIds = array_column($roomFolders, 'folderId');
        $folderIdToName = array_column($roomFolders, 'name', 'folderId');

        if (empty($folderIds)) {
            return [];
        }

        $mediaByFolder = $this->fetchMediaByFolders($folderIds, $context);

        return $this->selectFromFolders($mediaByFolder, $folderIdToName);
    }

    /**
     * Fetch all media from selected folders in a single batch query
     *
     * @return array<string, MediaEntity[]> Media grouped by folder ID
     */
    private function fetchMediaByFolders(array $folderIds, Context $context): array
    {
        $mediaCriteria = new Criteria();
        $mediaCriteria->addFilter(new EqualsAnyFilter('mediaFolderId', $folderIds));
        $mediaCriteria->addAssociation('thumbnails');
        $mediaCriteria->setLimit(self::MEDIA_LIMIT);

        $allMedia = $this->mediaRepository->search($mediaCriteria, $context);

        // Group media by folder ID locally
        $mediaByFolder = [];
        foreach ($allMedia->getElements() as $media) {
            $folderId = $media->getMediaFolderId();
            if ($folderId && $media->getPath()) {
                if (!isset($mediaByFolder[$folderId])) {
                    $mediaByFolder[$folderId] = [];
                }
                $mediaByFolder[$folderId][] = $media;
            }
        }

        return $mediaByFolder;
    }

    /**
     * Select one image from each folder.
     *
     * Since each folder is expected to contain exactly one scene image,
     * this simply takes the first image found in each folder. - Could easily be
     *
     * @param array<string, MediaEntity[]> $mediaByFolder
     * @param array<string, string> $folderIdToName
     * @return array<string, MediaEntity>
     */
    private function selectFromFolders(array $mediaByFolder, array $folderIdToName): array
    {
        $environmentImages = [];

        foreach ($folderIdToName as $folderId => $sceneName) {
            if (empty($mediaByFolder[$folderId])) {
                // Empty folder - skip silently (may be expected during setup)
                continue;
            }

            $media = reset($mediaByFolder[$folderId]);

            $environmentImages[$sceneName] = $media;
        }

        return $environmentImages;
    }
}
