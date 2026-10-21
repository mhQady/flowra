<?php

use Flowra\DTOs\StatusView;
use Flowra\Models\Status;
use Flowra\Support\StatusViewResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Ticket;
use Tests\Fixtures\Workflows\StatusWorkflow\StatusWorkflow;

beforeEach(function () {
    config(['flowra.cache_workflows' => false]);

    StatusViewResolver::flush();

    Schema::create('tickets', fn (Blueprint $table) => $table->id());

    $this->at = function (string $state): StatusWorkflow {
        $ticket = Ticket::query()->create();

        Status::query()->create([
            'owner_type' => $ticket->getMorphClass(),
            'owner_id' => $ticket->getKey(),
            'workflow' => StatusWorkflow::class,
            'transition' => 'some_transition',
            'to' => $state,
            'comment' => [],
        ]);

        return new StatusWorkflow($ticket);
    };
});

it('carries the real sub-state with a status shown as its phase', function () {
    app('translator')->addLines(['tests.status.in_review' => 'Being Reviewed'], 'en');

    $entry = ($this->at)('in_review')->statusView('sub_states')->get();

    expect($entry->key)->toBe('under_review')
        ->and($entry->subState)->toBe('in_review')
        ->and($entry->subStateLabel())->toBe('Being Reviewed')
        ->and($entry->toArray()['sub_state'])->toBe(['key' => 'in_review', 'label' => 'Being Reviewed']);
});

it('resolves a phase named by a member state', function () {
    $entry = ($this->at)('auditing')->statusView('sub_states')->get();

    expect($entry->key)->toBe('processing')
        ->and($entry->subState)->toBe('auditing')
        ->and($entry->subStateLabel())->toBe('Auditing');
});

it('carries nothing when the real state is the phase itself', function () {
    $entry = ($this->at)('processing')->statusView('sub_states')->get();

    expect($entry->key)->toBe('processing')
        ->and($entry->subState)->toBeNull()
        ->and($entry->toArray())->toHaveKey('sub_state', null);
});

it('never reveals a state from outside the phase', function () {
    $entry = ($this->at)('closed')->statusView('sub_states')->get();

    expect($entry->key)->toBe('processing')
        ->and($entry->subState)->toBeNull()
        ->and(json_encode($entry))->not->toContain('closed');
});

it('never carries the sub-state of a hidden status', function () {
    $entry = ($this->at)('scored')->statusView('sub_states')->get();

    expect($entry->isRedacted())->toBeTrue()
        ->and($entry->subState)->toBeNull()
        ->and(json_encode($entry))->not->toContain('scored');
});

it('keeps the sub_state key on a status outside any phase, as null', function () {
    expect(($this->at)('submitted')->statusView('sub_states')->get()->toArray())->toHaveKey('sub_state', null);
});

it('leaves views that never asked unchanged', function () {
    expect(($this->at)('in_review')->statusView('applicant')->get()->toArray())->not->toHaveKey('sub_state');
});

it('can be asked for per read', function () {
    $entry = ($this->at)('scored')->statusView('applicant')->withSubState()->get();

    expect($entry->key)->toBe('under_review')
        ->and($entry->subState)->toBe('scored');
});

it('combines with phase-only display', function () {
    $entry = ($this->at)('auditing')->statusView('sub_states_everywhere')->get();

    expect($entry->key)->toBe('processing')
        ->and($entry->state)->toBeNull()
        ->and($entry->subState)->toBe('auditing');
});

it('reads sub_state from config', function () {
    $everywhere = StatusView::fromArray('a', ['sub_state' => true]);
    $listed = StatusView::fromArray('b', ['sub_state' => ['prepare_application_info']]);
    $off = StatusView::fromArray('c', []);

    expect($everywhere->subStatesEverywhere())->toBeTrue()
        ->and($listed->subStatePhases())->toBe(['prepare_application_info'])
        ->and($off->subStatesEverywhere())->toBeFalse()
        ->and($off->subStatePhases())->toBe([]);
});
