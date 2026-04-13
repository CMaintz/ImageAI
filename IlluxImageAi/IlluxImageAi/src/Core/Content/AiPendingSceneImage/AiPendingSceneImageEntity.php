<?php declare(strict_types=1);

namespace Illux\ImageAi\Core\Content\AiPendingSceneImage;

use DateTimeInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity as EntityAttribute;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * Entity for storing pending scene images awaiting approval
 *
 * Generated images are stored here until an admin approves or rejects them.
 * Approved images are saved to the media library and linked.
 * Rejected images remain in the database but are not saved to media.
 */
#[EntityAttribute('ai_pending_scene_image', collectionClass: AiPendingSceneImageCollection::class)]
class AiPendingSceneImageEntity extends Entity
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID)]
    public string $id;

    #[Field(type: FieldType::STRING)]
    public string $sceneType;

    #[Field(type: FieldType::TEXT)]
    public string $imageData;

    #[Field(type: FieldType::STRING)]
    public string $mimeType;

    #[Field(type: FieldType::TEXT)]
    public string $prompt;

    #[Field(type: FieldType::TEXT)]
    public string $systemInstruction;

    #[Field(type: FieldType::JSON)]
    public array $generationParams;

    #[Field(type: FieldType::JSON)]
    public array $config;

    #[Field(type: FieldType::STRING)]
    public string $status;

    #[Field(type: FieldType::UUID)]
    public ?string $mediaId = null;

    #[Field(type: FieldType::DATETIME)]
    public ?DateTimeInterface $approvedAt = null;

    #[Field(type: FieldType::DATETIME)]
    public ?DateTimeInterface $rejectedAt = null;
}
