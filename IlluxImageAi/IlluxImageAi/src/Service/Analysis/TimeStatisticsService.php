<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Analysis;

use Doctrine\DBAL\Connection;
use Illux\ImageAi\Config\IlluxConfiguration;
use Shopware\Core\Framework\Context;

/**
 * Service for calculating time saved based on approved AI analysis results.
 *
 * Uses direct DBAL queries for performance - aggregates translation stats
 * in SQL rather than hydrating entities.
 *
 * @phpstan-type TimeStatistics array{
 *     totalMinutes: int,
 *     totalHours: float,
 *     totalDays: float,
 *     breakdown: array{properties: int, seo: int, description: int},
 *     productCount: int
 * }
 */
class TimeStatisticsService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IlluxConfiguration $config,
    ) {
    }

    /**
     * Calculate total time saved across all approved analyses.
     *
     * Groups by product_id to avoid double-counting re-analyzed products.
     * Uses the most recent analysis per product.
     *
     * @return TimeStatistics
     */
    public function calculateTotalTimeSaved(Context $context): array
    {
        $results = $this->fetchApprovedAnalysisStats();

        return $this->aggregateTimeStatistics($results);
    }

    /**
     * Fetch aggregated stats for approved analysis results, one per product.
     *
     * @return array<int, array{has_properties: bool, has_seo: bool, desc_language_count: int}>
     */
    private function fetchApprovedAnalysisStats(): array
    {
        // Subquery to get the most recent approved analysis per product
        $sql = "
            SELECT
                ar.analyzed_properties IS NOT NULL
                    AND ar.analyzed_properties != '[]'
                    AND ar.analyzed_properties != 'null'
                    AND ar.analyzed_properties != '' AS has_properties,
                (
                    SELECT COUNT(*) > 0
                    FROM ai_analysis_result_translation t
                    WHERE t.ai_analysis_result_id = ar.id
                      AND t.meta_title IS NOT NULL
                      AND t.meta_title != ''
                ) AS has_seo,
                (
                    SELECT COUNT(*)
                    FROM ai_analysis_result_translation t
                    WHERE t.ai_analysis_result_id = ar.id
                      AND t.product_description IS NOT NULL
                      AND t.product_description != ''
                ) AS desc_language_count
            FROM ai_analysis_result ar
            INNER JOIN (
                SELECT product_id, MAX(created_at) AS max_created
                FROM ai_analysis_result
                WHERE status IN ('approved', 'auto_approved')
                GROUP BY product_id
            ) latest ON ar.product_id = latest.product_id AND ar.created_at = latest.max_created
            WHERE ar.status IN ('approved', 'auto_approved')
        ";

        $rows = $this->connection->fetchAllAssociative($sql);

        return array_map(fn(array $row) => [
            'has_properties' => (bool) $row['has_properties'],
            'has_seo' => (bool) $row['has_seo'],
            'desc_language_count' => (int) $row['desc_language_count'],
        ], $rows);
    }

    /**
     * Aggregate time statistics from fetched analysis stats.
     *
     * @param array<int, array{has_properties: bool, has_seo: bool, desc_language_count: int}> $results
     * @return TimeStatistics
     */
    private function aggregateTimeStatistics(array $results): array
    {
        $timeConfig = $this->config->getTimeTrackingConfig();

        $totalMinutes = 0;
        $totalProperties = 0;
        $totalSeo = 0;
        $totalDescription = 0;

        foreach ($results as $result) {
            $breakdown = $timeConfig->getBreakdown(
                $result['has_properties'],
                $result['has_seo'],
                $result['desc_language_count']
            );

            $totalMinutes += array_sum($breakdown);
            $totalProperties += $breakdown['properties'];
            $totalSeo += $breakdown['seo'];
            $totalDescription += $breakdown['description'];
        }

        return [
            'totalMinutes' => $totalMinutes,
            'totalHours' => round($totalMinutes / 60, 1),
            'totalDays' => round($totalMinutes / (60 * 24), 2),
            'breakdown' => [
                'properties' => $totalProperties,
                'seo' => $totalSeo,
                'description' => $totalDescription,
            ],
            'productCount' => count($results),
        ];
    }
}
