<?php declare(strict_types=1);

namespace Illux\ImageAi\Controller\Administration;

use Illux\ImageAi\Config\IlluxConfiguration;
use Illux\ImageAi\Config\PluginConstants;
use Illux\ImageAi\Queue\Message\AnalyzeBatchMessage;
use Illux\ImageAi\Service\Analysis\ProductAnalysisService;
use Illux\ImageAi\Service\Analysis\SuggestedPropertyOptionsService;
use Illux\ImageAi\Service\Analysis\TimeStatisticsService;
use Illux\ImageAi\Service\BatchJobService;
use Illux\ImageAi\Trait\ControllerResponseTrait;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use Throwable;

#[Route(defaults: ['_routeScope' => ['api']])]
class AnalysisController extends AbstractController
{
    use ControllerResponseTrait;

    public function __construct(
        private readonly ProductAnalysisService $productAnalysisService,
        private readonly IlluxConfiguration $illuxConfiguration,
        private readonly LoggerInterface $logger,
        private readonly TimeStatisticsService $timeStatisticsService,
        private readonly BatchJobService $batchJobService,
        private readonly MessageBusInterface $messageBus,
        private readonly SuggestedPropertyOptionsService $suggestedOptionService
    ) {
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/analyze-product/{productId}',
        name: 'api.action.illux_ai_tools.analyze_product',
        methods: ['POST']
    )]
    public function analyzeProduct(string $productId, Context $context): JsonResponse
    {
        try {
            $result = $this->batchJobService->createAnalysisJob(
                productIds: [$productId],
                context: $context
            );
            $batchJob = $result['job'];

            $this->messageBus->dispatch(new AnalyzeBatchMessage(
                batchJobId: $batchJob->id,
                productIds: [$productId],
                analysisResultMapping: $result['analysisResultMapping']
            ));

            return $this->successResponse([
                'message' => 'Analysis queued',
                'batchJobId' => $batchJob->id,
                'totalProducts' => 1,
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Analysis:Single', ['productId' => $productId]);
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/analyze-products',
        name: 'api.action.illux_ai_tools.analyze_products',
        methods: ['POST']
    )]
    public function analyzeProducts(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->getJsonBody($request);
            $productIds = $data['productIds'] ?? [];

            if (empty($productIds)) {
                return $this->errorResponse('No product IDs provided', 400);
            }

            if (count($productIds) > PluginConstants::MAX_PRODUCTS_PER_ADMIN_REQUEST) {
                return $this->errorResponse(sprintf(
                    'Too many products. Maximum %d products per request,
                    got %d. Use analyze-all-products for larger batches.',
                    PluginConstants::MAX_PRODUCTS_PER_ADMIN_REQUEST,
                    count($productIds)
                ), 400);
            }

            $result = $this->batchJobService->createAnalysisJob(
                productIds: $productIds,
                context: $context
            );
            $batchJob = $result['job'];

            $this->messageBus->dispatch(new AnalyzeBatchMessage(
                batchJobId: $batchJob->id,
                productIds: $productIds,
                analysisResultMapping: $result['analysisResultMapping']
            ));

            return $this->successResponse([
                'message' => 'Analysis queued',
                'batchJobId' => $batchJob->id,
                'totalProducts' => count($productIds),
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Analysis:Batch');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/analyze-all-products',
        name: 'api.action.illux_ai_tools.analyze_all_products',
        methods: ['POST']
    )]
    public function analyzeAllProducts(Request $request, Context $context): JsonResponse
    {
        try {
            // Fast path: return early if a job is already active
            $existingJob = $this->batchJobService->getActiveAnalysisJob($context);
            if ($existingJob !== null) {
                return $this->successResponse([
                    'message' => 'Analysis job already running',
                    'totalProducts' => 0,
                    'existingJobId' => $existingJob->id,
                ]);
            }

            $data = $this->getJsonBody($request);
            $overrideFilters = $data['filters'] ?? [];
            $includeAnalyzed = $overrideFilters['includeAnalyzed'] ?? false;
            $productIds = $this->productAnalysisService->findEligibleProductIds($context, $includeAnalyzed);

            if (empty($productIds)) {
                return $this->successResponse([
                    'message' => 'No eligible products found',
                    'totalProducts' => 0,
                ]);
            }

            $contentConfig = $this->illuxConfiguration->getContentConfig();
            $metadataFilters = [
                'includeDescription' =>
                    $overrideFilters['includeDescription'] ?? $contentConfig->includeProductDescription,
                'includeSeoAnalysis' => $overrideFilters['includeSeo'] ?? $contentConfig->includeSeoAnalysis,
            ];

            // Atomic create under advisory lock — prevents duplicate jobs from concurrent requests
            $result = $this->batchJobService->createAnalysisJobIfNoneActive(
                productIds: $productIds,
                context: $context,
                metadataFilters: $metadataFilters
            );

            if ($result === null) {
                $existingJob = $this->batchJobService->getActiveAnalysisJob($context);
                return $this->successResponse([
                    'message' => 'Analysis job already running',
                    'totalProducts' => 0,
                    'existingJobId' => $existingJob?->id,
                ]);
            }

            $batchJob = $result['job'];

            $this->messageBus->dispatch(new AnalyzeBatchMessage(
                batchJobId: $batchJob->id,
                productIds: $productIds,
                analysisResultMapping: $result['analysisResultMapping'],
                metadataFilters: $metadataFilters
            ));

            return $this->successResponse([
                'message' => 'Analysis queued',
                'batchJobId' => $batchJob->id,
                'totalProducts' => count($productIds),
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Analysis:All');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/batch-job/{id}',
        name: 'api.action.illux_ai_tools.batch_job_status',
        methods: ['GET']
    )]
    public function getBatchJobStatus(string $id, Context $context): JsonResponse
    {
        try {
            // Fresh DB read — incrementProgress uses raw SQL that bypasses the DAL cache
            $job = $this->batchJobService->getFreshProgress($id);

            if ($job === null) {
                return $this->errorResponse('Batch job not found', 404);
            }

            $totalItems = (int) $job['totalItems'];
            $processedItems = (int) $job['processedItems'];
            $processingCount = (int) ($job['processingCount'] ?? 0);
            $percentage = $totalItems > 0 ? round(($processedItems / $totalItems) * 100, 2) : 0;

            return new JsonResponse([
                'id' => $job['id'],
                'type' => $job['type'],
                'status' => $job['status'],
                'totalItems' => $totalItems,
                'processedItems' => $processedItems,
                'successCount' => (int) $job['successCount'],
                'failureCount' => (int) $job['failureCount'],
                'processingCount' => $processingCount,
                'percentage' => $percentage,
                'errorMessage' => $job['errorMessage'],
                'startedAt' => $job['startedAt'],
                'completedAt' => $job['completedAt'],
                'createdAt' => $job['createdAt'],
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'BatchJob:Status', ['id' => $id]);
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/analysis-stats',
        name: 'api.action.illux_ai_tools.analysis_stats',
        methods: ['GET']
    )]
    public function getAnalysisStats(Context $context): JsonResponse
    {
        try {
            $analysisStats = $this->productAnalysisService->generateAnalysisStats($context);
            return $this->successResponse(['stats' => $analysisStats]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Analysis:Stats');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/active-job',
        name: 'api.action.illux_ai_tools.active_job',
        methods: ['GET']
    )]
    public function getActiveJob(Context $context): JsonResponse
    {
        try {
            $activeJob = $this->batchJobService->getActiveAnalysisJob($context);
            $progress = $this->productAnalysisService->getAnalysisProgress($context);

            if ($activeJob === null) {
                return new JsonResponse([
                    'hasActiveJob' => false,
                    'progress' => $progress,
                ]);
            }

            return new JsonResponse([
                'hasActiveJob' => true,
                'job' => [
                    'id' => $activeJob->id,
                    'type' => $activeJob->type->value,
                    'status' => $activeJob->status->value,
                    'totalItems' => $activeJob->totalItems,
                    'processedItems' => $activeJob->processedItems,
                    'successCount' => $activeJob->successCount,
                    'failureCount' => $activeJob->failureCount,
                    'percentage' => $activeJob->getPercentage(),
                    'startedAt' => $activeJob->startedAt?->format('c'),
                    'createdAt' => $activeJob->getCreatedAt()?->format('c'),
                ],
                'progress' => $progress,
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'ActiveJob');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/suggested-options',
        name: 'api.action.illux_ai_tools.suggested_options',
        methods: ['GET']
    )]
    public function getSuggestedOptions(Context $context): JsonResponse
    {
        try {
            $result = $this->suggestedOptionService->getSuggestedOptions($context);

            return $this->successResponse([
                'suggestions' => $result['suggestions'],
                'totalSuggestions' => $result['totalSuggestions'],
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'SuggestedOptions:Get');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/suggested-options/approve',
        name: 'api.action.illux_ai_tools.approve_suggested_options',
        methods: ['POST']
    )]
    public function approveSuggestedOptions(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->getJsonBody($request);
            $optionsToCreate = $data['options'] ?? [];

            if (empty($optionsToCreate)) {
                return $this->errorResponse('No options provided', 400);
            }

            $result = $this->suggestedOptionService->approveAndCreateOptions($optionsToCreate, $context);

            return $this->successResponse([
                'created' => $result['created'],
                'failed' => $result['failed'],
                'errors' => $result['errors'],
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'SuggestedOptions:Approve');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/suggested-options/reject',
        name: 'api.action.illux_ai_tools.reject_suggested_option',
        methods: ['POST']
    )]
    public function rejectSuggestedOption(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->getJsonBody($request);
            $propertyGroup = $data['propertyGroup'] ?? '';
            $optionName = $data['optionName'] ?? '';

            if (empty($propertyGroup) || empty($optionName)) {
                return $this->errorResponse('Property group and option name are required', 400);
            }

            $updatedCount = $this->suggestedOptionService->rejectSuggestion($propertyGroup, $optionName, $context);

            return $this->successResponse(['updatedResults' => $updatedCount]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'SuggestedOptions:Reject');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/time-statistics',
        name: 'api.action.illux_ai_tools.time_statistics',
        methods: ['GET']
    )]
    public function getTimeStatistics(Context $context): JsonResponse
    {
        try {
            $statistics = $this->timeStatisticsService->calculateTotalTimeSaved($context);

            return $this->successResponse(['data' => $statistics]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'TimeStatistics');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/stop-analysis/{batchJobId}',
        name: 'api.action.illux_ai_tools.stop_analysis',
        methods: ['POST']
    )]
    public function stopAnalysis(string $batchJobId, Context $context): JsonResponse
    {
        try {
            $batchJob = $this->batchJobService->get($batchJobId, $context);

            if ($batchJob === null) {
                return $this->errorResponse('Batch job not found', 404);
            }

            if (!in_array($batchJob->status->value, ['queued', 'processing'], true)) {
                return $this->errorResponse('Cannot cancel job with status: ' . $batchJob->status->value, 400);
            }

            $this->batchJobService->markCancelled($batchJobId, $context);

            return $this->successResponse(['message' => 'Analysis job cancelled']);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Analysis:Stop', ['batchJobId' => $batchJobId]);
        }
    }
}
