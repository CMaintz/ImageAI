<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Config;

/**
 * Value object for workflow configuration
 *
 * Immutable configuration for business logic settings.
 * Controls approval workflows, scheduling, and product filtering.
 *
 * Note: Confidence-related settings are in ConfidenceConfiguration.
 *
 * @phpstan-immutable
 */
readonly class WorkflowConfiguration
{
    /**
     * @param bool $enableApprovalWorkflow Whether to require manual approval before applying results
     * @param bool $enableScheduledAnalysis Whether to enable automatic scheduled analysis
     * @param int $scheduleIntervalHours Interval in hours between scheduled runs (1-24)
     * @param string[] $eligibleProductTypes Product type names eligible for analysis (e.g., ['Wexo Artwork'])
     */
    public function __construct(
        public bool $enableApprovalWorkflow,
        public bool $enableScheduledAnalysis,
        public int $scheduleIntervalHours,
        public array $eligibleProductTypes = ['Wexo Artwork'],
    ) {
    }

    public function shouldAutoApply(): bool
    {
        return $this->enableApprovalWorkflow === false;
    }

    public function isScheduledAnalysisEnabled(): bool
    {
        return $this->enableScheduledAnalysis && $this->scheduleIntervalHours > 0;
    }

    public function getScheduleIntervalSeconds(): int
    {
        return $this->scheduleIntervalHours * 3600;
    }
}
