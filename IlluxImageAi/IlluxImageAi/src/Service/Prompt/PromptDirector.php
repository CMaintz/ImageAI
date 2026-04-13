<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Prompt;

use Illux\ImageAi\Builder\Prompt\BatchAnalysisPromptBuilder;
use Illux\ImageAi\Builder\Prompt\CompositionPromptBuilder;
use Illux\ImageAi\Builder\Prompt\ScenePromptBuilder;
use Illux\ImageAi\Builder\Prompt\SystemInstructionBuilder;
use Illux\ImageAi\Config\ContentConfiguration;
use Illux\ImageAi\DTO\FrameData;
use Illux\ImageAi\Service\LanguageConfigurationService;

/**
 * Director for all prompt building operations.
 *
 * Implements the Director pattern to orchestrate specialized prompt builders.
 * Provides a single entry point for constructing prompts across all AI operations:
 * - Batch product analysis
 * - Image composition
 * - Scene generation
 */
class PromptDirector
{
    public function __construct(
        private readonly LanguageConfigurationService $languageConfigService,
        private readonly BatchAnalysisPromptBuilder $batchAnalysisBuilder,
        private readonly SystemInstructionBuilder $instructionBuilder,
        private readonly CompositionPromptBuilder $compositionBuilder,
        private readonly ScenePromptBuilder $sceneBuilder
    ) {
    }

    /**
     * Build the user-facing prompt for batch analysis
     * @param array $languages Array of language codes (e.g., ['en-GB', 'da-DK'])
     * @param int $productCount Number of products being analyzed
     * @param ContentConfiguration $contentConfig Content configuration (with any overrides already applied)
     * @return string Complete prompt text
     */
    public function buildBatchPrompt(
        array $languages,
        int $productCount,
        ContentConfiguration $contentConfig
    ): string {
        $languageNames = array_map(
            fn($code) => $this->languageConfigService->getLanguageName($code) . " ($code)",
            $languages
        );

        return $this->batchAnalysisBuilder
            ->reset()
            ->setLanguages($languages)
            ->setLanguageNames($languageNames)
            ->setProductCount($productCount)
            ->setContentConfig($contentConfig)
            ->build();
    }

    /**
     * Build the system instruction for batch analysis
     *
     * @return string System instruction text
     */
    public function buildBatchInstruction(): string
    {
        return $this->instructionBuilder
            ->reset()
            ->forBatchAnalysis()
            ->build();
    }

    /**
     * Build prompt for compositing artwork/wallpaper into a room scene
     *
     * @param string $promptType Type of composition: 'artwork' (default) or 'wallpaper'
     * @param array<string, mixed> $options Product options (frame, material, size, etc.)
     * @param array{width: int, height: int, unit: string}|null $dimensions Parsed dimensions
     * @param FrameData|null $frameData Frame reference data including images and dimensions
     * @return string Complete composition prompt
     */
    public function buildCompositionPrompt(
        string $promptType,
        array $options,
        ?array $dimensions = null,
        ?FrameData $frameData = null
    ): string {
        $this->compositionBuilder
            ->reset()
            ->setOptions($options)
            ->setDimensions($dimensions)
            ->setFrameData($frameData);

        return match ($promptType) {
            'wallpaper' => $this->compositionBuilder->buildWallpaper(),
            default => $this->compositionBuilder->build(),
        };
    }

    /**
     * Build prompt for generating an environment scene
     * @param array $sceneConfig Scene configuration with all parameters
     * @return string Complete scene generation prompt
     */
    public function buildScenePrompt(array $sceneConfig): string
    {
        $builder = $this->sceneBuilder->reset();

        if (isset($sceneConfig['sceneType'])) {
            $builder->setSceneType($sceneConfig['sceneType']);
        }
        if (isset($sceneConfig['sceneTypeDescription'])) {
            $builder->setSceneTypeDescription($sceneConfig['sceneTypeDescription']);
        }
        if (isset($sceneConfig['perspective'])) {
            $builder->setPerspective($sceneConfig['perspective']);
        }
        if (isset($sceneConfig['cameraAngle'])) {
            $builder->setCameraAngle($sceneConfig['cameraAngle']);
        }
        if (isset($sceneConfig['cameraLens'])) {
            $builder->setCameraLens($sceneConfig['cameraLens']);
        }
        if (isset($sceneConfig['interiorStyle'])) {
            $builder->setInteriorStyle($sceneConfig['interiorStyle']);
        }
        if (isset($sceneConfig['lighting'])) {
            $builder->setLighting($sceneConfig['lighting']);
        }
        if (isset($sceneConfig['mood'])) {
            $builder->setMood($sceneConfig['mood']);
        }
        if (isset($sceneConfig['colorPalette'])) {
            $builder->setColorPalette($sceneConfig['colorPalette']);
        }
        if (isset($sceneConfig['composition'])) {
            $builder->setComposition($sceneConfig['composition']);
        }
        if (isset($sceneConfig['styling'])) {
            $builder->setStyling($sceneConfig['styling']);
        }
        if (isset($sceneConfig['style'])) {
            $builder->setPhotographyStyle($sceneConfig['style']);
        }
        if (isset($sceneConfig['customDetails'])) {
            $builder->setCustomDetails($sceneConfig['customDetails']);
        }

        return $builder->build();
    }

    /**
     * Build system instruction for scene generation
     * @return string System instruction text
     */
    public function buildSceneInstruction(): string
    {
        return $this->instructionBuilder
            ->reset()
            ->forSceneGeneration()
            ->build();
    }

    /**
     * Get the composition builder for direct manipulation
     * Use when the standard buildCompositionPrompt() is insufficient.
     */
    public function getCompositionBuilder(): CompositionPromptBuilder
    {
        return $this->compositionBuilder;
    }

    /**
     * Get the scene builder for direct manipulation
     * Use when the standard buildScenePrompt() is insufficient.
     */
    public function getSceneBuilder(): ScenePromptBuilder
    {
        return $this->sceneBuilder;
    }
}
