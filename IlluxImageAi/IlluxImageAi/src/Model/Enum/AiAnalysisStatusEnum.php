<?php declare(strict_types=1);

namespace Illux\ImageAi\Model\Enum;

enum AiAnalysisStatusEnum: string
{
    case Processing = 'processing';
    case PendingReview = 'pending_review';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case AutoApproved = 'auto_approved';
    case Failed = 'failed';
}
