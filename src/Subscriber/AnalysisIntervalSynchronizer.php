<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Subscriber;

use CMaintz\ImageAi\Config\ConfigKeys;
use CMaintz\ImageAi\Config\PluginConfiguration;
use CMaintz\ImageAi\ScheduledTask\ProductAnalysisTask;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskEntity;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AnalysisIntervalSynchronizer implements EventSubscriberInterface
{
    /**
     * @param EntityRepository<ScheduledTaskCollection<ScheduledTaskEntity>> $scheduledTaskRepository
     */
    public function __construct(
        protected EntityRepository $scheduledTaskRepository,
        protected PluginConfiguration $config,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'onConfigChanged',
        ];
    }

    public function onConfigChanged(SystemConfigChangedEvent $event): void
    {
        if ($event->getKey() !== ConfigKeys::SCHEDULED_TASK_INTERVAL) {
            return;
        }

        // Clear cached config so we get fresh values
        $this->config->clearCache();

        // Use the centralized config to get interval in seconds
        $newIntervalSeconds = $this->config->getWorkflowConfig()->getScheduleIntervalSeconds();

        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter(
                'scheduledTaskClass',
                ProductAnalysisTask::class
            )
        );

        $tasks = $this->scheduledTaskRepository->search(
            $criteria,
            Context::createDefaultContext()
        )->getEntities();

        if ($tasks->count() > 0) {
            /** @var ScheduledTaskEntity|null $task */
            $task = $tasks->first();
            if ($task !== null) {
                $this->scheduledTaskRepository->update([
                    [
                        'id' => $task->getId(),
                        'runInterval' => $newIntervalSeconds,
                    ]
                ], Context::createDefaultContext());
            }
        }
    }
}
