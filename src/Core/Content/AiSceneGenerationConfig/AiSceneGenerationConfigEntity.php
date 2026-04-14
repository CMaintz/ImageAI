<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Core\Content\AiSceneGenerationConfig;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity as EntityAttribute;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * Entity for storing scene generation configuration
 *
 * This is a singleton entity - only one global configuration exists.
 * Stores available options for scene generation with:
 * - label: Display name in UI
 * - description: Full text to insert into prompt
 */
#[EntityAttribute('ai_scene_generation_config', collectionClass: AiSceneGenerationConfigCollection::class)]
class AiSceneGenerationConfigEntity extends Entity
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID)]
    public string $id;

    /**
     * Camera lens options
     * Format: [{label: "50mm f/1.8", description: "captured with a 50mm f/1.8 standard lens"}, ...]
     */
    #[Field(type: FieldType::JSON)]
    public array $cameraLensOptions = [];

    /**
     * Camera perspective options (vertical - eye level, bird's eye, etc.)
     * Format: [{label: "Eye Level", description: "shot from eye-level perspective"}, ...]
     */
    #[Field(type: FieldType::JSON)]
    public array $perspectiveOptions = [];

    /**
     * Camera angle options (horizontal - angle to wall on x-axis)
     * Format: [{label: "Straight On", description: "camera positioned directly facing the wall"}, ...]
     */
    #[Field(type: FieldType::JSON)]
    public array $cameraAngleOptions = [];

    /**
     * Interior style options
     * Format: [{label: "Nordic", description: "Nordic Scandinavian design with light woods..."}, ...]
     */
    #[Field(type: FieldType::JSON)]
    public array $interiorStyleOptions = [];

    /**
     * Lighting options
     * Format: [{label: "Natural Daylight", description: "natural daylight streaming through windows"}, ...]
     */
    #[Field(type: FieldType::JSON)]
    public array $lightingOptions = [];

    /**
     * Visual style options (photorealistic, cinematic, etc.)
     * Format: [{label: "Photorealistic", description: "photorealistic style"}, ...]
     */
    #[Field(type: FieldType::JSON)]
    public array $styleOptions = [];

    /**
     * Styling/atmosphere options (how "lived in" the space feels)
     * Format: [{label: "Staged", description: "professionally styled with balanced lived-in details"}, ...]
     */
    #[Field(type: FieldType::JSON)]
    public array $stylingOptions = [];

    /**
     * Aspect ratio options
     * Format: [{label: "16:9", value: "16:9"}, ...]
     */
    #[Field(type: FieldType::JSON)]
    public array $aspectRatioOptions = [];

    /**
     * Mood/atmosphere options
     * Format: [{label: "Professional", description: "professional"}, ...]
     */
    #[Field(type: FieldType::JSON)]
    public array $moodOptions = [];

    /**
     * Color palette options
     * Format: [{label: "Warm Earth Tones", description: "warm earth tones with browns and beiges"}, ...]
     */
    #[Field(type: FieldType::JSON)]
    public array $colorPaletteOptions = [];

    /**
     * Composition style options
     * Format: [{label: "Balanced", description: "balanced"}, ...]
     */
    #[Field(type: FieldType::JSON)]
    public array $compositionOptions = [];

    /**
     * Scene type options with detailed descriptions for prompt generation
     * Format: [{label: "Living Room", description: "A contemporary living space with comfortable seating..."}, ...]
     *
     * Labels should match the media folder names under "AI Environment Scenes"
     */
    #[Field(type: FieldType::JSON)]
    public array $sceneTypeOptions = [];
}
