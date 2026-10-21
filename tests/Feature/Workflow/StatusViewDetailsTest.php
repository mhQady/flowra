<?php

use Flowra\DTOs\StatusView;
use Flowra\Support\StatusViewResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Application;
use Tests\Fixtures\Models\Status;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Workflows\StatusWorkflow\StatusWorkflow;
use Tests\Fixtures\Workflows\StatusWorkflow\StatusWorkflowStates as S;

beforeEach(function () {
    config([
        'flowra.cache_workflows' => false,
        'flowra.models.status' => Status::class,
    ]);

    Schema::create('applications', fn (Blueprint $table) => $table->id());
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    User::query()->create(['id' => 7, 'name' => 'Reviewer']);

    // Views declared through config, as instances or arrays, beside the workflow's own.
    $this->views = function (array $views): void {
        config(['flowra.status_views.views' => $views]);
        StatusViewResolver::flush();
    };

    $this->at = function (string $state): StatusWorkflow {
        $application = Application::query()->create();

        Status::query()->create([
            'owner_type' => $application->getMorphClass(),
            'owner_id' => $application->getKey(),
            'workflow' => StatusWorkflow::class,
            'transition' => 'score',
            'from' => 'in_review',
            'to' => $state,
            'comment' => ['Checked the internal ledger'],
            'applied_by' => 7,
        ]);

        return new StatusWorkflow($application);
    };

    $this->detailed = fn ($entry) => expect($entry->appliedBy)->toBe(7)
        ->and($entry->comment)->toBe(['Checked the internal ledger'])
        ->and($entry->transition)->toBe('score')
        ->and($entry->from)->toBe('in_review');

    $this->bare = fn ($entry) => expect($entry->appliedBy)->toBeNull()
        ->and($entry->comment)->toBe([])
        ->and($entry->transition)->toBeNull()
        ->and($entry->from)->toBeNull()
        ->and($entry->relations)->toBe([]);

    ($this->views)([]);
});

it('sends no details on the real status of a view that never asked', function () {
    ($this->views)(['external' => StatusView::make('external')->labels('tests.status')]);

    $entry = ($this->at)('scored')->statusView('external')->get();

    expect($entry->key)->toBe('scored')
        ->and($entry->isRemapped())->toBeFalse()
        ->and($entry->recordedState)->toBe('scored');
    ($this->bare)($entry);

    $json = json_encode($entry);

    expect($json)->not->toContain('internal ledger')
        ->and($entry->toArray()['applied_by'])->toBeNull();
});

it('sends every detail on the real status of a view with withDetails()', function () {
    ($this->views)(['staff' => StatusView::make('staff')->withDetails()->with('appliedBy')]);

    $entry = ($this->at)('scored')->statusView('staff')->get();

    ($this->detailed)($entry);
    expect($entry->relations['appliedBy']->name)->toBe('Reviewer');
});

it('does not reach a status remapped by a rule without keepDetails()', function () {
    ($this->views)(['staff' => StatusView::make('staff')->withDetails()->with('appliedBy')
        ->show(S::SCORED)->as('under_review')]);

    $entry = ($this->at)('scored')->statusView('staff')->get();

    expect($entry->key)->toBe('under_review')->and($entry->isRemapped())->toBeTrue();
    ($this->bare)($entry);
});

it('does not reach a status chosen by a resolver either', function () {
    ($this->views)(['staff' => StatusView::make('staff')->withDetails()->using(fn () => 'archived')]);

    ($this->bare)(($this->at)('scored')->statusView('staff')->get());
});

it('still keeps details on a remapped status whose rule keeps them', function () {
    ($this->views)(['owner' => StatusView::make('owner')->with('appliedBy')
        ->show(S::SCORED)->as('under_review')->keepDetails()]);

    $remapped = ($this->at)('scored')->statusView('owner')->get();
    $real = ($this->at)('submitted')->statusView('owner')->get();

    ($this->detailed)($remapped);
    expect($remapped->relations['appliedBy']->name)->toBe('Reviewer');
    // The rule's opt-in is the rule's alone: the view's real status stays bare.
    ($this->bare)($real);
});

it('loads with() relations through loadMissing(), reusing an eager load', function () {
    ($this->views)(['staff' => StatusView::make('staff')->withDetails()->with('appliedBy')]);

    foreach (['scored', 'submitted'] as $state) {
        ($this->at)($state);
    }

    $applications = Application::query()->with('statusWorkflowStatus.appliedBy')->get();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $names = $applications->map(fn (Application $app) => $app->statusWorkflow->statusView('staff')->get()->relations['appliedBy']->name);

    expect(DB::getQueryLog())->toBe([])
        ->and($names->all())->toBe(['Reviewer', 'Reviewer']);
});

it('refuses with() when nothing opts in to details', function (Closure $read) {
    ($this->views)(['leaky' => StatusView::make('leaky')->with('appliedBy')->show(S::DRAFT)->as('x')]);

    $read(($this->at)('scored'));
})->with([
    'view relations' => [fn (StatusWorkflow $workflow) => $workflow->statusView('leaky')->get()],
    'per-read relations' => [fn (StatusWorkflow $workflow) => $workflow->statusView('leaky')->with('appliedBy')->get()],
    'serialized read' => [fn (StatusWorkflow $workflow) => json_encode($workflow->statusView('leaky'))],
])->throws(LogicException::class, 'Status view [leaky] loads relations with with() but sends no details');

it('refuses per-read with() on a view without the opt-in', function () {
    ($this->views)(['external' => StatusView::make('external')]);

    ($this->at)('scored')->statusView('external')->with('appliedBy')->get();
})->throws(LogicException::class, 'withDetails()');

it('does not count a hide rule\'s keepDetails() as an opt-in', function () {
    ($this->views)(['hidden' => StatusView::make('hidden')->with('appliedBy')
        ->hide(S::SCORED)->as('done')->keepDetails()]);

    ($this->at)('scored')->statusView('hidden')->get();
})->throws(LogicException::class);

it('accepts a per-read keepDetails() rule as the opt-in for with()', function () {
    ($this->views)(['external' => StatusView::make('external')]);

    $entry = ($this->at)('scored')->statusView('external')
        ->with('appliedBy')
        ->show(S::SCORED)->as('under_review')->keepDetails()
        ->get();

    expect($entry->relations['appliedBy']->name)->toBe('Reviewer');
});

it('keeps details on the built-in default view', function () {
    $entry = ($this->at)('scored')->statusView()->get();

    ($this->detailed)($entry);
    expect(StatusViewResolver::get(StatusWorkflow::class, StatusViewResolver::DEFAULT_VIEW)->keepsDetails())->toBeTrue()
        ->and(($this->at)('scored')->statusView()->with('appliedBy')->get()->relations['appliedBy']->name)->toBe('Reviewer');
});

it('makes a view replacing default opt in like any other', function () {
    ($this->views)(['default' => ['labels' => 'tests.status']]);

    ($this->bare)(($this->at)('scored')->statusView()->get());
});

it('reads keep_details from config the same as withDetails()', function () {
    ($this->views)([
        'from_config' => ['keep_details' => true, 'with' => ['appliedBy']],
        'fluent' => StatusView::make('fluent')->withDetails()->with('appliedBy'),
        'off' => ['keep_details' => false],
    ]);

    $fromConfig = ($this->at)('scored')->statusView('from_config')->get();
    $fluent = ($this->at)('scored')->statusView('fluent')->get();

    expect(StatusView::fromArray('x', ['keep_details' => true])->keepsDetails())->toBeTrue()
        ->and(StatusView::fromArray('x', [])->keepsDetails())->toBeFalse()
        ->and($fromConfig->toArray())->toEqual($fluent->toArray())
        ->and($fromConfig->transition)->toBe($fluent->transition)
        ->and($fromConfig->from)->toBe($fluent->from);
    ($this->detailed)($fromConfig);
    ($this->bare)(($this->at)('scored')->statusView('off')->get());
});

it('keeps remapped, recordedState and sub_state as they were', function () {
    ($this->views)(['bare_sub' => StatusView::make('bare_sub')->phaseOnly()->withSubState()]);

    $entry = ($this->at)('scored')->statusView('bare_sub')->get();

    expect($entry->key)->toBe('under_review')
        ->and($entry->isRemapped())->toBeTrue()
        ->and($entry->recordedState)->toBe('scored')
        ->and($entry->toArray()['sub_state']['key'])->toBe('scored');
    ($this->bare)($entry);
});
