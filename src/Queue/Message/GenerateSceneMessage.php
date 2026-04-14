<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Queue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Message for async scene generation
 *
 * Dispatched to queue when user triggers scene generation from admin UI.
 * The GenerateSceneHandler processes this in the background worker.
 */
final class GenerateSceneMessage implements AsyncMessageInterface
{
    public function __construct(
        public readonly string $batchJobId,
        public readonly array $config
    ) {
    }
}
