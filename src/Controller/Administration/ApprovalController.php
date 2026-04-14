<?php declare(strict_types=1);

namespace Illux\ImageAi\Controller\Administration;

use Illux\ImageAi\Service\Approval\AnalysisApprovalService;
use Illux\ImageAi\Service\Approval\SceneImageApprovalService;
use Illux\ImageAi\Trait\ControllerResponseTrait;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;

/**
 * Unified controller for all approval/rejection workflows.
 *
 * Handles approvals for:
 * - AI analysis results (product metadata)
 * - Generated scene images
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ApprovalController extends AbstractController
{
    use ControllerResponseTrait;

    public function __construct(
        private readonly AnalysisApprovalService $analysisApprovalService,
        private readonly SceneImageApprovalService $sceneImageApprovalService,
        private readonly LoggerInterface $logger
    ) {
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/approval/analysis/approve',
        name: 'api.action.illux_ai_tools.approval.analysis.approve',
        methods: ['POST']
    )]
    public function approveAnalysisResults(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->getJsonBody($request);
            $ids = $this->requireArrayParam($data, 'ids');

            $result = $this->analysisApprovalService->approveResults($ids, $context);

            return $this->successResponse([
                'successCount' => $result['successCount'],
                'failureCount' => $result['failureCount'],
                'errors' => $result['errors'],
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Approval:Analysis:Approve');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/approval/analysis/reject',
        name: 'api.action.illux_ai_tools.approval.analysis.reject',
        methods: ['POST']
    )]
    public function rejectAnalysisResults(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->getJsonBody($request);
            $ids = $this->requireArrayParam($data, 'ids');

            $result = $this->analysisApprovalService->rejectResults($ids, $context);

            return $this->successResponse(['rejectedCount' => $result['rejectedCount']]);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Approval:Analysis:Reject');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/approval/scene-image/approve',
        name: 'api.action.illux_ai_tools.approval.scene_image.approve',
        methods: ['POST']
    )]
    public function approveSceneImage(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->getJsonBody($request);
            $pendingImageId = $this->requireStringParam($data, 'pendingImageId');
            $targetFolderId = $this->optionalStringParam($data, 'targetFolderId');

            $result = $this->sceneImageApprovalService->approvePendingImage(
                $pendingImageId,
                $targetFolderId,
                $context
            );

            if ($result['success']) {
                return $this->successResponse(['mediaId' => $result['mediaId']]);
            }

            return $this->errorResponse($result['error'] ?? 'Scene image approval failed', 400);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Approval:SceneImage:Approve');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/approval/scene-image/reject',
        name: 'api.action.illux_ai_tools.approval.scene_image.reject',
        methods: ['POST']
    )]
    public function rejectSceneImage(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->getJsonBody($request);
            $pendingImageId = $this->requireStringParam($data, 'pendingImageId');

            $success = $this->sceneImageApprovalService->rejectPendingImage($pendingImageId, $context);

            return $this->successResponse(['rejected' => $success]);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Approval:SceneImage:Reject');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/approval/scene-image/batch-approve',
        name: 'api.action.illux_ai_tools.approval.scene_image.batch_approve',
        methods: ['POST']
    )]
    public function batchApproveSceneImages(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->getJsonBody($request);
            $approvals = $this->requireArrayParam($data, 'approvals');

            $result = $this->sceneImageApprovalService->batchApprovePendingImages($approvals, $context);

            return $this->successResponse([
                'successCount' => $result['successCount'],
                'failureCount' => $result['failureCount'],
                'results' => $result['results'],
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Approval:SceneImage:BatchApprove');
        }
    }

    #[Route(
        path: '/api/_action/illux-ai-tools/approval/scene-image/batch-reject',
        name: 'api.action.illux_ai_tools.approval.scene_image.batch_reject',
        methods: ['POST']
    )]
    public function batchRejectSceneImages(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->getJsonBody($request);
            $pendingImageIds = $this->requireArrayParam($data, 'pendingImageIds');

            $result = $this->sceneImageApprovalService->batchRejectPendingImages($pendingImageIds, $context);

            return $this->successResponse(['rejectedCount' => $result['rejectedCount']]);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Approval:SceneImage:BatchReject');
        }
    }
}
