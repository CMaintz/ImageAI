<?php declare(strict_types=1);

namespace Illux\ImageAi\Model\Enum;

enum BatchJobStatusEnum: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
