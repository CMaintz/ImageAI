<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Service\Approval;

use DateTime;
use CMaintz\ImageAi\Core\Content\AiPendingSceneImage\AiPendingSceneImageCollection;
use CMaintz\ImageAi\Core\Content\AiPendingSceneImage\AiPendingSceneImageEntity;
use CMaintz\ImageAi\Service\Media\MediaFolderScanner;
use Shopware\Core\Content\Media\File\FileFetcher;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Throwable;

/**
 * Handles approval/rejection of pending scene images.
 *
 * When approved, pending images are saved to the media library.
 * When rejected, pending images are deleted from the database.
 */
class SceneImageApprovalService
{
    /**
     * @param EntityRepository<AiPendingSceneImageCollection<AiPendingSceneImageEntity>> $pendingSceneImageRepository
     * @param EntityRepository<MediaCollection<MediaEntity>> $mediaRepository
     */
    public function __construct(
        private readonly EntityRepository $pendingSceneImageRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly FileFetcher $fileFetcher,
        private readonly FileSaver $fileSaver,
        private readonly MediaFolderScanner $mediaFolderScanner
    ) {
    }

    /**
     * Approve a pending scene image and save to media library
     * @param string $pendingImageId
     * @param string|null $targetFolderId If null, auto-lookup from sceneType
     * @param Context $context
     * @return array{success: bool, mediaId: string|null, error: string|null}
     */
    public function approvePendingImage(
        string $pendingImageId,
        ?string $targetFolderId,
        Context $context
    ): array {
        try {
            $pendingImage = $this->pendingSceneImageRepository
                ->search(new Criteria([$pendingImageId]), $context)
                ->first();

            if (!$pendingImage) {
                return [
                    'success' => false,
                    'mediaId' => null,
                    'error' => 'Pending image not found',
                ];
            }

            $sceneType = $pendingImage->get('sceneType');
            if (!$targetFolderId && $sceneType) {
                $targetFolderId = $this->mediaFolderScanner->getSceneFolderId($sceneType, $context);

                if (!$targetFolderId) {
                    return [
                        'success' => false,
                        'mediaId' => null,
                        'error' => "No media folder found for scene type: '{$sceneType}'. Please ensure a subfolder " .
                            "with this exact name exists under 'AI Environment Scenes' in Media.",
                    ];
                }
            }

            if (!$targetFolderId) {
                return [
                    'success' => false,
                    'mediaId' => null,
                    'error' => 'Target folder ID is required (no sceneType available for auto-lookup)',
                ];
            }

            $imageContent = base64_decode($pendingImage->get('imageData'));
            $mimeType = $pendingImage->get('mimeType') ?? 'image/png';

            $extension = match ($mimeType) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/webp' => 'webp',
                default => 'png',
            };

            $filename = sprintf(
                'scene_%s_%s',
                str_replace(' ', '_', strtolower($sceneType)),
                Uuid::randomHex()
            );

            $mediaId = Uuid::randomHex();
            $this->mediaRepository->create([
                [
                    'id' => $mediaId,
                    'mediaFolderId' => $targetFolderId,
                    'private' => false,
                ]
            ], $context);

            $mediaFile = $this->fileFetcher->fetchBlob($imageContent, $extension, $mimeType);
            $this->fileSaver->persistFileToMedia($mediaFile, $filename, $mediaId, $context);
            $this->fileFetcher->cleanUpTempFile($mediaFile);

            $this->pendingSceneImageRepository->update([
                [
                    'id' => $pendingImageId,
                    'status' => 'approved',
                    'mediaId' => $mediaId,
                    'approvedAt' => new DateTime(),
                ]
            ], $context);

            return [
                'success' => true,
                'mediaId' => $mediaId,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'mediaId' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reject a pending scene image (deletes from database)
     * @param string $pendingImageId
     * @param Context $context
     * @return bool Success status
     */
    public function rejectPendingImage(string $pendingImageId, Context $context): bool
    {
        try {
            // Delete the rejected image from database instead of just marking it
            //TODO figure out the ideal flow; could keep the entity in DB, but simply deleting the sizable image data?
            $this->pendingSceneImageRepository->delete([
                ['id' => $pendingImageId]
            ], $context);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Batch approve multiple pending scene images
     * @param array $approvals Array of ['pendingImageId' => string, 'targetFolderId' => string]
     * @param Context $context
     * @return array{successCount: int, failureCount: int, results: array}
     */
    public function batchApprovePendingImages(array $approvals, Context $context): array
    {
        $successCount = 0;
        $failureCount = 0;
        $results = [];

        foreach ($approvals as $approval) {
            $pendingImageId = $approval['pendingImageId'] ?? null;
            $targetFolderId = $approval['targetFolderId'] ?? null;

            if (!$pendingImageId) {
                $failureCount++;
                $results[] = [
                    'pendingImageId' => $pendingImageId,
                    'success' => false,
                    'error' => 'Missing pendingImageId',
                ];
                continue;
            }

            $result = $this->approvePendingImage($pendingImageId, $targetFolderId, $context);
            $results[] = [
                'pendingImageId' => $pendingImageId,
                'success' => $result['success'],
                'mediaId' => $result['mediaId'] ?? null,
                'error' => $result['error'] ?? null,
            ];

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        return [
            'successCount' => $successCount,
            'failureCount' => $failureCount,
            'results' => $results,
        ];
    }

    /**
     * Batch reject multiple pending scene images (deletes from database)
     * @param array $pendingImageIds Array of pending image IDs to reject
     * @param Context $context
     * @return array{rejectedCount: int}
     */
    public function batchRejectPendingImages(array $pendingImageIds, Context $context): array
    {
        if (empty($pendingImageIds)) {
            return ['rejectedCount' => 0];
        }

        $deletes = [];
        foreach ($pendingImageIds as $id) {
            $deletes[] = ['id' => $id];
        }

        try {
            $this->pendingSceneImageRepository->delete($deletes, $context);

            return ['rejectedCount' => count($deletes)];
        } catch (Throwable) {
            return ['rejectedCount' => 0];
        }
    }
}
