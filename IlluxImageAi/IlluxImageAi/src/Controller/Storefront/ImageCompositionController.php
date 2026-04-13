<?php declare(strict_types=1);

namespace Illux\ImageAi\Controller\Storefront;

use Illux\ImageAi\Orchestrator\CompositionOrchestrator;
use Illux\ImageAi\Service\Media\UserUploadedImageResolver;
use Illux\ImageAi\Trait\ControllerResponseTrait;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ImageCompositionController extends StorefrontController
{
    use ControllerResponseTrait;

    public function __construct(
        private readonly CompositionOrchestrator $compositorService,
        private readonly UserUploadedImageResolver $userImageResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    #[Route(
        path: '/ai/compose/artwork',
        name: 'frontend.ai.compose.artwork',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function composeArtwork(Request $request, SalesChannelContext $context): JsonResponse
    {
        set_time_limit(200);

        try {
            $productId = $request->request->getString('productId');
            if ($productId === '') {
                return $this->errorResponse('Product ID is required', 400);
            }

            [
                $options,
                $dimensions,
                $roomFolders,
                $customEnvironmentImage,
                $customEnvironmentMimeType
            ] = $this->parseCommonParams($request);

            if (empty($roomFolders) && $customEnvironmentImage === null) {
                return $this->errorResponse('No room folders selected and no custom environment provided', 400);
            }

            // Check if client wants SSE streaming mode
            $streaming = $request->request->getBoolean('streaming', false);

            if ($streaming) {
                // Prepare job only (don't execute yet) - execution happens via SSE stream endpoint
                $jobData = $this->compositorService->prepareArtworkCompositionJob(
                    $productId,
                    $options,
                    $dimensions,
                    $roomFolders,
                    $context->getContext(),
                    $customEnvironmentImage,
                    $customEnvironmentMimeType
                );
            } else {
                // Original behavior: prepare AND execute (wait for all)
                $jobData = $this->compositorService->startArtworkCompositionJob(
                    $productId,
                    $options,
                    $dimensions,
                    $roomFolders,
                    $context->getContext(),
                    $customEnvironmentImage,
                    $customEnvironmentMimeType
                );
            }

            return $this->successResponse($jobData);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Artwork');
        }
    }

    #[Route(
        path: '/ai/compose/wallpaper',
        name: 'frontend.ai.compose.wallpaper',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function composeWallpaper(Request $request, SalesChannelContext $context): JsonResponse
    {
        set_time_limit(200);

        try {
            // Resolve user image from GraphicalAssistance or ChiliPublish storage
            $userImage = $this->userImageResolver->resolveFromRequest($request);
            if ($userImage === null) {
                return $this->errorResponse('Please upload an image first', 400);
            }

            [$options, $dimensions, $roomFolders, $customEnvironmentImage, $customEnvironmentMimeType]
                = $this->parseCommonParams($request);

            if (empty($roomFolders) && $customEnvironmentImage === null) {
                return $this->errorResponse('No room folders selected and no custom environment provided', 400);
            }

            $streaming = $request->request->getBoolean('streaming', false);

            if ($streaming) {
                $jobData = $this->compositorService->prepareUserImageCompositionJob(
                    $userImage['base64'],
                    $userImage['mimeType'],
                    'wallpaper',
                    $options,
                    $dimensions,
                    $roomFolders,
                    $context->getContext(),
                    $customEnvironmentImage,
                    $customEnvironmentMimeType
                );
            } else {
                $jobData = $this->compositorService->startUserImageCompositionJob(
                    $userImage['base64'],
                    $userImage['mimeType'],
                    'wallpaper',
                    $options,
                    $dimensions,
                    $roomFolders,
                    $context->getContext(),
                    $customEnvironmentImage,
                    $customEnvironmentMimeType
                );
            }

            return $this->successResponse($jobData);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Wallpaper');
        }
    }

    #[Route(
        path: '/ai/compose/photo',
        name: 'frontend.ai.compose.photo',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function composePhoto(Request $request, SalesChannelContext $context): JsonResponse
    {
        set_time_limit(200);

        try {
            // Resolve user image from GraphicalAssistance or ChiliPublish storage
            $userImage = $this->userImageResolver->resolveFromRequest($request);
            if ($userImage === null) {
                return $this->errorResponse('Please upload an image first', 400);
            }

            [$options, $dimensions, $roomFolders, $customEnvironmentImage, $customEnvironmentMimeType]
                = $this->parseCommonParams($request);

            if (empty($roomFolders) && $customEnvironmentImage === null) {
                return $this->errorResponse('No room folders selected and no custom environment provided', 400);
            }

            $streaming = $request->request->getBoolean('streaming', false);

            if ($streaming) {
                $jobData = $this->compositorService->prepareUserImageCompositionJob(
                    $userImage['base64'],
                    $userImage['mimeType'],
                    'artwork',
                    $options,
                    $dimensions,
                    $roomFolders,
                    $context->getContext(),
                    $customEnvironmentImage,
                    $customEnvironmentMimeType
                );
            } else {
                $jobData = $this->compositorService->startUserImageCompositionJob(
                    $userImage['base64'],
                    $userImage['mimeType'],
                    'artwork',
                    $options,
                    $dimensions,
                    $roomFolders,
                    $context->getContext(),
                    $customEnvironmentImage,
                    $customEnvironmentMimeType
                );
            }

            return $this->successResponse($jobData);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Photo');
        }
    }

    /** @return array{array, array{width: int, height: int, unit: string}|null, array, string|null, string} */
    private function parseCommonParams(Request $request): array
    {
        $optionsJson = $request->request->getString('options');
        $options = $optionsJson !== '' ? json_decode($optionsJson, true) : [];

        $dimensionsJson = $request->request->getString('dimensions');
        $dimensions = $dimensionsJson !== '' ? json_decode($dimensionsJson, true) : null;

        $roomFoldersJson = $request->request->getString('roomFolders');
        $roomFolders = $roomFoldersJson !== '' ? json_decode($roomFoldersJson, true) : [];

        $customEnvironmentImage = $request->request->getString('customEnvironmentImage');
        $customEnvironmentImage = $customEnvironmentImage !== '' ? $customEnvironmentImage : null;
        $customEnvironmentMimeType = $request->request->getString('customEnvironmentMimeType', 'image/jpeg');

        return [$options, $dimensions, $roomFolders, $customEnvironmentImage, $customEnvironmentMimeType];
    }

    /**
     * Poll for composition results.
     *
     * Returns any new results that haven't been sent yet.
     * Since compose() now processes all scenes concurrently,
     * all results are typically available on the first poll.
     */
    #[Route(
        path: '/ai/compose/poll',
        name: 'frontend.ai.compose.poll',
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function poll(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $jobId = $request->query->get('jobId');

            if (!$jobId) {
                return $this->errorResponse('Job ID is required', 400);
            }

            // Get new results from session (no API calls - just reading stored results)
            $result = $this->compositorService->getNewResults($jobId);

            $formattedResults = [];
            foreach ($result['newResults'] as $composition) {
                if ($composition['image']) {
                    $formattedResults[] = [
                        'sceneName' => $composition['sceneName'],
                        'label' => $composition['label'],
                        'image' => 'data:image/png;base64,' . $composition['image'],
                    ];
                } else {
                    $formattedResults[] = [
                        'sceneName' => $composition['sceneName'],
                        'label' => $composition['label'],
                        'image' => null,
                        'error' => $composition['error'] ?? 'Failed to generate composition',
                    ];
                }
            }

            return new JsonResponse([
                'success' => true,
                'status' => $result['status'],
                'total' => $result['total'],
                'completed' => $result['completed'],
                'newResults' => $formattedResults,
            ], 200, [
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Composition:Poll', [
                'jobId' => $request->query->get('jobId'),
            ]);
        }
    }

    /**
     * SSE streaming endpoint for composition results.
     *
     * Streams results as they arrive from the AI, reducing time to first result
     * from ~30-60s (wait for all) to ~8-15s (first scene completes).
     *
     * Event types:
     * - "result": A single composition result {sceneName, label, image, error}
     * - "progress": Progress update {completed, total}
     * - "complete": All processing finished
     * - "error": Fatal error occurred
     */
    #[Route(
        path: '/ai/compose/stream',
        name: 'frontend.ai.compose.stream',
        methods: ['GET']
    )]
    public function streamComposition(Request $request): StreamedResponse
    {
        $jobId = $request->query->getString('jobId');

        $response = new StreamedResponse(function () use ($jobId) {
            // Disable output buffering for streaming
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            // Set time limit for long-running SSE connection
            set_time_limit(300);

            try {
                if ($jobId === '') {
                    $this->sendSSEEvent('error', ['message' => 'Job ID is required']);
                    return;
                }

                // Check if job exists
                try {
                    $status = $this->compositorService->getJobStatus($jobId);
                } catch (Throwable) {
                    $this->sendSSEEvent('error', ['message' => 'Job not found: ' . $jobId]);
                    return;
                }

                // If job is already completed, send all results and close
                if ($status['status'] === 'completed') {
                    $result = $this->compositorService->getNewResults($jobId);
                    foreach ($result['newResults'] as $composition) {
                        $this->sendSSEEvent('result', $this->formatResult($composition));
                    }
                    $this->sendSSEEvent('complete', [
                        'total' => $status['total'],
                        'completed' => $status['completed'],
                    ]);
                    return;
                }

                // Start processing if not already started
                if (!$this->compositorService->isProcessingStarted($jobId)) {
                    // Stream results as they arrive from the generator
                    $completedCount = 0;
                    foreach ($this->compositorService->executeCompositionJobStreaming($jobId) as $result) {
                        $completedCount++;

                        // Send the result immediately
                        $this->sendSSEEvent('result', $this->formatResult($result));

                        // Send progress update
                        $jobStatus = $this->compositorService->getJobStatus($jobId);
                        $this->sendSSEEvent('progress', [
                            'completed' => $completedCount,
                            'total' => $jobStatus['total'],
                        ]);
                    }

                    // Signal completion
                    $finalStatus = $this->compositorService->getJobStatus($jobId);
                    $this->sendSSEEvent('complete', [
                        'total' => $finalStatus['total'],
                        'completed' => $finalStatus['completed'],
                    ]);
                } else {
                    // Processing already started (reconnection case)
                    // Poll for results until complete
                    $lastSent = 0;
                    $maxWait = 180; // 3 minutes
                    $waited = 0;

                    while ($waited < $maxWait) {
                        $result = $this->compositorService->getNewResults($jobId);

                        foreach ($result['newResults'] as $composition) {
                            $this->sendSSEEvent('result', $this->formatResult($composition));
                            $lastSent++;
                        }

                        if ($result['status'] === 'completed') {
                            $this->sendSSEEvent('complete', [
                                'total' => $result['total'],
                                'completed' => $result['completed'],
                            ]);
                            break;
                        }

                        // Wait before next check
                        usleep(200000); // 200ms
                        $waited += 0.2;
                    }

                    if ($waited >= $maxWait) {
                        $this->sendSSEEvent('error', ['message' => 'Timeout waiting for results']);
                    }
                }
            } catch (Throwable $e) {
                $this->logger->error('[IlluxImageAi] SSE stream error', [
                    'jobId' => $jobId,
                    'error' => $e->getMessage(),
                ]);
                $this->sendSSEEvent('error', ['message' => 'Processing error: ' . $e->getMessage()]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable nginx buffering
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /**
     * Send a Server-Sent Event
     */
    private function sendSSEEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Format a composition result for SSE streaming
     */
    private function formatResult(array $composition): array
    {
        if ($composition['image']) {
            return [
                'sceneName' => $composition['sceneName'],
                'label' => $composition['label'],
                'image' => 'data:image/png;base64,' . $composition['image'],
            ];
        }

        return [
            'sceneName' => $composition['sceneName'],
            'label' => $composition['label'],
            'image' => null,
            'error' => $composition['error'] ?? 'Failed to generate composition',
        ];
    }
}
