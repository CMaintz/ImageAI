<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Builder\Prompt;

use RuntimeException;

// phpcs:disable Generic.Files.LineLength.TooLong

/**
 * Builder for constructing scene image generation prompts
 *
 * Follows Gemini 2.5 Flash Image best practices:
 * - Descriptive narratives over keyword lists (94% vs 61% scene coherence)
 * - Photographic language (camera angles, lenses, lighting)
 * - Think like a photographer for photorealistic results
 * - Detailed scene descriptions for better coherence
 *
 * @see https://developers.googleblog.com/en/how-to-prompt-gemini-2-5-flash-image-generation-for-the-best-results/
 */
class ScenePromptBuilder
{
    private string $sceneType = '';
    private string $sceneTypeDescription = '';
    private string $perspective = '';
    private string $cameraAngle = '';
    private string $cameraLens = '';
    private string $interiorStyle = '';
    private string $lighting = '';
    private string $styling = '';
    private string $photographyStyle = '';
    private string $mood = 'professional';
    private ?string $colorPalette = null;
    private string $composition = 'balanced';
    private string $customDetails = '';

    public function setSceneType(string $sceneType): self
    {
        $this->sceneType = $sceneType;
        return $this;
    }

    public function setSceneTypeDescription(string $description): self
    {
        $this->sceneTypeDescription = $description;
        return $this;
    }

    public function setPerspective(string $perspective): self
    {
        $this->perspective = $perspective;
        return $this;
    }

    public function setCameraLens(string $cameraLens): self
    {
        $this->cameraLens = $cameraLens;
        return $this;
    }

    public function setCameraAngle(string $cameraAngle): self
    {
        $this->cameraAngle = $cameraAngle;
        return $this;
    }

    public function setInteriorStyle(string $interiorStyle): self
    {
        $this->interiorStyle = $interiorStyle;
        return $this;
    }

    public function setLighting(string $lighting): self
    {
        $this->lighting = $lighting;
        return $this;
    }

    public function setMood(string $mood): self
    {
        $this->mood = $mood;
        return $this;
    }

    public function setColorPalette(?string $colorPalette): self
    {
        $this->colorPalette = $colorPalette;
        return $this;
    }

    public function setComposition(string $composition): self
    {
        $this->composition = $composition;
        return $this;
    }

    public function setStyling(string $styling): self
    {
        $this->styling = $styling;
        return $this;
    }

    public function setPhotographyStyle(string $photographyStyle): self
    {
        $this->photographyStyle = $photographyStyle;
        return $this;
    }

    public function setCustomDetails(string $customDetails): self
    {
        $this->customDetails = $customDetails;
        return $this;
    }


    /**
     * Build the complete prompt as a descriptive narrative
     *
     * Following Gemini best practices:
     * - Be hyper-specific with details
     * - Provide context and intent
     * - Use step-by-step instructions for complex scenes
     * - Control the camera with photographic language
     * - Use semantic negative prompts (describe what you want, not what to avoid)
     *
     * @return string Complete prompt text
     */
    public function build(): string
    {
        $this->validate();

        // Build camera positioning description
        $cameraPositioning = $this->perspective;
        if (!empty($this->cameraAngle)) {
            $cameraPositioning .= ', ' . $this->cameraAngle;
        }

        // Build style description - always photorealistic base with optional photography style
        $styleDescription = 'photorealistic';
        if (!empty($this->photographyStyle) && strtolower($this->photographyStyle) !== 'photorealistic') {
            $styleDescription .= ', ' . $this->photographyStyle;
        }

        // Build scene description - use detailed description if available, otherwise just the type name
        $sceneDescription = !empty($this->sceneTypeDescription)
            ? "{$this->sceneType}: {$this->sceneTypeDescription}"
            : $this->sceneType;

        $prompt = <<<PROMPT
Create a {$styleDescription} interior photograph of a {$this->sceneType} designed for an e-commerce art print store. The image will be used to showcase wall art, so it must include a wall with enough clear space to display artwork.

STEP 1 - THE ROOM:
Design a {$this->interiorStyle} space based on this concept: {$sceneDescription}

The room must look like a real, lived-in space - not artificially staged or AI-generated looking. Include realistic details like:
- Furniture with proper proportions and realistic materials
- Natural wear patterns and authentic textures
- Decor items that make sense for this type of room
- Proper spatial relationships between objects
The space should feel inviting and aspirational, the kind of room where someone would proudly display artwork.

STEP 2 - THE WALL FOR ARTWORK (IMPORTANT):
Include a wall section with enough clear, unobstructed space to display artwork. This doesn't need to be completely bare - windows nearby, some shelving to the side, or minor decor elements are fine - but ensure there is:
- A clear wall area suitable for displaying a framed art print (roughly 1/4 of the image width or more)
- A neutral or complementary wall color (white, light gray, soft beige, or muted tone)
- Even lighting on the wall area without harsh shadows or bright spots
- The wall positioned as a natural focal point where art would typically hang
- No artwork, empty framing nor canvas present on the wall

STEP 3 - CAMERA AND COMPOSITION:
{$cameraPositioning}, captured with a {$this->cameraLens}. {$this->composition}. Frame the shot so the wall area is prominently visible and would naturally draw the eye as a place to hang art.

STEP 4 - LIGHTING AND ATMOSPHERE:
Illuminate the scene with {$this->lighting}, creating a {$this->mood} atmosphere. Ensure the wall area designated for artwork is well-lit and visible.
PROMPT;

        if (!empty($this->styling)) {
            $prompt .= "\n\nSTYLING: The space should appear {$this->styling}. Include appropriate accessories, plants, textiles, and personal touches that make it feel like a real home, while maintaining adequate clear wall space for artwork.";
        }

        if ($this->colorPalette) {
            $prompt .= "\n\nCOLOR PALETTE: The overall color scheme features {$this->colorPalette}, which should complement rather than compete with potential artwork.";
        }

        if (!empty($this->customDetails)) {
            $prompt .= "\n\nADDITIONAL DETAILS: {$this->customDetails}";
        }

        $prompt .= <<<QUALITY

TECHNICAL REQUIREMENTS:
- Photorealistic quality with sharp focus throughout
- Professional interior photography standards
- Proper exposure with no blown highlights or crushed shadows
- High resolution detail suitable for commercial use
- Natural, believable lighting that enhances the space
- Realistic textures and details
QUALITY;
//TODO the above could be tweaked - Maybe focus shouldn't be sharp, but soft, etc. (Could easily be added to the
// generationConfig)
        return $prompt;
    }

    public function reset(): self
    {
        $this->sceneType = '';
        $this->sceneTypeDescription = '';
        $this->perspective = '';
        $this->cameraAngle = '';
        $this->cameraLens = '';
        $this->interiorStyle = '';
        $this->lighting = '';
        $this->styling = '';
        $this->photographyStyle = '';
        $this->mood = 'professional';
        $this->colorPalette = null;
        $this->composition = 'balanced';
        $this->customDetails = '';
        return $this;
    }

    /**
     * Validate that required fields are set
     * @throws RuntimeException If validation fails
     */
    private function validate(): void
    {
        if (empty($this->sceneType)) {
            throw new RuntimeException('Scene type must be set before building prompt');
        }
    }
}
