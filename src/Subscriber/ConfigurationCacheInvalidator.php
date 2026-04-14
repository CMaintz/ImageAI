<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Subscriber;

use CMaintz\ImageAi\Config\PluginConfiguration;
use CMaintz\ImageAi\Config\PluginConstants;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Invalidates configuration caches when plugin settings change.
 *
 * Clears both:
 * - PluginConfiguration's in-memory cache (value objects)
 * - SystemConfigService's internal cache (ensures fresh database reads)
 *
 * This ensures configuration changes in the admin panel take effect immediately.
 */
class ConfigurationCacheInvalidator implements EventSubscriberInterface
{
    public function __construct(
        private readonly PluginConfiguration $config,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Use low priority to run AFTER Shopware's internal handlers
            SystemConfigChangedEvent::class => ['onConfigChanged', -100],
        ];
    }

    public function onConfigChanged(SystemConfigChangedEvent $event): void
    {
        $key = $event->getKey();

        if (str_starts_with($key, PluginConstants::CONFIG_PREFIX)) {
            // Clear Shopware's SystemConfigService internal cache
            $this->systemConfigService->reset();

            // Clear our PluginConfiguration value object cache
            $this->config->clearCache();
        }
    }
}
