<?php

namespace Tests\Fixtures\Workflows\TicketWorkflow;

use Flowra\Concretes\BaseWorkflow;
use Flowra\DTOs\Transition;

class TicketWorkflow extends BaseWorkflow
{
    public static function transitionsSchema(): array
    {
        return [
            Transition::make('submit', TicketWorkflowStates::DRAFT, TicketWorkflowStates::SUBMITTED),
            Transition::make('start_review', TicketWorkflowStates::SUBMITTED, TicketWorkflowStates::IN_REVIEW),
            Transition::make('score', TicketWorkflowStates::IN_REVIEW, TicketWorkflowStates::SCORED),
            Transition::make('close', TicketWorkflowStates::SCORED, TicketWorkflowStates::CLOSED),
        ];
    }
}
