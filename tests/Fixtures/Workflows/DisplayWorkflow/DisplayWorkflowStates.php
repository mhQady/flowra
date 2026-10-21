<?php

namespace Tests\Fixtures\Workflows\DisplayWorkflow;

use Flowra\DTOs\Phase;
use Flowra\Enums\PhaseDisplayEnum;

enum DisplayWorkflowStates: string
{
    case SUBMITTED = 'submitted';
    case IN_REVIEW = 'in_review';
    case SCORED = 'scored';
    case PROCESSING = 'processing';
    case AUDITING = 'auditing';
    case CLOSED = 'closed';

    public static function phases(): array
    {
        return [
            // Declares its own default: every view that says nothing shows it with its phase.
            Phase::make('under_review')->label('Review Step')->display(PhaseDisplayEnum::WITH_PHASE)
                ->children(self::IN_REVIEW, self::SCORED),
            // A phase keyed by a real state that is also one of its children; no default.
            Phase::make(self::PROCESSING)->children(self::PROCESSING, self::AUDITING),
        ];
    }
}
