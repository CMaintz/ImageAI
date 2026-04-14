<?php declare(strict_types=1);

namespace Illux\ImageAi\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Scheduled task to clean up old batch jobs and pending images.
 *
 * Runs daily to remove:
 * - Completed batch jobs older than 30 days
 * - Failed batch jobs older than 7 days
 * - Cancelled batch jobs older than 3 days
 * - Orphaned pending scene images (job deleted or older than 30 days)
 */
class JobCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'illux.image_ai.job_cleanup_task';
    }

    public static function getDefaultInterval(): int
    {
        return 86400;
    }
}
