<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Queue\Handler;

use CMaintz\ImageAi\Queue\Message\GenerateSceneMessage;
use CMaintz\ImageAi\Service\BatchJobService;
use CMaintz\ImageAi\Orchestrator\SceneGenerationOrchestrator;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler(handles: GenerateSceneMessage::class)]
final class GenerateSceneHandler
{
    public function __construct(
        private readonly SceneGenerationOrchestrator $sceneGenerationService,
        private readonly BatchJobService $batchJobService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(GenerateSceneMessage $message): void
    {
        $context = Context::createCLIContext();
        $batchJobId = $message->batchJobId;
        $config = $message->config;

        $this->batchJobService->markProcessing($batchJobId, $context);

        try {
            $result = $this->sceneGenerationService->generateSceneImages($config, $context);

            if ($result['success']) {
                $successCount = count($result['pendingImages'] ?? []);

                $this->batchJobService->incrementProgress(
                    id: $batchJobId,
                    processedItems: $successCount,
                    successCount: $successCount,
                    failureCount: 0,
                    context: $context
                );

                $this->batchJobService->markCompleted($batchJobId, $context);
            } else {
                $errors = $result['errors'] ?? ['Unknown error'];
                $errorMessage = is_array($errors) ? implode(', ', $errors) : (string) $errors;

                $this->batchJobService->markFailed($batchJobId, $errorMessage, $context);

                $this->logger->error('[CMaintzImageAi] Scene generation job failed', [
                    'batchJobId' => $batchJobId,
                    'errors' => $errors,
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('[CMaintzImageAi] Scene generation job exception', [
                'batchJobId' => $batchJobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->batchJobService->markFailed($batchJobId, $e->getMessage(), $context);
        }
    }
}
