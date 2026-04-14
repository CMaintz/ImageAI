<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Orchestrator;

use CMaintz\ImageAi\DTO\Request\GenerationRequest;
use CMaintz\ImageAi\Api\Gemini\GeminiClient;
use CMaintz\ImageAi\Service\Prompt\PromptDirector;
use InvalidArgumentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use CMaintz\ImageAi\Core\Content\AiPendingSceneImage\AiPendingSceneImageCollection;
use CMaintz\ImageAi\Core\Content\AiPendingSceneImage\AiPendingSceneImageEntity;

/**
 * Service for generating environment/scene images using Gemini 2.5 Flash Image
 *
 * Follows Gemini image generation best practices:
 * - Descriptive narratives over keyword lists
 * - Photographic language (camera angles, lenses, lighting)
 * - Iterative refinement workflow
 * - Scene coherence through detailed descriptions
 *
 * Generated images are stored as pending until approved by admin.
 */
class SceneGenerationOrchestrator
{
    /**
     * @param EntityRepository<AiPendingSceneImageCollection> $pendingSceneImageRepository
     */
    public function __construct(
        private readonly GeminiClient $geminiClient,
        private readonly PromptDirector $promptDirector,
        private readonly EntityRepository $pendingSceneImageRepository,
    ) {
    }

    /**
     * Generate scene images based on configuration
     * @param array $config Generation configuration with scene type, params, and prompt details
     * @param Context $context
     * @return array{success: bool, pendingImages: array, errors: array}
     */
    public function generateSceneImages(array $config, Context $context): array
    {
        $this->validateConfig($config);

        $sceneTypes = $config['sceneTypes'];
        $systemInstruction = $this->promptDirector->buildSceneInstruction();

        $requests = [];
        $prompts = [];
        foreach ($sceneTypes as $sceneTypeData) {
            $sceneConfig = array_merge($config, [
                'sceneType' => $sceneTypeData['label'],
                'sceneTypeDescription' => $sceneTypeData['description'],
                'customDetails' => $config['additionalDetails'] ?? $config['customDetails'] ?? '',
            ]);

            $prompt = $this->promptDirector->buildScenePrompt($sceneConfig);
            $prompts[$sceneTypeData['label']] = $prompt;

            $requests[$sceneTypeData['label']] = new GenerationRequest(
                prompt: $prompt,
                systemInstruction: $systemInstruction,
                aspectRatio: $config['aspectRatio'] ?? '16:9',
                numberOfImages: 1,
            );
        }

        $results = $this->geminiClient->generateImages($requests);

        $pendingImages = [];
        $errors = [];

        foreach ($results as $sceneType => $result) {
            if ($result['success'] && !empty($result['images'])) {
                foreach ($result['images'] as $imageData) {
                    $pendingImages[] = [
                        'id' => Uuid::randomHex(),
                        'sceneType' => $sceneType,
                        'imageData' => $imageData['base64'],
                        'mimeType' => $imageData['mimeType'] ?? 'image/png',
                        'prompt' => $prompts[$sceneType],
                        'systemInstruction' => $systemInstruction,
                        'generationParams' => [
                            'aspectRatio' => $config['aspectRatio'] ?? '16:9',
                            'numberOfImages' => 1,
                        ],
                        'config' => $config,
                        'status' => 'pending',
                    ];
                }
            } else {
                $errors[] = "Failed for '{$sceneType}': " . ($result['error'] ?? 'Unknown error');
            }
        }

        if (!empty($pendingImages)) {
            $this->pendingSceneImageRepository->create($pendingImages, $context);
        }

        return [
            'success' => !empty($pendingImages),
            'pendingImages' => $pendingImages,
            'errors' => $errors,
        ];
    }

    /**
     * Build a prompt preview without generating images
     * Uses the same PromptDirector logic as actual generation,
     * ensuring the preview matches what will be sent to the API.
     * @param array $config Generation configuration
     * @return array{prompt: string, systemInstruction: string}
     */
    public function buildPromptPreview(array $config): array
    {
        $sceneTypeData = $config['sceneTypes'][0];

        $sceneConfig = array_merge($config, [
            'sceneType' => $sceneTypeData['label'],
            'sceneTypeDescription' => $sceneTypeData['description'],
            'customDetails' => $config['additionalDetails'] ?? $config['customDetails'] ?? '',
        ]);

        return [
            'prompt' => $this->promptDirector->buildScenePrompt($sceneConfig),
            'systemInstruction' => $this->promptDirector->buildSceneInstruction(),
        ];
    }

    /**
     * Get pending scene images for approval
     * @param Context $context
     * @param int $limit Maximum number of images to return
     * @return array Array of formatted pending image data
     */
    public function getPendingImages(Context $context, int $limit = 50): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('status', 'pending'));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit($limit);

        $pendingImages = $this->pendingSceneImageRepository->search($criteria, $context)->getEntities();

        $imagesData = [];
        /** @var AiPendingSceneImageEntity $image */
        foreach ($pendingImages as $image) {
            $imagesData[] = [
                'id' => $image->id,
                'sceneType' => $image->sceneType,
                'imageData' => 'data:' . $image->mimeType . ';base64,' . $image->imageData,
                'prompt' => $image->prompt,
                'config' => $image->config,
                'status' => $image->status,
                'generatedAt' => $image->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
        }

        return $imagesData;
    }

    /**
     * Validate generation configuration
     * @param array $config
     * @throws InvalidArgumentException If config is invalid
     */
    private function validateConfig(array $config): void
    {
        if (empty($config['sceneTypes']) || !is_array($config['sceneTypes']) || count($config['sceneTypes']) === 0) {
            throw new InvalidArgumentException('At least one scene type is required');
        }

        foreach ($config['sceneTypes'] as $sceneType) {
            if (!is_array($sceneType) || empty($sceneType['label'])) {
                throw new InvalidArgumentException('Each scene type must have a label');
            }
        }

        $validAspectRatios = ['1:1', '16:9', '9:16', '4:3', '3:4'];
        $aspectRatio = $config['aspectRatio'] ?? '16:9';
        if (!in_array($aspectRatio, $validAspectRatios)) {
            throw new InvalidArgumentException('Invalid aspect ratio: ' . $aspectRatio);
        }
    }
}
