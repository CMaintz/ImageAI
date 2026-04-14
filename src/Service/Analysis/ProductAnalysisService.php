<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Analysis;

use Illux\ImageAi\Config\IlluxConfiguration;
use Illux\ImageAi\Config\PluginConstants;
use Illux\ImageAi\Model\Enum\AiAnalysisStatusEnum;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Illux\ImageAi\Core\Content\AiAnalysisResult\Entity\AiAnalysisResultCollection;
use Illux\ImageAi\Core\Content\AiAnalysisResult\Entity\AiAnalysisResultEntity;

/**
 * Service for product analysis queries and statistics
 *
 * Handles finding eligible products for analysis and generating analysis statistics.
 * Mapping and persistence operations have been moved to AnalysisMapper and AnalysisPersistenceService.
 */
class ProductAnalysisService
{
    /**
     * @param EntityRepository<ProductCollection<ProductEntity>> $productRepository
     * @param EntityRepository<AiAnalysisResultCollection<AiAnalysisResultEntity>> $aiAnalysisResultRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $aiAnalysisResultRepository,
        private readonly IlluxConfiguration $config
    ) {
    }

    /**
     * Generate analysis statistics by status
     * @param Context $context Shopware context
     * @return array<string, int> Map of status value to count
     */
    public function generateAnalysisStats(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addAggregation(
            new TermsAggregation(
                'status_buckets',
                'status'
            )
        );

        $result = $this->aiAnalysisResultRepository->search($criteria, $context);

        /** @var TermsResult $terms */
        $terms = $result->getAggregations()->get('status_buckets');

        $stats = [];

        foreach ($terms->getBuckets() as $bucket) {
            $stats[$bucket->getKey()] = $bucket->getCount();
        }

        foreach (AiAnalysisStatusEnum::cases() as $case) {
            $stats[$case->value] = $stats[$case->value] ?? 0;
        }

        return $stats;
    }

    /**
     * Get analysis progress across all queued/processing jobs
     * Returns counts of processing vs completed analysis results
     * for overall progress tracking in the dashboard.
     * @param Context $context Shopware context
     * @return array{current: int, total: int, processing: int, percentage: float}
     */
    public function getAnalysisProgress(Context $context): array
    {
        $processingCriteria = new Criteria();
        $processingCriteria->addFilter(new EqualsFilter('status', AiAnalysisStatusEnum::Processing->value));
        $processingCount = $this->aiAnalysisResultRepository->search($processingCriteria, $context)->getTotal();

        $totalCriteria = new Criteria();
        $totalCount = $this->aiAnalysisResultRepository->search($totalCriteria, $context)->getTotal();

        $completedCount = $totalCount - $processingCount;
        $percentage = $totalCount > 0 ? round(($completedCount / $totalCount) * 100, 1) : 0;

        return [
            'current' => $completedCount,
            'total' => $totalCount,
            'processing' => $processingCount,
            'percentage' => $percentage,
        ];
    }

    /**
     * Find IDs of all eligible products for analysis.
     *
     * Memory-optimized: Returns only IDs instead of full entities.
     * Use this for dispatching to queues where only IDs are needed.
     *
     * @param Context $context
     * @param bool $includeAnalyzed Whether to include already analyzed products
     * @return array<string> Array of product IDs
     */
    public function findEligibleProductIds(Context $context, bool $includeAnalyzed = false): array
    {
        $criteria = $this->buildEligibleProductsCriteria($includeAnalyzed);

        /** @var array<string> */
        return $this->productRepository->searchIds($criteria, $context)->getIds();
    }

    /**
     * Find all eligible products for analysis.
     *
     * Note: For queue dispatch, prefer findEligibleProductIds() to reduce memory usage.
     * This method loads full entities which is only needed when you need entity data.
     *
     * @param Context $context
     * @param bool $includeAnalyzed Whether to include already analyzed products
     * @return array<ProductEntity> Array of products
     */
    public function findEligibleProducts(Context $context, bool $includeAnalyzed = false): array
    {
        $criteria = $this->buildEligibleProductsCriteria($includeAnalyzed);
        $criteria->addAssociation('cover.media.thumbnails');

        $result = $this->productRepository->search($criteria, $context);

        return $result->getElements();
    }

    private function buildEligibleProductsCriteria(bool $includeAnalyzed): Criteria
    {
        $criteria = new Criteria();
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_NONE);

        $criteria->addFilter(
            new NotFilter(
                NotFilter::CONNECTION_AND,
                [new EqualsFilter('coverId', null)]
            )
        );

        $eligibleProductTypes = $this->config->getWorkflowConfig()->eligibleProductTypes;
        $criteria->addFilter(
            new MultiFilter(
                MultiFilter::CONNECTION_AND,
                [
                    new EqualsFilter('properties.group.name', 'Product Type'),
                    new EqualsAnyFilter('properties.name', $eligibleProductTypes),
                ]
            )
        );

        // Always exclude products currently being processed to prevent loops/duplicates
        $criteria->addFilter(
            new NotFilter(
                NotFilter::CONNECTION_AND,
                [
                    new EqualsAnyFilter('aiAnalysisResults.status', [
                        AiAnalysisStatusEnum::Processing->value,
                    ]),
                ]
            )
        );

        if (!$includeAnalyzed) {
            // Additionally exclude products that have completed analysis results.
            // Products with NO results (null) or only failed/rejected results are eligible.
            //
            // EqualsAnyFilter on association returns products that have AT LEAST ONE matching result.
            // NotFilter inverts this: exclude products that have at least one result with these statuses.
            // Products with NO analysis results don't match the inner filter, so they're NOT excluded.
            $criteria->addFilter(
                new NotFilter(
                    NotFilter::CONNECTION_AND,
                    [
                        new EqualsAnyFilter('aiAnalysisResults.status', [
                            AiAnalysisStatusEnum::AutoApproved->value,
                            AiAnalysisStatusEnum::Approved->value,
                            AiAnalysisStatusEnum::PendingReview->value,
                        ]),
                    ]
                )
            );
        }

        $criteria->setLimit(PluginConstants::SAFETY_LIMIT_PRODUCTS);

        return $criteria;
    }
}
