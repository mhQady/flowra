<?php

namespace Tests\Fixtures\Workflows\DisplayWorkflow;

use Flowra\Concretes\BaseWorkflow;
use Flowra\DTOs\RegistryView;
use Flowra\DTOs\StatusView;
use Flowra\DTOs\Transition;
use Flowra\Enums\PhaseDisplayEnum;

class DisplayWorkflow extends BaseWorkflow
{
    public static function transitionsSchema(): array
    {
        return [
            Transition::make('start_review', DisplayWorkflowStates::SUBMITTED, DisplayWorkflowStates::IN_REVIEW),
            Transition::make('score', DisplayWorkflowStates::IN_REVIEW, DisplayWorkflowStates::SCORED),
            Transition::make('process', DisplayWorkflowStates::SCORED, DisplayWorkflowStates::PROCESSING),
            Transition::make('audit', DisplayWorkflowStates::PROCESSING, DisplayWorkflowStates::AUDITING),
            Transition::make('close', DisplayWorkflowStates::AUDITING, DisplayWorkflowStates::CLOSED),
        ];
    }

    public static function statusViews(): array
    {
        return [
            StatusView::make('surveyor')->labels('tests.display')->phaseOnly()->withDetails(),

            StatusView::make('mixed')
                ->phaseOnly()
                // Named by a member state: resolves to the processing phase.
                ->phaseDisplay(PhaseDisplayEnum::WITH_PHASE, DisplayWorkflowStates::AUDITING),

            StatusView::make('state_only')->stateOnly(),

            StatusView::make('ruled')
                ->show(DisplayWorkflowStates::SUBMITTED)->as(DisplayWorkflowStates::AUDITING)->label('Rule Label')->keepDetails()
                ->hide(DisplayWorkflowStates::CLOSED)->as(DisplayWorkflowStates::AUDITING)
                ->phaseOnly(),
        ];
    }

    public static function registryViews(): array
    {
        return [
            RegistryView::make('phase_only')->detailed()->phaseOnly(),
            RegistryView::make('collapsed_with_phase')->collapsed()->withPhase(),
        ];
    }
}
