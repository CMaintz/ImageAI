<?php declare(strict_types=1);

namespace Illux\ImageAi\Queue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final class AnalyzeBatchMessage implements AsyncMessageInterface
{
    /**
     * @param string $batchJobId Batch job ID
     * @param array<string> $productIds Product IDs to analyze
     * @param array<string, string> $analysisResultMapping Map of productId => analysisResultId
     * @param array|null $metadataFilters Optional metadata filters
     */
    public function __construct(
        public readonly string $batchJobId,
        public readonly array $productIds,
        public readonly array $analysisResultMapping,
        public readonly ?array $metadataFilters = null
    ) {
    }
}
