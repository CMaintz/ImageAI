<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Service\Frame;

use CMaintz\ImageAi\Service\Media\MediaFileReader;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Throwable;
use Wexo\ProductComponents\Core\Content\ComponentOption\ComponentOptionCollection;
use Wexo\ProductComponents\Core\Content\ComponentOption\ComponentOptionEntity;
use Wexo\ProductComponents\Core\Content\ComponentType\ComponentTypeCollection;
use Wexo\ProductComponents\Core\Content\ComponentType\ComponentTypeEntity;
use Wexo\ProductComponents\Model\FrameImageTypeEnum;

/**
 * Resolves frame corner images from ComponentOption entities.
 *
 * Given a frame name (from product options), finds the matching ComponentOption
 * and returns its corner image (typically TopLeftCorner) for use as a visual
 * reference in AI composition prompts.
 */
class FrameCornerImageResolver
{
    // Frame image resolution: Uses ComponentOption ID directly from frontend
    // (frontend sets data-component-option-global-id on option elements)

    /**
     * @param EntityRepository<ComponentOptionCollection> $componentOptionRepository
     * @param EntityRepository<ComponentTypeCollection> $componentTypeRepository
     */
    public function __construct(
        private readonly EntityRepository $componentOptionRepository,
        private readonly EntityRepository $componentTypeRepository,
        private readonly MediaFileReader $mediaFileReader,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Resolve corner image media for a frame by its label/name
     *
     * @param string $frameName The frame option label (e.g., "Mat Sort Eg")
     * @param Context $context
     * @return MediaEntity|null The corner image media, or null if not found
     */
    public function resolveCornerImage(string $frameName, Context $context): ?MediaEntity
    {
        $frameName = trim($frameName);
        if ($frameName === '' || $this->isNoFrameOption($frameName)) {
            return null;
        }

        $componentOption = $this->findComponentOption($frameName, $context);

        if (!$componentOption) {
            $this->logger->info('[FrameResolver] Name-based lookup failed - ComponentOption not found', [
                'frameName' => $frameName,
            ]);
            return null;
        }

        $this->logger->info('[FrameResolver] Name-based lookup succeeded', [
            'frameName' => $frameName,
            'componentOptionId' => $componentOption->getId(),
        ]);

        return $this->getCornerImage($componentOption);
    }

    /**
     * Resolve corner image media for a frame by ComponentOption ID
     *
     * @param string $componentOptionId The ComponentOption ID
     * @param Context $context
     * @return MediaEntity|null The corner image media, or null if not found
     */
    public function resolveCornerImageById(string $componentOptionId, Context $context): ?MediaEntity
    {
        $criteria = new Criteria([$componentOptionId]);
        $criteria->addAssociation('frameImages.media.thumbnails');

        /** @var ComponentOptionEntity|null $componentOption */
        $componentOption = $this->componentOptionRepository->search($criteria, $context)->first();

        if (!$componentOption) {
            $this->logger->info('[FrameResolver] ComponentOption not found by ID', ['id' => $componentOptionId]);
            return null;
        }

        return $this->getCornerImage($componentOption);
    }

    /**
     * Find a ComponentOption by its name/label (checking internal name and translations)
     */
    private function findComponentOption(string $frameName, Context $context): ?ComponentOptionEntity
    {
        // Try exact match on internal name first
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('internalName', $frameName));
        $criteria->addAssociation('frameImages.media.thumbnails');
        $criteria->setLimit(1);

        /** @var ComponentOptionEntity|null $result */
        $result = $this->componentOptionRepository->search($criteria, $context)->first();

        if ($result) {
            return $result;
        }

        // Try searching in translations (publicName)
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('translations.publicName', $frameName));
        $criteria->addAssociation('frameImages.media.thumbnails');
        $criteria->setLimit(1);

        /** @var ComponentOptionEntity|null $result */
        $result = $this->componentOptionRepository->search($criteria, $context)->first();

        if ($result) {
            return $result;
        }

        // Try case-insensitive partial match as fallback
        $criteria = new Criteria();
        $criteria->addFilter(
            new MultiFilter(MultiFilter::CONNECTION_OR, [
                new ContainsFilter('internalName', $frameName),
                new ContainsFilter('translations.publicName', $frameName),
            ])
        );
        $criteria->addAssociation('frameImages.media.thumbnails');
        $criteria->setLimit(1);

        $this->logger->debug('Trying partial match for frame', ['frameName' => $frameName]);

        return $this->componentOptionRepository->search($criteria, $context)->first();
    }

    /**
     * Find ComponentOption by navigating from PropertyGroupOption ID through ComponentType.
     *
     * Relationship chain:
     * PropertyGroupOption.id → ComponentType.propertyGroupOptionId → ComponentType.options → ComponentOption
     */
    private function findComponentOptionByPropertyGroupOptionId(
        string $propertyGroupOptionId,
        Context $context
    ): ?ComponentOptionEntity {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('propertyGroupOptionId', $propertyGroupOptionId));
        $criteria->addAssociation('options.frameImages.media.thumbnails');
        $criteria->setLimit(1);

        /** @var ComponentTypeEntity|null $componentType */
        $componentType = $this->componentTypeRepository->search($criteria, $context)->first();

        if (!$componentType) {
            $this->logger->info('[FrameResolver] No ComponentType found for PropertyGroupOption ID', [
                'propertyGroupOptionId' => $propertyGroupOptionId,
            ]);
            return null;
        }

        $options = $componentType->getOptions();
        if (!$options || $options->count() === 0) {
            $this->logger->info('[FrameResolver] ComponentType has no ComponentOptions', [
                'componentTypeId' => $componentType->getId(),
                'propertyGroupOptionId' => $propertyGroupOptionId,
            ]);
            return null;
        }

        // Return the first option that has frame images
        foreach ($options->getElements() as $option) {
            $frameImages = $option->getFrameImages();
            if ($frameImages && $frameImages->count() > 0) {
                $this->logger->info('[FrameResolver] Found ComponentOption with frame images via ComponentType', [
                    'componentTypeId' => $componentType->getId(),
                    'componentOptionId' => $option->getId(),
                    'frameImageCount' => $frameImages->count(),
                ]);
                return $option;
            }
        }

        // If no option has frame images, return null (don't return options without frame images)
        $this->logger->info('[FrameResolver] No ComponentOption with frame images found', [
            'componentTypeId' => $componentType->getId(),
            'optionCount' => $options->count(),
        ]);

        return null;
    }

    /**
     * Extract the corner image from a ComponentOption's frameImages
     */
    private function getCornerImage(ComponentOptionEntity $componentOption): ?MediaEntity
    {
        $frameImages = $componentOption->getFrameImages();

        if (!$frameImages || $frameImages->count() === 0) {
            $this->logger->info('[FrameResolver] No frame images found for ComponentOption', [
                'id' => $componentOption->getId(),
                'internalName' => $componentOption->getInternalName(),
            ]);
            return null;
        }

        // Prefer top-left corner image, but accept any frame image
        $fallbackMedia = null;
        foreach ($frameImages->getElements() as $frameImage) {
            $media = $frameImage->getMedia();
            if (!$media) {
                continue;
            }

            if ($frameImage->getImageType() === FrameImageTypeEnum::TopLeftCorner->value) {
                $this->logger->info('[FrameResolver] Found preferred corner image', [
                    'componentOptionId' => $componentOption->getId(),
                    'mediaId' => $media->getId(),
                    'fileName' => $media->getFileName(),
                    'imageType' => $frameImage->getImageType(),
                ]);
                return $media;
            }

            if ($fallbackMedia === null) {
                $fallbackMedia = $media;
            }
        }

        if ($fallbackMedia !== null) {
            $this->logger->info('[FrameResolver] Using fallback frame image (not corner)', [
                'componentOptionId' => $componentOption->getId(),
                'mediaId' => $fallbackMedia->getId(),
                'fileName' => $fallbackMedia->getFileName(),
            ]);
            return $fallbackMedia;
        }

        $this->logger->info('[FrameResolver] No usable frame image found', [
            'componentOptionId' => $componentOption->getId(),
            'availableTypes' => array_map(
                fn($img) => $img->getImageType(),
                $frameImages->getElements()
            ),
        ]);

        return null;
    }

    /**
     * Check if the frame name indicates "no frame" option
     */
    private function isNoFrameOption(string $frameName): bool
    {
        $lower = strtolower($frameName);

        return str_contains($lower, 'uden')
            || str_contains($lower, 'ingen')
            || str_contains($lower, 'frameless')
            || str_contains($lower, 'none')
            || str_contains($lower, 'no frame');
    }

    /**
     * Resolve comprehensive frame data from product composition options.
     *
     * Searches options for a frame-related group and resolves frame images and dimensions.
     * Returns base64-encoded image data and frame measurements ready for API use.
     *
     * @param array<string, mixed> $options Product options from composition request
     * @param Context $context
     * @return array{
     *     frameCornerImageBase64: string|null,
     *     frameEdgeImageBase64: string|null,
     *     frameMimeType: string|null,
     *     frameSpanCm: float|null,
     *     frameOverlapCm: float|null,
     *     frameName: string|null
     * }
     */
    public function resolveFrameDataFromOptions(array $options, Context $context): array
    {
        $emptyResult = [
            'frameCornerImageBase64' => null,
            'frameEdgeImageBase64' => null,
            'frameMimeType' => null,
            'frameSpanCm' => null,
            'frameOverlapCm' => null,
            'frameName' => null,
        ];

        $optionSummary = [];
        foreach ($options as $key => $opt) {
            $optionSummary[$key] = [
                'optionId' => $opt['optionId'] ?? 'missing',
                'groupId' => $opt['groupId'] ?? 'missing',
                'optionLabel' => $opt['optionLabel'] ?? 'missing',
                'groupLabel' => $opt['groupLabel'] ?? 'missing',
            ];
        }
        $this->logger->info('[FrameResolver] Searching for frame option in composition options', [
            'optionCount' => count($options),
            'fullOptions' => $optionSummary,
        ]);

        // Find frame option in the options array
        $frameOptionId = null;
        $frameName = null;
        $frameGroupLabel = null;

        foreach ($options as $groupLabel => $option) {
            $lowerGroup = strtolower($groupLabel);
            $optionLabel = strtolower($option['optionLabel'] ?? '');

            // Check isFramingComponent flag from frontend (data-framing-component attribute)
            $isFramingComponent = ($option['isFramingComponent'] ?? false) === true;

            // Check both groupLabel AND optionLabel for frame-related keywords
            $isFrameGroup = str_contains($lowerGroup, 'frame') || str_contains($lowerGroup, 'ramme');
            $isFrameOption = str_contains($optionLabel, 'ramme') || str_contains($optionLabel, 'frame')
                || str_contains($optionLabel, 'svæveramme');

            if ($isFramingComponent || $isFrameGroup || $isFrameOption) {
                $frameOptionId = $option['optionId'] ?? null;
                $frameName = $option['optionLabel'] ?? $option['groupLabel'] ?? null;
                $frameGroupLabel = $groupLabel;
                $this->logger->info('[FrameResolver] Detected frame option', [
                    'isFramingComponent' => $isFramingComponent,
                    'isFrameGroup' => $isFrameGroup,
                    'isFrameOption' => $isFrameOption,
                    'groupLabel' => $groupLabel,
                    'optionLabel' => $option['optionLabel'] ?? null,
                ]);
                break;
            }
        }

        if ($frameOptionId === null && $frameName === null) {
            $this->logger->info('[FrameResolver] No frame option group found in options');
            return $emptyResult;
        }

        // Check for "no frame" option early
        if ($frameName !== null && $this->isNoFrameOption($frameName)) {
            $this->logger->info('[FrameResolver] No-frame option selected', [
                'frameName' => $frameName,
            ]);
            return $emptyResult;
        }

        $this->logger->info('[FrameResolver] Found frame option', [
            'groupLabel' => $frameGroupLabel,
            'optionId' => $frameOptionId,
            'frameName' => $frameName,
        ]);

        // Load the ComponentOption with frame images to get all data
        $componentOption = null;
        if ($frameOptionId !== null) {
            // Try direct lookup as ComponentOption ID first
            $criteria = new Criteria([$frameOptionId]);
            $criteria->addAssociation('frameImages.media.thumbnails');
            $componentOption = $this->componentOptionRepository->search($criteria, $context)->first();

            // Fallback: Try via PropertyGroupOption ID → ComponentType → ComponentOption
            if (!$componentOption) {
                $componentOption = $this->findComponentOptionByPropertyGroupOptionId($frameOptionId, $context);
            }
        }

        if (!$componentOption) {
            $this->logger->info('[FrameResolver] ComponentOption not found', [
                'optionId' => $frameOptionId,
            ]);
            return $emptyResult;
        }

        // Extract frame dimensions
        $frameSpanCm = $componentOption->getFrameEdgeSpan();
        $frameOverlapCm = $componentOption->getFrameEdgeOverlap();

        // Get frame images - corner and edge
        $frameImages = $componentOption->getFrameImages();
        $cornerMedia = null;
        $edgeMedia = null;

        if ($frameImages && $frameImages->count() > 0) {
            foreach ($frameImages->getElements() as $frameImage) {
                $media = $frameImage->getMedia();
                if (!$media) {
                    continue;
                }

                $imageType = $frameImage->getImageType();
                if ($imageType === FrameImageTypeEnum::TopLeftCorner->value && $cornerMedia === null) {
                    $cornerMedia = $media;
                } elseif ($imageType === FrameImageTypeEnum::TopMiddle->value && $edgeMedia === null) {
                    $edgeMedia = $media;
                }

                // Stop if we have both
                if ($cornerMedia !== null && $edgeMedia !== null) {
                    break;
                }
            }
        }

        if ($cornerMedia === null) {
            $this->logger->info('[FrameResolver] No corner image found for ComponentOption', [
                'optionId' => $frameOptionId,
                'frameName' => $frameName,
            ]);
            return $emptyResult;
        }

        try {
            $cornerImageData = $this->mediaFileReader->readMediaFile($cornerMedia);
            $cornerImageBase64 = base64_encode($cornerImageData);
            $frameMimeType = $cornerMedia->getMimeType() ?? 'image/jpeg';

            $edgeImageBase64 = null;
            if ($edgeMedia !== null) {
                $edgeImageData = $this->mediaFileReader->readMediaFile($edgeMedia);
                $edgeImageBase64 = base64_encode($edgeImageData);
            }

            $this->logger->info('[FrameResolver] Successfully resolved frame data', [
                'optionId' => $frameOptionId,
                'frameName' => $frameName,
                'cornerMediaId' => $cornerMedia->getId(),
                'edgeMediaId' => $edgeMedia?->getId(),
                'frameSpanCm' => $frameSpanCm,
                'frameOverlapCm' => $frameOverlapCm,
                'mimeType' => $frameMimeType,
            ]);

            return [
                'frameCornerImageBase64' => $cornerImageBase64,
                'frameEdgeImageBase64' => $edgeImageBase64,
                'frameMimeType' => $frameMimeType,
                'frameSpanCm' => $frameSpanCm,
                'frameOverlapCm' => $frameOverlapCm,
                'frameName' => $frameName,
            ];
        } catch (Throwable $e) {
            $this->logger->warning('[FrameResolver] Failed to read frame image files', [
                'optionId' => $frameOptionId,
                'frameName' => $frameName,
                'error' => $e->getMessage(),
            ]);
            return $emptyResult;
        }
    }

    /**
     * @deprecated Use resolveFrameDataFromOptions() instead for richer frame data
     * @param array<string, mixed> $options
     * @return array{frameImageBase64: string|null, frameMimeType: string|null}
     */
    public function resolveFrameImageFromOptions(array $options, Context $context): array
    {
        $frameData = $this->resolveFrameDataFromOptions($options, $context);

        return [
            'frameImageBase64' => $frameData['frameCornerImageBase64'],
            'frameMimeType' => $frameData['frameMimeType'],
        ];
    }
}
