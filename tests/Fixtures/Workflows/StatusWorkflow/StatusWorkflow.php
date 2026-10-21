<?php

namespace Tests\Fixtures\Workflows\StatusWorkflow;

use Flowra\Concretes\BaseWorkflow;
use Flowra\DTOs\StatusRule;
use Flowra\DTOs\StatusView;
use Flowra\DTOs\Transition;
use Flowra\Models\Status;
use Tests\Fixtures\StatusViews\IsStaff;

class StatusWorkflow extends BaseWorkflow
{
    public static function transitionsSchema(): array
    {
        return [
            Transition::make('submit', StatusWorkflowStates::DRAFT, StatusWorkflowStates::SUBMITTED),
            Transition::make('start_review', StatusWorkflowStates::SUBMITTED, StatusWorkflowStates::IN_REVIEW),
            Transition::make('score', StatusWorkflowStates::IN_REVIEW, StatusWorkflowStates::SCORED),
            Transition::make('process', StatusWorkflowStates::SCORED, StatusWorkflowStates::PROCESSING),
            Transition::make('audit', StatusWorkflowStates::PROCESSING, StatusWorkflowStates::AUDITING),
            Transition::make('close', StatusWorkflowStates::AUDITING, StatusWorkflowStates::CLOSED),
        ];
    }

    public static function statusViews(): array
    {
        return [
            StatusView::make('applicant')
                ->show('under_review')->as('under_review')
                ->hide(StatusWorkflowStates::PROCESSING)->as('in_progress')
                ->when(fn (Status $status, $owner, $viewer) => ! ($viewer->staff ?? false)),

            StatusView::make('ordered')
                ->show(StatusWorkflowStates::SCORED)->as('first')
                ->show('under_review')->as('second'),

            StatusView::make('state_only')
                ->show(StatusWorkflowStates::AUDITING)->as('auditing_shown'),

            StatusView::make('kinds')
                ->rule(StatusRule::show(StatusWorkflowStates::IN_REVIEW)->as('by_instance')->when(new IsStaff()))
                ->rule(StatusRule::show(StatusWorkflowStates::IN_REVIEW)->as('by_class')->when(IsStaff::class))
                ->rule(StatusRule::any()->as('nobody')),

            StatusView::make('resolved')
                ->using(fn (Status $status) => $status->to === 'closed' ? 'archived' : null)
                ->show(StatusWorkflowStates::CLOSED)->as('never_reached')
                ->show(StatusWorkflowStates::SUBMITTED)->as('received'),

            StatusView::make('details')
                ->show(StatusWorkflowStates::SUBMITTED)->as('received')->keepDetails()
                ->show(StatusWorkflowStates::DRAFT)->as('not_started')
                ->hide(StatusWorkflowStates::CLOSED)->as('done')->keepDetails(),

            StatusView::make('labelled')
                ->labels('tests.status')
                ->show(StatusWorkflowStates::DRAFT)->as('custom')->label('Literal Label')
                ->show(StatusWorkflowStates::SUBMITTED)->as('prefixed')
                ->show(StatusWorkflowStates::IN_REVIEW)->as('under_review')
                ->show(StatusWorkflowStates::SCORED)->as('closed'),

            StatusView::make('with_fallback')->fallback('not_started'),

            StatusView::make('shared')->show(StatusWorkflowStates::SUBMITTED)->as('from_workflow'),

            StatusView::make('sub_states')
                ->labels('tests.status')
                // Hidden first: a redacted entry never carries its sub-state.
                ->hide(StatusWorkflowStates::SCORED)->as('under_review')
                ->show('under_review')->as('under_review')
                ->show(StatusWorkflowStates::PROCESSING)->as(StatusWorkflowStates::PROCESSING)
                // An unrelated state mapped onto a phase: its real state is outside the phase.
                ->show(StatusWorkflowStates::CLOSED)->as(StatusWorkflowStates::PROCESSING)
                // The processing phase, named by a member state.
                ->withSubState('under_review', StatusWorkflowStates::AUDITING),

            StatusView::make('sub_states_everywhere')->phaseOnly()->withSubState(),

            StatusView::make('with_actor')
                ->withDetails()
                ->with('actor')
                ->show(StatusWorkflowStates::SUBMITTED)->as('received')->keepDetails()
                ->show(StatusWorkflowStates::DRAFT)->as('not_started')
                ->hide(StatusWorkflowStates::CLOSED)->as('done')->keepDetails(),
        ];
    }
}
