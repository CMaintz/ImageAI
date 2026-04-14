<?php declare(strict_types=1);

namespace Illux\ImageAi\Builder\Prompt;

use Illux\ImageAi\Config\PluginConstants;
use Illux\ImageAi\DTO\FrameData;

// phpcs:disable Generic.Files.LineLength.TooLong

/**
 * Builder for constructing image composition prompts
 *
 * Responsible for creating prompts that instruct the AI to composite
 * product artwork into environment scenes with appropriate sizing and framing.
 * Optionally includes frame reference image instructions with dimensions.
 */
class CompositionPromptBuilder
{
    /** @var array<string, array{groupLabel: string, optionLabel: string, dimensions?: array}> */
    private array $options = [];

    /** @var array{width: int, height: int, unit: string}|null */
    private ?array $dimensions = null;

    private ?FrameData $frameData = null;

    /**
     * Set all product options
     *
     * @param array $options Map of groupLabel => option data
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Set parsed size dimensions for scaling calculations
     *
     * @param array{width: int, height: int, unit: string}|null $dimensions
     */
    public function setDimensions(?array $dimensions): self
    {
        $this->dimensions = $dimensions;
        return $this;
    }

    /**
     * Set frame data including images and dimensions
     *
     * @param FrameData|null $frameData Frame reference data
     */
    public function setFrameData(?FrameData $frameData): self
    {
        $this->frameData = $frameData;
        return $this;
    }

    /**
     * Build the complete composition prompt
     *
     * Following Gemini best practices for multi-image composition:
     * - Hyper-specific descriptions
     * - Clear context and intent
     * - Step-by-step instructions
     * - Semantic positive descriptions (not "don't do X")
     * - Photographic/cinematic language
     *
     * @return string Complete prompt ready for API
     */
    public function build(): string
    {
        $specificationsList = [];
        $material = 'high-quality art print';

        foreach ($this->options as $groupLabel => $option) {
            $label = $option['optionLabel'] ?? $option['groupLabel'] ?? $groupLabel;
            $lowerGroup = strtolower($groupLabel);

            // Skip frame from specifications - handled by frame reference image
            if (str_contains($lowerGroup, 'frame') || str_contains($lowerGroup, 'ramme')) {
                continue;
            }

            $specificationsList[] = "{$groupLabel}: {$label}";

            if (str_contains($lowerGroup, 'material') || str_contains($lowerGroup, 'materiale')) {
                $material = $this->mapMaterial($label);
            }
        }

        $specificationsText = !empty($specificationsList)
            ? implode(', ', $specificationsList)
            : 'standard art print';

        // Build size context for scaling
        $sizeContext = $this->buildSizeContext();
        $scaleInstructions = $this->buildScaleInstructions();

        // Build image references based on whether frame data is included
        if ($this->frameData !== null) {
            $hasEdgeImage = $this->frameData->edgeImageBase64 !== null;
            if ($hasEdgeImage) {
                $imageReferences = 'The first image is wall art, the second image is the room environment, the third image shows a frame corner sample, and the fourth image shows a frame edge sample.';
            } else {
                $imageReferences = 'The first image is wall art, the second image is the room environment, and the third image shows the frame style to use.';
            }
            $shadowText = 'Add a soft shadow behind the frame consistent with the room\'s light direction.';
        } else {
            $imageReferences = 'The first image is wall art that needs to be placed in the room shown in the second image. There are only two images - no frame reference is provided.';
            $shadowText = 'Add a subtle shadow at the edges consistent with the room\'s light direction.';
        }

        $frameNarrative = $this->buildFrameNarrative();

        return <<<PROMPT
Create a professional interior design photograph for a high-end home decor magazine. {$imageReferences}

Take the artwork from the first image and hang it on the wall in the room from the second image. The artwork measures {$sizeContext}, and this exact proportion must be preserved when scaling - the width-to-height ratio is locked and unchangeable.
{$frameNarrative}
Find the most prominent wall in the room and place the artwork in a centered, balanced position at eye-level or above existing furniture. If centering would cause the artwork to overlap windows, doors, or furniture, shift the position until it fits while keeping its exact proportions intact. The artwork belongs on a solid wall surface only - never on windows, doors, glass, or mirrors.

Scale the artwork realistically: {$scaleInstructions}

The print material is {$material}. Product specifications: {$specificationsText}.

Match the room's existing lighting precisely. Study the color temperature of the room - warm tungsten glow, cool daylight, or neutral - and apply that same color cast to the artwork surface. The artwork should look like it was photographed in this exact room, not pasted in from elsewhere. {$shadowText}

Keep every other detail of the room exactly as shown - all furniture, decorations, wall colors, and architectural features remain unchanged.

Generate a photorealistic image with the same aspect ratio as the room photo, showing the artwork professionally displayed as if captured by an interior photographer.
PROMPT;
    }

    /**
     * Build narrative frame section for the prompt.
     */
    private function buildFrameNarrative(): string
    {
        if ($this->frameData === null) {
            return "\nThis artwork has NO FRAME - display it frameless with clean, sharp edges directly against the wall. Do not add any frame, border, or molding around the artwork.\n";
        }

        $frameName = $this->frameData->name ?? 'the provided frame style';

        $hasEdgeImage = $this->frameData->edgeImageBase64 !== null;

        if ($hasEdgeImage) {
            $imageRef = "The third image shows a corner sample and the fourth image shows an edge section of the frame";
        } else {
            $imageRef = "The third image shows a corner sample of the frame";
        }

        $dimensionContext = '';
        if ($this->frameData->hasDimensions()) {
            $visibleWidth = $this->frameData->getVisibleWidthCm();
            $dimensionContext = " The frame's visible border is approximately {$visibleWidth}cm wide around the artwork.";
        }

        return <<<FRAME

{$imageRef} - this is the exact frame style "{$frameName}" that must surround the artwork. Study this reference carefully: note the color, wood grain or texture, the molding profile shape, and the surface finish (matte, satin, or glossy). Construct the complete frame by extending this exact style along all four sides with properly mitered corners. Do not substitute a generic frame - replicate the reference frame precisely.{$dimensionContext}

FRAME;
    }

    /**
     * Map material option label to detailed description
     */
    private function mapMaterial(string $label): string
    {
        $lower = strtolower($label);

        return match (true) {
            str_contains($lower, 'canvas') || str_contains($lower, 'lærred') => 'premium stretched canvas with subtle textile texture',
            str_contains($lower, 'matte') || str_contains($lower, 'mat') => 'high-quality matte fine art paper with no reflections',
            str_contains($lower, 'gloss') || str_contains($lower, 'blank') => 'glossy photo paper with vibrant colors and subtle reflections',
            str_contains($lower, 'metal') || str_contains($lower, 'aluminium') => 'HD metal print with vivid colors and modern sheen',
            str_contains($lower, 'acrylic') || str_contains($lower, 'akryl') => 'face-mounted acrylic with glass-like depth and clarity',
            str_contains($lower, 'poster') || str_contains($lower, 'plakat') => 'standard poster paper print',
            default => "print material: {$label}"
        };
    }

    /**
     * Build detailed scale instructions based on dimensions
     */
    private function buildScaleInstructions(): string
    {
        if (!$this->dimensions) {
            return 'Scale the artwork to a size that looks proportionally balanced with the room furniture and wall space.';
        }

        $width = $this->dimensions['width'];
        $height = $this->dimensions['height'];
        $unit = $this->dimensions['unit'] ?? 'cm';

        // Calculate relative scale hints
        $heightCm = $unit === 'in' ? $height * PluginConstants::CM_PER_INCH : $height;
        $scaleReference = match (true) {
            $heightCm >= 120 => 'This is a statement piece - approximately 60% of a standard door height (200cm). It should dominate the wall space.',
            $heightCm >= 100 => 'This is a large artwork - approximately half the height of a standard door. It should be a prominent focal point.',
            $heightCm >= 70 => 'This is a medium-large artwork - roughly 35-40% of door height. It should be clearly visible but not overwhelming.',
            $heightCm >= 50 => 'This is a medium artwork - roughly 25-30% of door height. Well-suited above furniture.',
            $heightCm >= 30 => 'This is a smaller artwork - roughly 15-20% of door height. Works well in groupings or smaller spaces.',
            default => 'This is a compact piece - scale it proportionally small, suitable for intimate viewing distances.'
        };

        $aspectRatio = round($width / $height, 2);
        return "The artwork dimensions are {$width}x{$height} {$unit} (aspect ratio {$aspectRatio}:1 - this ratio is FIXED and must NOT change). {$scaleReference} Use furniture and architectural elements in the room as scale references. When scaling, maintain the exact {$width}:{$height} proportions.";
    }

    /**
     * Build size context for the prompt based on parsed dimensions
     */
    private function buildSizeContext(): string
    {
        if (!$this->dimensions) {
            return "Size: Use an appropriate size that looks natural in the scene.";
        }

        $width = $this->dimensions['width'];
        $height = $this->dimensions['height'];
        $unit = $this->dimensions['unit'] ?? 'cm';

        // Provide scale reference for common room elements
        $scaleHint = match (true) {
            $height >= 100 => 'This is a large artwork - approximately half the height of a standard door.',
            $height >= 70 => 'This is a medium-large artwork - scale it to be roughly 35-40% of door height.',
            $height >= 50 => 'This is a medium artwork - scale it to be roughly 25-30% of door height.',
            default => 'This is a smaller artwork - scale it proportionally smaller.'
        };

        $aspectRatio = round($width / $height, 2);
        return "Size: {$width}x{$height} {$unit} (LOCKED aspect ratio: {$aspectRatio}:1 - DO NOT CHANGE)\n{$scaleHint}";
    }

    public function buildWallpaper(): string
    {
        $sizeContext = $this->buildWallpaperSizeContext();

        return <<<PROMPT
Create a professional interior design visualization. The first image is a wallpaper design that needs to be applied to the walls in the room shown in the second image.

CRITICAL REQUIREMENTS:
1. Keep the room environment COMPLETELY UNCHANGED except for the walls - preserve all furniture, decorations, lighting, and architectural details
2. Output image must have the SAME ASPECT RATIO as the second image (the room/environment)
3. The wallpaper should cover the ENTIRE visible wall surface, not be placed as a single artwork

{$sizeContext}

Step-by-step composition instructions:

1. ANALYZE THE ROOM: Identify all visible wall surfaces. Note lighting direction, existing colors, and architectural features like windows, doors, and corners.

2. PREPARE THE WALLPAPER:
   - Extract the wallpaper design from the first image
   - If it's a repeating pattern, tile it seamlessly
   - If it's a mural/scene, scale it to fit the wall proportionally

3. APPLY TO WALLS:
   - Cover ALL visible wall surfaces with the wallpaper
   - Wrap around corners naturally
   - Stop at architectural boundaries (windows, doors, ceiling, floor)
   - Maintain pattern alignment across wall sections

4. INTEGRATE PERSPECTIVE:
   - Match the wallpaper perspective to each wall's angle in the scene
   - Ensure patterns follow the room's perspective lines correctly
   - Handle corners with proper perspective transitions

5. LIGHTING INTEGRATION:
   - Apply the room's existing lighting to the wallpaper surface
   - Add subtle shadows in corners and near furniture
   - Ensure colors appear natural under the room's lighting conditions

6. FINISHING DETAILS:
   - Blend wallpaper edges naturally at architectural boundaries
   - Ensure seamless pattern repetition where applicable
   - The wallpaper should look professionally installed

OUTPUT: A photorealistic interior photo with the SAME ASPECT RATIO as the input room image, showing the room with the new wallpaper professionally installed on all walls. All furniture and decorations remain exactly as they were.
PROMPT;
    }

    private function buildWallpaperSizeContext(): string
    {
        if (!$this->dimensions) {
            return "Scale: Apply wallpaper to cover all visible wall surfaces naturally.";
        }

        $width = $this->dimensions['width'];
        $height = $this->dimensions['height'];
        $unit = $this->dimensions['unit'] ?? 'cm';

        return "Pattern dimensions: {$width}x{$height} {$unit}\nScale the pattern appropriately for a realistic wall coverage.";
    }

    /**
     * Reset builder to initial state
     */
    public function reset(): self
    {
        $this->options = [];
        $this->dimensions = null;
        $this->frameData = null;
        return $this;
    }
}
