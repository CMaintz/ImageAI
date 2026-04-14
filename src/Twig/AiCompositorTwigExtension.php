<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Twig;

use CMaintz\ImageAi\Config\PluginConstants;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for AI Compositor button visibility and state
 *
 * Provides functions:
 * - aiCompositorStatus(): Determines button visibility and enabled state
 * - aiCompositorRoomTypes(): Gets available room types for selection
 */
class AiCompositorTwigExtension extends AbstractExtension
{
    /** Product types where button should NEVER be shown (case-insensitive match against property name) */
    private const array HIDDEN_TYPES = [
        'Wexo Gift Card',
        'Wexo Poster Frame',
        'Wexo Pop Art',
    ];

    /** Product types that use product cover image (case-insensitive match against property name) */
    private const array ARTWORK_TYPES = [
        'Wexo Artwork',
        'Wexo Wallpaper Customizable',
    ];

    /** Product types that require user upload (case-insensitive match against property name) */
    private const array UPLOAD_TYPES = [
        'Wexo Photo',
        'Wexo Your Wallpaper',
        'Wexo Collage Product',
    ];

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     * @param EntityRepository<MediaFolderCollection> $mediaFolderRepository
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $mediaFolderRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'aiCompositorStatus',
                function (?ProductEntity $product, SalesChannelContext $context): array {
                    return $this->getCompositorStatus($product, $context);
                }
            ),
            new TwigFunction(
                'aiCompositorRoomTypes',
                function (SalesChannelContext $context): array {
                    return $this->getRoomTypes($context->getContext());
                }
            ),
        ];
    }

    /**
     * Get the compositor button status for a product
     *
     * Button is visible for ALL product types EXCEPT hidden types (gift card, poster frame, pop art).
     * The generate button state (enabled/disabled) depends on product type:
     * - ARTWORK: enabled if cover image exists
     * - UPLOAD: enabled if user has uploaded an image
     * - Other: enabled by default
     *
     * @return array{visible: bool, enabled: bool, reason: ?string, productType: ?string}
     */
    public function getCompositorStatus(
        ?ProductEntity $product,
        SalesChannelContext $context
    ): array {
        $type = $this->getProductTypeFromProduct($product, $context);

        // HIDDEN types - don't show button
        if ($type !== null && in_array($type, self::HIDDEN_TYPES, true)) {
            return [
                'visible' => false,
                'enabled' => false,
                'reason' => 'Product type not supported',
                'productType' => $type,
            ];
        }

        // ARTWORK types - visible and enabled if we have a cover image
        if ($type !== null && in_array($type, self::ARTWORK_TYPES, true)) {
            $imageUrl = $product ? $this->getArtworkCoverUrl($product, $context->getContext()) : null;

            return [
                'visible' => true,
                'enabled' => $imageUrl !== null,
                'reason' => $imageUrl ? null : 'No artwork image found',
                'productType' => $type,
            ];
        }

        // UPLOAD types - visible and enabled if user has uploaded
        if ($type !== null && in_array($type, self::UPLOAD_TYPES, true)) {
            $hasUpload = $this->hasUserUploadedImage();

            return [
                'visible' => true,
                'enabled' => $hasUpload,
                'reason' => $hasUpload ? null : 'Upload an image first to see it in a room',
                'productType' => $type,
            ];
        }

        // All other types (including unknown) - visible and enabled by default
        return [
            'visible' => true,
            'enabled' => true,
            'reason' => null,
            'productType' => $type,
        ];
    }

    /**
     * Get the artwork cover image URL (used internally for status checking)
     *
     * For artwork products: Uses parent.cover.media (or product.cover.media if no parent)
     */
    private function getArtworkCoverUrl(ProductEntity $product, Context $context): ?string
    {
        // If this is a variant, get cover from parent
        if ($product->getParentId()) {
            $parentProduct = $this->getParentProduct($product, $context);
            $parentCoverUrl = $parentProduct?->getCover()?->getMedia()?->getUrl();
            if ($parentCoverUrl) {
                return $parentCoverUrl;
            }
        }

        // Fallback to product's own cover (if it's the main product, not a variant)
        return $product->getCover()?->getMedia()?->getUrl();
    }

    /**
     * Get the product type by checking property names on product or parent.
     * Returns the first matching type name from HIDDEN_TYPES, ARTWORK_TYPES, or UPLOAD_TYPES.
     */
    private function getProductTypeFromProduct(?ProductEntity $product, SalesChannelContext $context): ?string
    {
        if (!$product) {
            return null;
        }

        // First try to get type from the product's own properties
        $type = $this->findProductTypeInProperties($product, $context->getContext());
        if ($type !== null) {
            return $type;
        }

        // Fallback: check parent product's properties (variants inherit type from parent)
        if ($product->getParentId()) {
            $parentProduct = $this->getParentProduct($product, $context->getContext());
            if ($parentProduct) {
                return $this->findProductTypeInProperties($parentProduct, $context->getContext());
            }
        }

        return null;
    }

    /**
     * Find product type by checking property names against our type lists.
     * Uses case-insensitive comparison.
     * If properties aren't loaded on the entity, fetches them from database.
     */
    private function findProductTypeInProperties(ProductEntity $product, Context $context): ?string
    {
        $productId = $product->getId();
        $properties = $product->getProperties();
        $propertiesLoadedFromEntity = $properties !== null && $properties->count() > 0;

        // If properties not loaded, fetch from database
        if (!$propertiesLoadedFromEntity) {
            $properties = $this->loadProductProperties($productId, $context);
        }

        if ($properties === null || $properties->count() === 0) {
            return null;
        }
        // Check against our type lists (case-insensitive)
        $allTypes = array_merge(self::HIDDEN_TYPES, self::ARTWORK_TYPES, self::UPLOAD_TYPES);

        foreach ($properties->getElements() as $property) {
            $name = $property->getName();
            if ($name === null) {
                continue;
            }

            foreach ($allTypes as $type) {
                if (strcasecmp($name, $type) === 0) {
                    return $type; // Return the canonical name from our constants
                }
            }
        }

        return null;
    }

    /**
     * Load properties for a product from the database
     */
    private function loadProductProperties(string $productId, Context $context): ?PropertyGroupOptionCollection
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('properties');

        /** @var ProductEntity|null $product */
        $product = $this->productRepository->search($criteria, $context)->first();

        return $product?->getProperties();
    }

    /**
     * Get the parent product with required associations
     */
    private function getParentProduct(?ProductEntity $product, Context $context): ?ProductEntity
    {
        if (!$product || !$product->getParentId()) {
            return null;
        }

        $criteria = new Criteria([$product->getParentId()]);
        $criteria->addAssociation('properties');
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('media');

        /** @var ProductEntity|null $parentProduct */
        $parentProduct = $this->productRepository->search($criteria, $context)->first();

        return $parentProduct;
    }

    /**
     * Check if user has uploaded an image via GraphicalAssistance or ChiliPublish
     */
    private function hasUserUploadedImage(): bool
    {
        $session = $this->requestStack->getSession();

        // Check GraphicalAssistance session storage (keys follow pattern: assistanceStorage{randomKey})
        foreach ($session->all() as $key => $value) {
            if (str_starts_with($key, 'assistanceStorage') && is_string($value) && !empty($value)) {
                return true;
            }
        }

        // Check ChiliPublish session indicator
        if ($session->get('chili_session_id')) {
            return true;
        }

        return false;
    }

    /**
     * Get available room types from AI Environment Scenes subfolders
     *
     * Only returns folders that contain at least one media item.
     *
     * @return array<array{id: string, name: string}>
     */
    private function getRoomTypes(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', PluginConstants::SCENE_PARENT_FOLDER_NAME));
        $criteria->addAssociation('children');

        $parentFolder = $this->mediaFolderRepository->search($criteria, $context)->first();

        if (!$parentFolder) {
            return [];
        }

        $children = $parentFolder->getChildren();
        if (!$children || $children->count() === 0) {
            return [];
        }

        // Get folder IDs and check which have media
        $folderIds = array_map(fn($f) => $f->getId(), $children->getElements());
        $foldersWithMedia = $this->getFoldersWithMedia($folderIds, $context);

        $roomTypes = [];
        foreach ($children->getElements() as $folder) {
            // Only include folders that have at least one media item
            if (in_array($folder->getId(), $foldersWithMedia, true)) {
                $roomTypes[] = [
                    'id' => $folder->getId(),
                    'name' => $folder->getName(),
                ];
            }
        }

        // Sort alphabetically by name
        usort($roomTypes, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $roomTypes;
    }

    /**
     * Get folder IDs that contain at least one media item
     *
     * @param array<string> $folderIds
     * @return array<string> Folder IDs that have media
     */
    private function getFoldersWithMedia(array $folderIds, Context $context): array
    {
        if (empty($folderIds)) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('mediaFolderId', $folderIds));
        $criteria->setLimit(500);

        $mediaItems = $this->mediaRepository->search($criteria, $context);

        // Collect unique folder IDs that have media
        $foldersWithMedia = [];
        foreach ($mediaItems->getElements() as $media) {
            $folderId = $media->getMediaFolderId();
            if ($folderId && !in_array($folderId, $foldersWithMedia, true)) {
                $foldersWithMedia[] = $folderId;
            }
        }

        return $foldersWithMedia;
    }
}
