<?php declare(strict_types=1);

namespace Illux\ImageAi\Subscriber;

use Illux\ImageAi\Config\IlluxConfiguration;
use Illux\ImageAi\Config\PluginConstants;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Invalidates configuration caches when plugin settings change.
 *
 * Clears both:
 * - IlluxConfiguration's in-memory cache (value objects)
 * - SystemConfigService's internal cache (ensures fresh database reads)
 *
 * This ensures configuration changes in the admin panel take effect immediately.
 */
class ConfigurationCacheInvalidator implements EventSubscriberInterface
{
    public function __construct(
        private readonly IlluxConfiguration $config,
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

            // Clear our IlluxConfiguration value object cache
            $this->config->clearCache();
        }
    }
}
