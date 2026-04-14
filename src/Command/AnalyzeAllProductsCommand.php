<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Command;

use CMaintz\ImageAi\Orchestrator\AnalysisOrchestrator;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'img-ai:analyze:all',
    description: 'Analyze all unanalyzed "Wexo Artwork" products using AI',
    aliases: ['img-ai:aa']
)]
class AnalyzeAllProductsCommand extends Command
{
    public function __construct(
        private readonly AnalysisOrchestrator $batchOrchestrationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'include-analyzed',
                'i',
                InputOption::VALUE_NONE,
                'Re-analyze already analyzed products'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $io->title('CMaintzImageAi - Analyze All Products');

        $includeAnalyzed = (bool)$input->getOption('include-analyzed');

        $io->info('Configuration:');
        $io->listing([
            'Include analyzed: ' . ($includeAnalyzed ? 'Yes' : 'No'),
        ]);

        try {
            $result = $this->batchOrchestrationService->orchestrateProductAnalysis(
                context: $context,
                includeAnalyzed: $includeAnalyzed
            );

            if (!$result['success']) {
                $io->error($result['error'] ?? 'Unknown error occurred');
                return Command::FAILURE;
            }

            $io->success([
                $result['message'] ?? 'Analysis completed',
                "Total Products: {$result['totalProducts']}",
                "Processed: {$result['processedProducts']}",
                "Success: {$result['successCount']}",
                "Failed: {$result['failureCount']}",
            ]);

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error([
                'Analysis failed with error:',
                $e->getMessage(),
            ]);

            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
