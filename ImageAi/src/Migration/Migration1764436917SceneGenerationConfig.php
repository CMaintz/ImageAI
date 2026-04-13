<?php declare(strict_types=1);

namespace Illux\ImageAi\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

// phpcs:disable Generic.Files.LineLength.TooLong
/**
 * @internal
 */
class Migration1764436917SceneGenerationConfig extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1764436917;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement("
            CREATE TABLE IF NOT EXISTS `ai_scene_generation_config` (
                `id`                      BINARY(16)     NOT NULL,
                `camera_lens_options`     JSON           NOT NULL,
                `perspective_options`     JSON           NOT NULL,
                `camera_angle_options`    JSON           NOT NULL,
                `interior_style_options`  JSON           NOT NULL,
                `lighting_options`        JSON           NOT NULL,
                `style_options`           JSON           NOT NULL,
                `styling_options`         JSON           NOT NULL,
                `aspect_ratio_options`    JSON           NOT NULL,
                `mood_options`            JSON           NOT NULL,
                `color_palette_options`   JSON           NOT NULL,
                `composition_options`     JSON           NOT NULL,
                `scene_type_options`      JSON           NULL,
                `created_at`              DATETIME(3)    NOT NULL,
                `updated_at`              DATETIME(3)    NULL,

                PRIMARY KEY (`id`),
                CONSTRAINT `json.scene_generation_config.camera_lens_options` CHECK (JSON_VALID(`camera_lens_options`)),
                CONSTRAINT `json.scene_generation_config.perspective_options` CHECK (JSON_VALID(`perspective_options`)),
                CONSTRAINT `json.scene_generation_config.camera_angle_options` CHECK (JSON_VALID(`camera_angle_options`)),
                CONSTRAINT `json.scene_generation_config.interior_style_options` CHECK (JSON_VALID(`interior_style_options`)),
                CONSTRAINT `json.scene_generation_config.lighting_options` CHECK (JSON_VALID(`lighting_options`)),
                CONSTRAINT `json.scene_generation_config.style_options` CHECK (JSON_VALID(`style_options`)),
                CONSTRAINT `json.scene_generation_config.styling_options` CHECK (JSON_VALID(`styling_options`)),
                CONSTRAINT `json.scene_generation_config.aspect_ratio_options` CHECK (JSON_VALID(`aspect_ratio_options`)),
                CONSTRAINT `json.scene_generation_config.mood_options` CHECK (JSON_VALID(`mood_options`)),
                CONSTRAINT `json.scene_generation_config.color_palette_options` CHECK (JSON_VALID(`color_palette_options`)),
                CONSTRAINT `json.scene_generation_config.composition_options` CHECK (JSON_VALID(`composition_options`))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Seed default configuration
        $cameraLensOptions = json_encode([
            ['label' => '35mm f/1.8 (Wide angle)', 'description' => '35mm f/1.8 wide-angle lens'],
            ['label' => '50mm f/1.8 (Standard)', 'description' => '50mm f/1.8 standard lens'],
            ['label' => '70mm f/2.8 (Portrait)', 'description' => '70mm f/2.8 portrait lens'],
            ['label' => '135mm f/2.8 (Telephoto)', 'description' => '135mm f/2.8 telephoto lens'],
        ]);
        // Perspective options (vertical camera angle - eye level, bird's eye, etc.)
        $perspectiveOptions = json_encode([
            ['label' => 'Eye Level', 'description' => 'shot from eye-level perspective'],
            ['label' => 'Low Angle', 'description' => 'low-angle shot looking upward'],
            ['label' => 'High Angle', 'description' => 'high-angle shot looking downward'],
            ['label' => 'Bird\'s Eye View', 'description' => 'bird\'s eye view from directly above'],
            ['label' => 'Worm\'s Eye View', 'description' => 'worm\'s eye view from ground level'],
            ['label' => 'Dutch Angle', 'description' => 'dutch angle with tilted horizon'],
            ['label' => 'Over-the-Shoulder', 'description' => 'over-the-shoulder perspective'],
        ]);

        // Camera angle options (horizontal angle to wall on x-axis)
        $cameraAngleOptions = json_encode([
            ['label' => 'Straight On (0°)', 'description' => 'camera positioned directly facing the wall'],
            ['label' => '15° Angle', 'description' => 'camera angled 15 degrees from the wall'],
            ['label' => '30° Angle', 'description' => 'camera angled 30 degrees from the wall'],
            ['label' => '45° Angle', 'description' => 'camera angled 45 degrees from the wall, showing room depth'],
            ['label' => '60° Angle', 'description' => 'camera angled 60 degrees from the wall, emphasizing room corner'],
            ['label' => 'Corner View (90°)', 'description' => 'camera positioned at corner, showing two walls'],
        ]);

        $interiorStyleOptions = json_encode([
            ['label' => 'Nordic Scandinavian', 'description' => 'Nordic Scandinavian design with light woods and clean minimal forms'],
            ['label' => 'Scandinavian', 'description' => 'Scandinavian simplicity with soft neutrals and natural textures'],
            ['label' => 'Bohemian', 'description' => 'Bohemian style with eclectic patterns and layered textiles'],
            ['label' => 'Boho', 'description' => 'Boho aesthetic with relaxed organic forms and artisanal décor'],
            ['label' => 'Modern', 'description' => 'Modern interior design with clean geometry and refined materials'],
            ['label' => 'Mid-Century Modern', 'description' => 'Mid-century modern with warm woods and iconic retro furnishings'],
            ['label' => 'Retro', 'description' => 'Retro interior design with bold colors and vintage-inspired décor'],
            ['label' => 'Art Deco', 'description' => 'Art Deco elegance with geometric symmetry and luxurious materials'],
            ['label' => 'Industrial', 'description' => 'Industrial style with raw materials, metal frameworks, and exposed textures'],
            ['label' => 'Rustic', 'description' => 'Rustic design with natural woods, earthy tones, and organic surfaces'],
            ['label' => 'Traditional', 'description' => 'Traditional interior with classic motifs and refined ornamentation'],
            ['label' => 'Contemporary', 'description' => 'Contemporary interior design with balanced minimal sophistication'],
            ['label' => 'Japandi', 'description' => 'Japandi fusion of Japanese minimalism and Scandinavian warmth'],
            ['label' => 'Minimalist', 'description' => 'Minimalist interior with uncluttered surfaces and pure functional lines'],
            ['label' => 'Maximalist', 'description' => 'Maximalist interior with bold patterns, layered décor, and expressive color'],
        ]);

        $lightingOptions = json_encode([
            ['label' => 'Natural Daylight', 'description' => 'natural daylight streaming through windows'],
            ['label' => 'Golden Hour', 'description' => 'warm golden hour sunlight with soft shadows'],
            ['label' => 'Blue Hour', 'description' => 'cool blue hour twilight with ambient glow'],
            ['label' => 'Overcast', 'description' => 'soft diffused lighting from overcast sky'],
            ['label' => 'Studio Lighting', 'description' => 'professional studio lighting with key and fill lights'],
            ['label' => 'Dramatic', 'description' => 'dramatic side lighting with strong contrast'],
            ['label' => 'Backlit', 'description' => 'backlit scene with rim lighting'],
            ['label' => 'Ambient', 'description' => 'soft ambient lighting without harsh shadows'],
        ]);

        $styleOptions = json_encode([
            ['label' => 'Architectural Photography', 'description' => 'architectural photography style with clean lines'],
            ['label' => 'Cinematic', 'description' => 'cinematic style with filmic atmosphere'],
            ['label' => 'Editorial Magazine', 'description' => 'editorial magazine photography style'],
            ['label' => 'Minimalist', 'description' => 'minimalist visual composition'],
            ['label' => 'Warm & Cozy', 'description' => 'warm and cozy visual aesthetic'],
            ['label' => 'Modern Contemporary', 'description' => 'modern visual aesthetic'],
            ['label' => 'Rustic Natural', 'description' => 'rustic natural aesthetic'],
        ]);

        $stylingOptions = json_encode([
            ['label' => 'Pristine', 'description' => 'pristine and perfectly arranged with no signs of use'],
            ['label' => 'Staged', 'description' => 'professionally styled with balanced lived-in details'],
            ['label' => 'Lived-in', 'description' => 'comfortably inhabited with personal touches and everyday items'],
            ['label' => 'Cozy', 'description' => 'warm and inviting with layered textures and comfortable atmosphere'],
            ['label' => 'Minimal', 'description' => 'uncluttered with only essential items present'],
        ]);

        $aspectRatioOptions = json_encode([
            ['label' => '16:9 (Wide landscape)', 'value' => '16:9'],
            ['label' => '9:16 (Tall portrait)', 'value' => '9:16'],
            ['label' => '4:3 (Standard landscape)', 'value' => '4:3'],
            ['label' => '3:4 (Standard portrait)', 'value' => '3:4'],
            ['label' => '1:1 (Square)', 'value' => '1:1'],
        ]);

        $moodOptions = json_encode([
            ['label' => 'Professional', 'description' => 'professional'],
            ['label' => 'Cozy & Inviting', 'description' => 'cozy and inviting'],
            ['label' => 'Elegant & Sophisticated', 'description' => 'elegant and sophisticated'],
            ['label' => 'Calm & Serene', 'description' => 'calm and serene'],
            ['label' => 'Vibrant & Energetic', 'description' => 'vibrant and energetic'],
            ['label' => 'Warm & Welcoming', 'description' => 'warm and welcoming'],
        ]);

        $colorPaletteOptions = json_encode([
            ['label' => 'Neutral Tones', 'description' => 'neutral tones with whites, grays, and beiges'],
            ['label' => 'Warm Earth Tones', 'description' => 'warm earth tones with browns, terracotta, and ochre'],
            ['label' => 'Cool Tones', 'description' => 'cool tones with blues, greens, and grays'],
            ['label' => 'Monochromatic', 'description' => 'monochromatic palette in shades of a single color'],
            ['label' => 'Pastel Colors', 'description' => 'soft pastel colors'],
            ['label' => 'Bold & Vibrant', 'description' => 'bold and vibrant colors'],
        ]);

        $compositionOptions = json_encode([
            ['label' => 'Balanced', 'description' => 'Use a balanced composition with evenly distributed visual weight'],
            ['label' => 'Symmetrical', 'description' => 'Use a symmetrical composition with mirrored elements'],
            ['label' => 'Asymmetrical', 'description' => 'Use an asymmetrical composition with dynamic visual weight'],
            ['label' => 'Rule of Thirds', 'description' => 'Compose the shot following the rule of thirds'],
            ['label' => 'Centered', 'description' => 'Use a centered composition with a strong focal point'],
        ]);

        // Scene type options - labels MUST match MediaFolderInstaller::SCENE_FOLDERS exactly
        $sceneTypeOptions = json_encode([
            ['label' => 'Commercial Office', 'description' => 'A modern commercial office space with professional furniture, large windows, and corporate aesthetic. Features desk areas, meeting spaces, or executive office environment.'],
            ['label' => 'Lobby', 'description' => 'An elegant hotel or corporate lobby with high ceilings, reception area, comfortable seating, and sophisticated design elements.'],
            ['label' => 'Kitchen', 'description' => 'A contemporary kitchen with modern appliances, clean countertops, and warm inviting atmosphere. Features island, cabinets, and dining elements.'],
            ['label' => 'Home Office', 'description' => 'A productive home office with desk, comfortable chair, and bookshelves. Includes task lighting, plants, and organizational elements. Natural light supports focus. Artwork displayed behind desk or on adjacent walls for inspiration.'],
            ['label' => 'Cafeteria', 'description' => 'A stylish café or cafeteria setting with tables, seating areas, and warm hospitality atmosphere. Features counter areas and casual dining spaces.'],
            ['label' => 'Restaurant', 'description' => 'An upscale restaurant interior with elegant dining tables, ambient lighting, and refined décor. Creates an inviting fine dining atmosphere.'],
            ['label' => 'Living Room', 'description' => 'A spacious living room with comfortable seating, coffee table, and accent furniture. Features large windows, soft textiles like throw pillows and blankets, and decorative elements such as plants and books. Warm and inviting atmosphere perfect for showcasing artwork above a sofa or on a feature wall.'],
            ['label' => 'Bedroom', 'description' => 'A serene bedroom with a well-made bed as centerpiece, flanked by nightstands with lamps. Includes soft bedding, area rugs, and personal touches. Natural light filters through curtains creating a calm, restful atmosphere. Ideal for artwork above the headboard.'],
            ['label' => 'Dining Room', 'description' => 'An elegant dining space with table and chairs, complemented by sideboard or buffet. Features statement lighting, tableware, and perhaps a bar cart. Sophisticated yet welcoming atmosphere for artwork on the main dining wall.'],
            ['label' => 'Hallway', 'description' => 'A welcoming hallway or entryway with console table, mirror, and storage. Features runner rug and good lighting. The narrow walls create a gallery-like setting perfect for displaying artwork.'],
            ['label' => 'Reading Nook', 'description' => 'A cozy reading corner with comfortable armchair or chaise and good reading lamp. Features soft throws, side table, and nearby bookshelves. The intimate setting is ideal for smaller artwork pieces.'],
            ['label' => 'Loft', 'description' => 'An industrial-style loft with exposed brick, high ceilings, and large windows. Features mix of vintage and modern furniture with metal accents. Expansive walls perfect for large statement artwork.'],
            ['label' => 'Café', 'description' => 'A stylish café interior with bistro tables, service counter, and pendant lighting. Features menu boards, coffee equipment, and plants. Exposed brick or tile creates inviting atmosphere. Large walls ideal for rotating artwork displays.'],
        ]);

        // Only insert if no config exists yet
        $configExists = $connection->fetchOne("SELECT COUNT(*) FROM `ai_scene_generation_config`");
        if ($configExists) {
            return;
        }

        $connection->executeStatement("
            INSERT INTO `ai_scene_generation_config` (
                `id`,
                `camera_lens_options`,
                `perspective_options`,
                `camera_angle_options`,
                `interior_style_options`,
                `lighting_options`,
                `style_options`,
                `styling_options`,
                `aspect_ratio_options`,
                `mood_options`,
                `color_palette_options`,
                `composition_options`,
                `scene_type_options`,
                `created_at`
            ) VALUES (
                UNHEX(REPLACE(UUID(), '-', '')),
                :cameraLensOptions,
                :perspectiveOptions,
                :cameraAngleOptions,
                :interiorStyleOptions,
                :lightingOptions,
                :styleOptions,
                :stylingOptions,
                :aspectRatioOptions,
                :moodOptions,
                :colorPaletteOptions,
                :compositionOptions,
                :sceneTypeOptions,
                NOW(3)
            )
        ", [
            'cameraLensOptions' => $cameraLensOptions,
            'perspectiveOptions' => $perspectiveOptions,
            'cameraAngleOptions' => $cameraAngleOptions,
            'interiorStyleOptions' => $interiorStyleOptions,
            'lightingOptions' => $lightingOptions,
            'styleOptions' => $styleOptions,
            'stylingOptions' => $stylingOptions,
            'aspectRatioOptions' => $aspectRatioOptions,
            'moodOptions' => $moodOptions,
            'colorPaletteOptions' => $colorPaletteOptions,
            'compositionOptions' => $compositionOptions,
            'sceneTypeOptions' => $sceneTypeOptions,
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Implement if needed
    }
}
