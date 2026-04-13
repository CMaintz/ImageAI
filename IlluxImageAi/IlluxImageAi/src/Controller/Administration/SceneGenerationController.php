<?php declare(strict_types=1);

namespace Illux\ImageAi\Controller\Administration;

use Illux\ImageAi\Queue\Message\GenerateSceneMessage;
use Illux\ImageAi\Service\BatchJobService;
use Illux\ImageAi\Orchestrator\SceneGenerationOrchestrator;
use Illux\ImageAi\Service\Media\MediaFolderScanner;
use Illux\ImageAi\Service\SceneGeneration\SceneGenerationConfigService;
use Illux\ImageAi\Trait\ControllerResponseTrait;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;

#[Route(defaults: ['_routeScope' => ['api']])]
class SceneGenerationController extends AbstractController
{
    use ControllerResponseTrait;

    public function __construct(
        private readonly SceneGenerationOrchestrator $generationService,
        private readonly MediaFolderScanner $folderScanner,
        private readonly SceneGenerationConfigService $configService,
        private readonly LoggerInterface $logger,
        private readonly BatchJobService $batchJobService,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Get available scene types from media folder structure
     */
    #[Route(
        path: '/api/_action/illux-ai-tools/scene-types',
        name: 'api.action.illux_ai_tools.scene_types',
        methods: ['GET']
    )]
    public function getSceneTypes(Context $context): JsonResponse
    {
        try {
            $sceneTypes = $this->folderScanner->getAvailableSceneTypes($context);

            return $this->successResponse(['sceneTypes' => $sceneTypes]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'SceneGeneration:Types');
        }
    }

    /**
     * Get configuration options for scene generation
     */
    #[Route(
        path: '/api/_action/illux-ai-tools/scene-generation-options',
        name: 'api.action.illux_ai_tools.scene_generation_options',
        methods: ['GET']
    )]
    public function getGenerationOptions(Context $context): JsonResponse
    {
        try {
            $config = $this->configService->ensureConfigExists($context);

            return $this->successResponse([
                'options' => [
                    'sceneTypeOptions' => $config->sceneTypeOptions,
                    'cameraLensOptions' => $config->cameraLensOptions,
                    'cameraAngleOptions' => $config->cameraAngleOptions,
                    'perspectiveOptions' => $config->perspectiveOptions,
                    'interiorStyleOptions' => $config->interiorStyleOptions,
                    'lightingOptions' => $config->lightingOptions,
                    'styleOptions' => $config->styleOptions,
                    'stylingOptions' => $config->stylingOptions,
                    'aspectRatioOptions' => $config->aspectRatioOptions,
                    'moodOptions' => $config->moodOptions,
                    'colorPaletteOptions' => $config->colorPaletteOptions,
                    'compositionOptions' => $config->compositionOptions,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'SceneGeneration:Options');
        }
    }

    /**
     * Generate scene images (async via queue)
     * Dispatches scene generation to the message queue for background processing.
     * Returns immediately with a batchJobId for progress polling.
     */
    #[Route(
        path: '/api/_action/illux-ai-tools/generate-scene-images',
        name: 'api.action.illux_ai_tools.generate_scenes',
        methods: ['POST']
    )]
    public function generateSceneImages(Request $request, Context $context): JsonResponse
    {
        try {
            $config = $request->request->all();

            $sceneTypes = $config['sceneTypes'] ?? [];
            $totalItems = is_array($sceneTypes) ? count($sceneTypes) : 0;

            if ($totalItems === 0) {
                return $this->errorResponse('No scene types selected', 400);
            }

            $batchJob = $this->batchJobService->createSceneGenerationJob(
                config: $config,
                totalItems: $totalItems,
                context: $context
            );

            $this->messageBus->dispatch(new GenerateSceneMessage(
                batchJobId: $batchJob->id,
                config: $config
            ));

            return $this->successResponse([
                'message' => 'Scene generation queued',
                'batchJobId' => $batchJob->id,
                'totalItems' => $totalItems,
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'SceneGeneration:Generate');
        }
    }

    /**
     * Get all pending scene images
     */
    #[Route(
        path: '/api/_action/illux-ai-tools/pending-scene-images',
        name: 'api.action.illux_ai_tools.pending_scene_images',
        methods: ['GET']
    )]
    public function getPendingImages(Context $context): JsonResponse
    {
        try {
            $images = $this->generationService->getPendingImages($context);

            return $this->successResponse(['images' => $images]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'SceneGeneration:PendingImages');
        }
    }

    /**
     * Get scene generation configuration (for editing in admin)
     */
    #[Route(
        path: '/api/_action/illux-ai-tools/scene-generation-config',
        name: 'api.action.illux_ai_tools.get_scene_generation_config',
        methods: ['GET']
    )]
    public function getConfig(Context $context): JsonResponse
    {
        try {
            $config = $this->configService->ensureConfigExists($context);

            return $this->successResponse([
                'config' => [
                    'id' => $config->id,
                    'sceneTypeOptions' => $config->sceneTypeOptions,
                    'cameraLensOptions' => $config->cameraLensOptions,
                    'cameraAngleOptions' => $config->cameraAngleOptions,
                    'perspectiveOptions' => $config->perspectiveOptions,
                    'interiorStyleOptions' => $config->interiorStyleOptions,
                    'lightingOptions' => $config->lightingOptions,
                    'styleOptions' => $config->styleOptions,
                    'stylingOptions' => $config->stylingOptions,
                    'aspectRatioOptions' => $config->aspectRatioOptions,
                    'moodOptions' => $config->moodOptions,
                    'colorPaletteOptions' => $config->colorPaletteOptions,
                    'compositionOptions' => $config->compositionOptions,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'SceneGeneration:GetConfig');
        }
    }

    /**
     * Update scene generation configuration
     */
    #[Route(
        path: '/api/_action/illux-ai-tools/scene-generation-config',
        name: 'api.action.illux_ai_tools.update_scene_generation_config',
        methods: ['POST']
    )]
    public function updateConfig(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $request->request->all();

            $this->configService->updateConfig($data, $context);

            return $this->successResponse();
        } catch (Throwable $e) {
            return $this->handleException($e, 'SceneGeneration:UpdateConfig');
        }
    }

    /**
     * Get prompt preview based on current configuration
     * Uses ScenePromptBuilder to generate the exact prompt that would be sent
     * to Gemini, ensuring the preview stays in sync with actual generation.
     */
    #[Route(
        path: '/api/_action/illux-ai-tools/prompt-preview',
        name: 'api.action.illux_ai_tools.prompt_preview',
        methods: ['POST']
    )]
    public function getPromptPreview(Request $request): JsonResponse
    {
        try {
            $config = $request->request->all();
            $preview = $this->generationService->buildPromptPreview($config);

            return $this->successResponse(['preview' => $preview]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'SceneGeneration:PromptPreview');
        }
    }
}
