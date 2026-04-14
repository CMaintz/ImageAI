<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Approval;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Illux\ImageAi\Config\PluginConstants;
use Illux\ImageAi\Core\Content\AiAnalysisResult\Entity\AiAnalysisResultCollection;
use Illux\ImageAi\Core\Content\AiAnalysisResult\Entity\AiAnalysisResultEntity;
use Illux\ImageAi\Model\Enum\AiAnalysisStatusEnum;
use Illux\ImageAi\Service\Analysis\ProductUpdateAssembler;
use Illux\ImageAi\Service\Property\PropertyLookupService;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Throwable;

/**
 * Handles approval/rejection of AI analysis results.
 *
 * When approved, analysis results (descriptions, SEO data, properties)
 * are applied to the corresponding products.
 *
 * Uses database transactions to ensure atomicity of batch operations.
 */
class AnalysisApprovalService
{
    /**
     * @param EntityRepository<AiAnalysisResultCollection<AiAnalysisResultEntity>> $aiAnalysisResultRepository
     * @param EntityRepository<ProductCollection<ProductEntity>> $productRepository
     */
    public function __construct(
        private readonly EntityRepository $aiAnalysisResultRepository,
        private readonly EntityRepository $productRepository,
        private readonly ProductUpdateAssembler $productUpdateAssembler,
        private readonly PropertyLookupService $propertyLookupService,
        private readonly Connection $connection
    ) {
    }

    /**
     * Approve analysis results and apply them to products in batch.
     *
     * Uses database transaction to ensure product updates and status updates
     * are applied atomically - either all succeed or all rollback.
     *
     * @param array $aiAnalysisIds Array of analysis result IDs to approve (max 100)
     * @param Context $context
     * @return array ['successCount' => int, 'failureCount' => int, 'errors' => array]
     * @throws Exception
     */
    public function approveResults(array $aiAnalysisIds, Context $context): array
    {
        if (count($aiAnalysisIds) > PluginConstants::APPROVAL_BATCH_MAX_SIZE) {
            return [
                'successCount' => 0,
                'failureCount' => count($aiAnalysisIds),
                'errors' => [sprintf(
                    'Batch size exceeds maximum of %d. Got %d IDs.',
                    PluginConstants::APPROVAL_BATCH_MAX_SIZE,
                    count($aiAnalysisIds)
                )],
            ];
        }

        $this->propertyLookupService->preloadAllPropertyOptions($context);

        $criteria = new Criteria($aiAnalysisIds);
        $criteria->addAssociation('translations');
        $criteria->addAssociation('product');
        $analysisResults = $this->aiAnalysisResultRepository->search($criteria, $context);

        $productUpdates = [];
        $statusUpdates = [];
        $successCount = 0;
        $failureCount = 0;
        $errors = [];

        /** @var AiAnalysisResultEntity $analysisEntity */
        foreach ($analysisResults as $analysisEntity) {
            try {
                $product = $analysisEntity->product;
                if (!$product) {
                    $errors[] = "Product not found for analysis result: {$analysisEntity->id}";
                    $failureCount++;
                    continue;
                }

                $productUpdate = $this->productUpdateAssembler->assembleFromEntityData(
                    $product->getId(),
                    $analysisEntity->translations ?? [],
                    $analysisEntity->analyzedProperties,
                    $context
                );

                if ($this->productUpdateAssembler->hasUpdateData($productUpdate)) {
                    $productUpdates[] = $productUpdate;
                    $successCount++;
                }

                $statusUpdates[] = [
                    'id' => $analysisEntity->id,
                    'status' => AiAnalysisStatusEnum::Approved->value,
                ];
            } catch (Throwable $e) {
                $errors[] = "Failed to process analysis result {$analysisEntity->id}: {$e->getMessage()}";
                $failureCount++;
            }
        }

        // Execute both updates in a tx for atomicity
        if (!empty($productUpdates) || !empty($statusUpdates)) {
            $this->connection->beginTransaction();
            try {
                if (!empty($productUpdates)) {
                    $this->productRepository->update($productUpdates, $context);
                }

                if (!empty($statusUpdates)) {
                    $this->aiAnalysisResultRepository->update($statusUpdates, $context);
                }

                $this->connection->commit();
            } catch (Throwable $e) {
                $this->connection->rollBack();
                return [
                    'successCount' => 0,
                    'failureCount' => count($aiAnalysisIds),
                    'errors' => ['Transaction failed: ' . $e->getMessage()],
                ];
            }
        }

        return [
            'successCount' => $successCount,
            'failureCount' => $failureCount,
            'errors' => $errors,
        ];
    }

    /**
     * Reject analysis results (mark as rejected, do not apply to products)
     * @param array $aiAnalysisIds Array of analysis result IDs to reject
     * @param Context $context
     * @return array ['rejectedCount' => int]
     */
    public function rejectResults(array $aiAnalysisIds, Context $context): array
    {
        $statusUpdates = [];
        foreach ($aiAnalysisIds as $id) {
            $statusUpdates[] = [
                'id' => $id,
                'status' => AiAnalysisStatusEnum::Rejected->value,
            ];
        }

        if (!empty($statusUpdates)) {
            $this->aiAnalysisResultRepository->update($statusUpdates, $context);
        }

        return [
            'rejectedCount' => count($statusUpdates),
        ];
    }
}
