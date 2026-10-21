<?php

namespace Tests\Fixtures\Workflows\StatusWorkflow;

use Flowra\DTOs\Phase;

enum StatusWorkflowStates: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case IN_REVIEW = 'in_review';
    case SCORED = 'scored';
    case PROCESSING = 'processing';
    case AUDITING = 'auditing';
    case CLOSED = 'closed';

    public static function phases(): array
    {
        return [
            // A synthetic phase key no transition lands on.
            Phase::make('under_review')->label('Review Step')->children(self::IN_REVIEW, self::SCORED),
            // A phase keyed by a real state that is also one of its children — the shape Farz uses.
            Phase::make(self::PROCESSING)->children(self::PROCESSING, self::AUDITING),
        ];
    }
}
