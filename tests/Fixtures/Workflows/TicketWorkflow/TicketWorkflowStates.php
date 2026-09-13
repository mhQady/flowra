<?php

namespace Tests\Fixtures\Workflows\TicketWorkflow;

use Flowra\DTOs\Phase;

enum TicketWorkflowStates: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case IN_REVIEW = 'in_review';
    case SCORED = 'scored';
    case CLOSED = 'closed';

    public static function phases(): array
    {
        return [
            Phase::make('under_review')->children(self::IN_REVIEW, self::SCORED),
        ];
    }
}
