<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Subscriber;

use CMaintz\ImageAi\Service\Property\PropertyLookupService;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionDefinition;
use Shopware\Core\Content\Property\PropertyGroupDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Invalidates the PropertyLookupService cache when property groups or options change.
 *
 * This ensures the persistent cache is cleared when:
 * - Property groups are created, updated, or deleted
 * - Property group options are created, updated, or deleted
 */
class PropertyCacheInvalidator implements EventSubscriberInterface
{
    public function __construct(
        private readonly PropertyLookupService $propertyLookupService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PropertyGroupDefinition::ENTITY_NAME . '.written' => 'onPropertyChange',
            PropertyGroupDefinition::ENTITY_NAME . '.deleted' => 'onPropertyChange',
            PropertyGroupOptionDefinition::ENTITY_NAME . '.written' => 'onPropertyChange',
            PropertyGroupOptionDefinition::ENTITY_NAME . '.deleted' => 'onPropertyChange',
        ];
    }

    public function onPropertyChange(EntityWrittenEvent|EntityDeletedEvent $event): void
    {
        $this->propertyLookupService->clearCache();
    }
}
