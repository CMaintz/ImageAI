<?php declare(strict_types=1);

namespace CMaintz\ImageAi\ScheduledTask;

use CMaintz\ImageAi\Config\PluginConfiguration;
use CMaintz\ImageAi\Orchestrator\AnalysisOrchestrator;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler(handles: ProductAnalysisTask::class)]
class ProductAnalysisTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly LoggerInterface $logger,
        private readonly AnalysisOrchestrator $batchOrchestrationService,
        private readonly PluginConfiguration $config
    ) {
        parent::__construct($scheduledTaskRepository, $this->logger);
    }

    public function run(): void
    {
        if (!$this->config->getWorkflowConfig()->isScheduledAnalysisEnabled()) {
            $this->logger->debug('Scheduled analysis is disabled, skipping');
            return;
        }

        $this->logger->info('Starting scheduled product analysis task');

        $context = Context::createCLIContext();

        try {
            $result = $this->batchOrchestrationService->orchestrateProductAnalysis(
                context: $context,
            );

            if (!$result['success']) {
                $this->logger->error('Scheduled task failed', [
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
                return;
            }

            $this->logger->info('Scheduled task completed', $result);
        } catch (Throwable $e) {
            $this->logger->error('Scheduled product analysis task failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
