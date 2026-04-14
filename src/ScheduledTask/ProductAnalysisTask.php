<?php declare(strict_types=1);

namespace CMaintz\ImageAi\ScheduledTask;

use CMaintz\ImageAi\Config\PluginConstants;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ProductAnalysisTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'image_ai.scheduled_analysis_task';
    }

    public static function getDefaultInterval(): int
    {
        // Shopware expects seconds, config is in hours
        return PluginConstants::DEFAULT_SCHEDULE_INTERVAL_HOURS * 3600;
    }
}
