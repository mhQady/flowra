<?php

use Flowra\DTOs\RegistryView;
use Flowra\DTOs\StatusView;
use Flowra\Enums\PhaseDisplayEnum;
use Flowra\Enums\TransitionTypesEnum;
use Flowra\Models\Registry;
use Flowra\Models\Status;
use Flowra\Support\RegistryViewResolver;
use Flowra\Support\StatusViewResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Ticket;
use Tests\Fixtures\Workflows\DisplayWorkflow\DisplayWorkflow;
use Tests\Fixtures\Workflows\DisplayWorkflow\DisplayWorkflowStates as S;
use Tests\Fixtures\Workflows\StatusWorkflow\StatusWorkflow;

beforeEach(function () {
    config(['flowra.cache_workflows' => false]);

    StatusViewResolver::flush();
    RegistryViewResolver::flush();

    Schema::create('tickets', fn (Blueprint $table) => $table->id());

    // A workflow whose model currently sits at $state.
    $this->at = function (string $state, string $workflow = DisplayWorkflow::class) {
        $ticket = Ticket::query()->create();

        Status::query()->create([
            'owner_type' => $ticket->getMorphClass(),
            'owner_id' => $ticket->getKey(),
            'workflow' => $workflow,
            'transition' => 'some_transition',
            'to' => $state,
            'comment' => ['Checked the internal ledger'],
            'applied_by' => 424242,
        ]);

        return new $workflow($ticket);
    };

    // submitted → in_review → scored (under_review) → processing → auditing (processing) → closed
    $this->trail = function (array $extra = []): DisplayWorkflow {
        $ticket = Ticket::query()->create();
        $rows = [
            ['start_review', 'submitted', 'in_review'],
            ['score', 'in_review', 'scored'],
            ['process', 'scored', 'processing'],
            ['audit', 'processing', 'auditing'],
            ['close', 'auditing', 'closed'],
            ...$extra,
        ];

        foreach ($rows as $minute => $row) {
            Registry::query()->create([
                'owner_type' => $ticket->getMorphClass(),
                'owner_id' => $ticket->getKey(),
                'workflow' => DisplayWorkflow::class,
                'transition' => $row[0],
                'from' => $row[1],
                'to' => $row[2],
                'type' => $row[3] ?? TransitionTypesEnum::TRANSITION->value,
                'comment' => [],
                'created_at' => now()->addMinutes($minute),
            ]);
        }

        return new DisplayWorkflow($ticket);
    };
});

it('leaves output unchanged when nothing declares a mode', function () {
    $status = ($this->at)('scored', StatusWorkflow::class)->statusView()->get();

    expect($status->display())->toBe(PhaseDisplayEnum::STATE_ONLY)
        ->and($status->toArray())->not->toHaveKeys(['display', 'display_label'])
        ->and($status->displayLabel())->toBe($status->label());
});

it('shows only the phase, dropping the child state and its details', function () {
    $entry = ($this->at)('auditing')->statusView('surveyor')->get();

    expect($entry->key)->toBe('processing')
        ->and($entry->label())->toBe('Processing')
        ->and($entry->state)->toBeNull()
        ->and($entry->phase)->toBe('processing')
        ->and($entry->isRemapped())->toBeTrue()
        ->and($entry->appliedBy)->toBeNull()
        ->and($entry->comment)->toBe([])
        ->and($entry->recordedState)->toBe('auditing')
        ->and($entry->toArray())->toMatchArray(['display' => 'phase', 'display_label' => 'Processing']);

    $json = json_encode($entry);

    expect($json)->not->toContain('"auditing"')
        ->and($json)->not->toContain('internal ledger')
        ->and($json)->not->toContain('424242');
});

it('keeps the details when phase-only display does not change the key', function () {
    $entry = ($this->at)('processing')->statusView('surveyor')->get();

    expect($entry->key)->toBe('processing')
        ->and($entry->state)->toBeNull()
        ->and($entry->isRemapped())->toBeFalse()
        ->and($entry->appliedBy)->toBe(424242);
});

it('shows the state with its phase through the localized pattern', function () {
    $entry = ($this->at)('scored')->statusView()->get();

    expect($entry->key)->toBe('scored')
        ->and($entry->state)->toBe('scored')
        ->and($entry->label())->toBe('Scored')
        ->and($entry->displayLabel())->toBe('Review Step › Scored')
        ->and($entry->isRemapped())->toBeFalse()
        ->and($entry->toArray())->toMatchArray(['display' => 'with_phase', 'display_label' => 'Review Step › Scored']);

    app('translator')->addLines(['flowra.with_phase' => ':state (:phase)'], 'en', 'flowra');

    expect(($this->at)('scored')->statusView()->get()->displayLabel())->toBe('Scored (Review Step)');
});

it('presents a state outside any phase as the state, whatever the mode', function () {
    $entry = ($this->at)('submitted')->statusView('surveyor')->get();

    expect($entry->key)->toBe('submitted')
        ->and($entry->display())->toBe(PhaseDisplayEnum::STATE_ONLY)
        ->and($entry->toArray())->not->toHaveKey('display');
});

it('stacks declarations from the phase up to the read', function () {
    $mode = fn ($builder) => $builder->get()->display();

    // 1. Phase::display() — the default view says nothing.
    expect($mode(($this->at)('scored')->statusView()))->toBe(PhaseDisplayEnum::WITH_PHASE)
        // 2. view-wide beats the phase.
        ->and($mode(($this->at)('scored')->statusView('state_only')))->toBe(PhaseDisplayEnum::STATE_ONLY)
        ->and($mode(($this->at)('scored')->statusView('mixed')))->toBe(PhaseDisplayEnum::PHASE_ONLY)
        // 3. view per phase (named by a member state) beats view-wide.
        ->and($mode(($this->at)('auditing')->statusView('mixed')))->toBe(PhaseDisplayEnum::WITH_PHASE)
        // 4. read-wide beats the view per phase.
        ->and($mode(($this->at)('auditing')->statusView('mixed')->stateOnly()))->toBe(PhaseDisplayEnum::STATE_ONLY)
        // 5. read per phase beats read-wide.
        ->and($mode(($this->at)('auditing')->statusView('mixed')->stateOnly()->phaseOnly(S::PROCESSING)))
        ->toBe(PhaseDisplayEnum::PHASE_ONLY);
});

it('applies the mode after rules, never leaking a hidden state', function () {
    $hidden = ($this->at)('closed')->statusView('ruled')->get();

    expect($hidden->key)->toBe('processing')
        ->and($hidden->isRedacted())->toBeTrue()
        ->and(json_encode($hidden))->not->toContain('closed')
        ->and(json_encode($hidden))->not->toContain('"auditing"');

    $shown = ($this->at)('submitted')->statusView('ruled')->get();

    // The rule's label named 'auditing', which is no longer what is shown; its keepDetails() holds.
    expect($shown->key)->toBe('processing')
        ->and($shown->label())->toBe('Processing')
        ->and($shown->appliedBy)->toBe(424242);
});

it('re-keys detailed registry leaves by their phase, leaving from/to and jumps alone', function () {
    $entries = ($this->trail)([['force', 'closed', 'auditing', TransitionTypesEnum::RESET->value]])
        ->registryView('phase_only')->get();

    expect($entries->map->key->all())->toBe(['under_review', 'under_review', 'processing', 'processing', 'closed', 'auditing'])
        ->and($entries->map->label()->all())->toBe(['Review Step', 'Review Step', 'Processing', 'Processing', 'Closed', 'Auditing'])
        ->and($entries[1]->to)->toBe('scored')
        ->and($entries[3]->to)->toBe('auditing')
        ->and($entries[0]->toArray())->toMatchArray(['display' => 'phase', 'display_label' => 'Review Step'])
        ->and($entries[4]->toArray())->not->toHaveKey('display')
        // The jump carries no phase, so it is never re-keyed.
        ->and($entries[5]->isJump())->toBeTrue()
        ->and($entries[5]->toArray())->not->toHaveKey('display');
});

it('adds a combined label to collapsed entries and their children', function () {
    $entries = ($this->trail)()->registryView('collapsed_with_phase')->get();

    expect($entries->map->key->all())->toBe(['under_review', 'processing', 'closed'])
        ->and($entries->map->displayLabel()->all())->toBe(['Review Step › Scored', 'Processing › Auditing', 'Closed'])
        ->and($entries[0]->children->map->displayLabel()->all())->toBe(['Review Step › In Review', 'Review Step › Scored'])
        ->and($entries[0]->toArray()['children'][0]['display_label'])->toBe('Review Step › In Review')
        ->and($entries[2]->toArray())->not->toHaveKey('display');
});

it('lets the phase declaration reach registry views that say nothing', function () {
    $entries = ($this->trail)()->registryView()->get();

    expect($entries[0]->display())->toBe(PhaseDisplayEnum::WITH_PHASE)
        ->and($entries[0]->key)->toBe('in_review')
        ->and($entries[2]->display())->toBe(PhaseDisplayEnum::STATE_ONLY);
});

it('presents SQL-paginated registry pages like get()', function () {
    $workflow = ($this->trail)();
    $page = $workflow->registryView('phase_only')->paginate(2);

    expect(collect($page->items())->map->key->all())->toBe(['under_review', 'under_review'])
        ->and(collect($page->items())->map->label()->all())->toBe(['Review Step', 'Review Step']);
});

it('reads phase_display from config, wide and per phase', function () {
    $wide = RegistryView::fromArray('wide', ['phase_display' => 'phase']);
    $mixed = StatusView::fromArray('mixed', ['phase_display' => ['*' => 'with_phase', 'under_review' => 'phase']]);

    expect($wide->phaseDisplayWide())->toBe(PhaseDisplayEnum::PHASE_ONLY)
        ->and($wide->phaseDisplayPhases())->toBe([])
        ->and($mixed->phaseDisplayWide())->toBe(PhaseDisplayEnum::WITH_PHASE)
        ->and($mixed->phaseDisplayPhases())->toBe(['under_review' => PhaseDisplayEnum::PHASE_ONLY]);
});

it('rejects an unknown mode', function () {
    StatusView::fromArray('bad', ['phase_display' => 'sideways']);
})->throws(InvalidArgumentException::class, 'Unknown phase display mode [sideways]');
