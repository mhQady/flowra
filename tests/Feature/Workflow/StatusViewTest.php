<?php

use Flowra\DTOs\StatusRule;
use Flowra\DTOs\StatusView;
use Flowra\Exceptions\StatusViewNotFoundException;
use Flowra\Models\Status;
use Flowra\Support\StatusViewResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Ticket;
use Tests\Fixtures\StatusViews\IsStaff;
use Tests\Fixtures\Workflows\StatusWorkflow\StatusWorkflow;
use Tests\Fixtures\Workflows\StatusWorkflow\StatusWorkflowStates as S;

beforeEach(function () {
    config(['flowra.cache_workflows' => false]);

    StatusViewResolver::flush();

    Schema::create('tickets', fn (Blueprint $table) => $table->id());

    // A workflow whose model currently sits at $state, with a status row written the way
    // apply() writes one.
    $this->at = function (?string $state, array $attributes = []): StatusWorkflow {
        $ticket = Ticket::query()->create();

        if ($state !== null) {
            Status::query()->create(array_merge([
                'owner_type' => $ticket->getMorphClass(),
                'owner_id' => $ticket->getKey(),
                'workflow' => StatusWorkflow::class,
                'transition' => 'some_transition',
                'from' => null,
                'to' => $state,
                'comment' => ['Checked the internal ledger'],
                'applied_by' => 424242,
            ], $attributes));
        }

        return new StatusWorkflow($ticket);
    };

    $this->staff = (object) ['staff' => true];
    $this->applicant = (object) ['staff' => false];
});

it('shows the real status through the default view', function () {
    $workflow = ($this->at)('scored');
    $entry = $workflow->statusView()->get();

    expect($entry->key)->toBe('scored')
        ->and($entry->state)->toBe('scored')
        ->and($entry->phase)->toBe('under_review')
        ->and($entry->label())->toBe('Scored')
        ->and($entry->phaseLabel())->toBe('Review Step')
        ->and($entry->isRemapped())->toBeFalse()
        ->and($entry->isRedacted())->toBeFalse()
        ->and($entry->recordedState)->toBe('scored')
        ->and($entry->appliedBy)->toBe(424242)
        ->and($entry->comment)->toBe(['Checked the internal ledger'])
        ->and($entry->appliedAt?->equalTo($workflow->status()->updated_at))->toBeTrue()
        ->and($entry->stateEnum())->toBe(S::SCORED);
});

it('leaves currentState, currentStatus and status() untouched', function () {
    $workflow = ($this->at)('processing');

    $workflow->statusView('applicant')->for($this->applicant)->get();

    expect($workflow->currentState)->toBe(S::PROCESSING)
        ->and($workflow->currentStatus->to)->toBe('processing')
        ->and($workflow->status()->to)->toBe('processing');
});

it('returns null for a model with no status, or the view fallback', function () {
    expect(($this->at)(null)->statusView()->get())->toBeNull();

    $entry = ($this->at)(null)->statusView('with_fallback')->get();

    expect($entry->key)->toBe('not_started')
        ->and($entry->isRemapped())->toBeFalse()
        ->and($entry->appliedAt)->toBeNull()
        ->and($entry->recordedState)->toBeNull();
});

it('lets the first matching rule win', function () {
    expect(($this->at)('scored')->statusView('ordered')->get()->key)->toBe('first')
        ->and(($this->at)('in_review')->statusView('ordered')->get()->key)->toBe('second');
});

it('claims every child when a rule names a phase key', function () {
    $view = fn (string $state) => ($this->at)($state)->statusView('applicant')->for($this->applicant)->get()->key;

    expect($view('in_review'))->toBe('under_review')
        ->and($view('scored'))->toBe('under_review')
        // PROCESSING is both a state and a phase key, so it claims its sibling too.
        ->and($view('processing'))->toBe('in_progress')
        ->and($view('auditing'))->toBe('in_progress')
        ->and($view('closed'))->toBe('closed');
});

it('claims only the state itself when a rule names a state that is no phase key', function () {
    expect(($this->at)('auditing')->statusView('state_only')->get()->key)->toBe('auditing_shown')
        ->and(($this->at)('processing')->statusView('state_only')->get()->key)->toBe('processing');
});

it('decides per viewer, a null viewer included', function () {
    $workflow = ($this->at)('auditing');

    expect($workflow->statusView('applicant')->for($this->applicant)->get()->key)->toBe('in_progress')
        ->and($workflow->statusView('applicant')->for($this->staff)->get()->key)->toBe('auditing')
        ->and($workflow->statusView('applicant')->get()->key)->toBe('in_progress');
});

it('accepts closures, condition instances and container-resolved class-strings alike', function () {
    $workflow = ($this->at)('in_review');

    expect($workflow->statusView('kinds')->for($this->staff)->get()->key)->toBe('by_instance')
        ->and($workflow->statusView('kinds')->for($this->applicant)->get()->key)->toBe('nobody');

    $byClass = $workflow->statusView('kinds')->for($this->staff)
        ->rule(StatusRule::show(S::IN_REVIEW)->as('per_read')->when(fn () => false))
        ->get();

    expect($byClass->key)->toBe('by_instance');

    app()->bind(IsStaff::class, fn () => new class extends \stdClass implements \Flowra\Contracts\StatusConditionContract {
        public function allows(Status $status, \Flowra\Contracts\HasWorkflowContract $owner, mixed $viewer = null): bool
        {
            return true;
        }
    });

    // The bound class-string now passes for anyone; the instance rule still checks the viewer.
    expect($workflow->statusView('kinds')->for($this->applicant)->get()->key)->toBe('by_class');
});

it('runs resolvers before rules and falls through when they return null', function () {
    expect(($this->at)('closed')->statusView('resolved')->get()->key)->toBe('archived')
        ->and(($this->at)('submitted')->statusView('resolved')->get()->key)->toBe('received');

    $entry = ($this->at)('submitted')->statusView('resolved')->using(fn () => S::DRAFT)->get();

    expect($entry->key)->toBe('draft')->and($entry->isRemapped())->toBeTrue();
});

it('redacts a hidden status on the DTO itself', function () {
    $entry = ($this->at)('processing')->statusView('applicant')->for($this->applicant)->get();

    expect($entry->key)->toBe('in_progress')
        ->and($entry->state)->toBeNull()
        ->and($entry->isRemapped())->toBeTrue()
        ->and($entry->isRedacted())->toBeTrue()
        ->and($entry->recordedState)->toBeNull()
        ->and($entry->appliedBy)->toBeNull()
        ->and($entry->comment)->toBe([]);
});

it('never leaks the real state, comment or actor through serialization', function (string $state, string $view) {
    $json = json_encode(($this->at)($state)->statusView($view)->for($this->applicant)->get());

    expect($json)->not->toContain('"'.$state.'"')
        ->and($json)->not->toContain('internal ledger')
        ->and($json)->not->toContain('424242');
})->with([
    'remapped' => ['auditing', 'state_only'],
    'remapped through a phase' => ['scored', 'applicant'],
    'hidden' => ['auditing', 'applicant'],
    'hidden despite keepDetails' => ['closed', 'details'],
]);

it('keeps the comment and actor on a remapped status only when asked', function () {
    $kept = ($this->at)('submitted')->statusView('details')->get();
    $dropped = ($this->at)('draft')->statusView('details')->get();
    $hidden = ($this->at)('closed')->statusView('details')->get();

    expect($kept->appliedBy)->toBe(424242)
        ->and($kept->comment)->toBe(['Checked the internal ledger'])
        ->and($kept->recordedState)->toBe('submitted')
        ->and($dropped->appliedBy)->toBeNull()
        ->and($dropped->comment)->toBe([])
        ->and($hidden->appliedBy)->toBeNull()
        ->and($hidden->comment)->toBe([]);
});

it('copes with a status row that recorded no comment', function () {
    expect(($this->at)('submitted', ['comment' => null])->statusView()->get()->comment)->toBe([]);
});

it('resolves labels: rule label, view prefix, phase label, flowra lang, headline', function () {
    app('translator')->addLines([
        'tests.status.prefixed' => 'From The Prefix',
        'tests.status.custom' => 'Prefix Loses To The Rule Label',
    ], 'en');
    app('translator')->addLines(['flowra.states.closed' => 'Closed For Good'], 'en', 'flowra');

    $label = fn (string $state) => ($this->at)($state)->statusView('labelled')->get()->label();

    expect($label('draft'))->toBe('Literal Label')
        ->and($label('submitted'))->toBe('From The Prefix')
        ->and($label('in_review'))->toBe('Review Step')
        ->and($label('scored'))->toBe('Closed For Good')
        ->and($label('auditing'))->toBe('Auditing');
});

it('keeps the transition and from-state with the details, never in the payload', function () {
    $real = ($this->at)('scored', ['transition' => 'score', 'from' => 'in_review'])->statusView()->get();
    $remapped = ($this->at)('scored', ['transition' => 'score', 'from' => 'in_review'])->statusView('applicant')->get();

    expect($real->transition)->toBe('score')
        ->and($real->from)->toBe('in_review')
        ->and($real->toArray())->not->toHaveKeys(['transition', 'from'])
        ->and($remapped->transition)->toBeNull()
        ->and($remapped->from)->toBeNull();
});

it('labels the serialized state through the view prefix too', function () {
    app('translator')->addLines(['tests.status.closed' => 'Closed Under Prefix'], 'en');

    $array = ($this->at)('scored')->statusView('labelled')->get()->toArray();

    expect($array['state'])->toBe(['key' => 'closed', 'label' => 'Closed Under Prefix']);
});

it('serializes the displayed state and phase separately', function () {
    $array = ($this->at)('scored')->statusView('applicant')->for($this->applicant)->get()->toArray();

    expect($array)->toMatchArray([
        'key' => 'under_review',
        'label' => 'Review Step',
        'state' => null,
        'phase' => ['key' => 'under_review', 'label' => 'Review Step'],
        'applied_by' => null,
        'comment' => [],
        'remapped' => true,
        'redacted' => false,
    ])->and($array)->not->toHaveKey('recorded_state');
});

it('lets a workflow view override a config view, and a config view override default', function () {
    config(['flowra.status_views.views' => [
        'default' => ['rules' => [['any' => true, 'as' => 'from_config_default']]],
        'shared' => ['rules' => [['any' => true, 'as' => 'from_config']]],
        'config_only' => [
            'labels' => 'tests.status',
            'rules' => [['show' => ['submitted'], 'as' => 'staff_only', 'conditions' => [IsStaff::class]]],
        ],
    ]]);
    StatusViewResolver::flush();

    $workflow = ($this->at)('submitted');

    expect($workflow->statusView()->get()->key)->toBe('from_config_default')
        ->and($workflow->statusView('shared')->get()->key)->toBe('from_workflow')
        ->and($workflow->statusView('config_only')->for($this->staff)->get()->key)->toBe('staff_only')
        ->and($workflow->statusView('config_only')->for($this->applicant)->get()->key)->toBe('submitted');
});

it('throws on an unknown view', function () {
    ($this->at)('draft')->statusView('nope');
})->throws(StatusViewNotFoundException::class, 'Status view (nope) is not defined');

it('checks per-read rules before the view rules', function () {
    $entry = ($this->at)('scored')->statusView('ordered')->show(S::SCORED)->as('per_read')->get();

    expect($entry->key)->toBe('per_read');
});

it('refuses a hide rule with nothing to show in its place', function () {
    ($this->at)('draft')->statusView()->hide(S::DRAFT)->get();
})->throws(LogicException::class, 'needs as()');

it('refuses as() before any rule', function () {
    StatusView::make('broken')->as('x');
})->throws(LogicException::class);

it('reads the already hydrated status without a query of its own', function () {
    $workflow = ($this->at)('auditing');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $workflow->statusView('applicant')->for($this->applicant)->get();
    $workflow->statusView()->get()->toArray();

    expect(DB::getQueryLog())->toBe([]);
});

it('rejects closures in config-declared views', function () {
    StatusView::fromArray('bad', ['rules' => [['show' => ['draft'], 'as' => 'x', 'conditions' => [fn () => true]]]]);
})->throws(InvalidArgumentException::class, 'class-strings');

it('builds a config view from class-strings', function () {
    $view = StatusView::fromArray('good', [
        'labels' => 'tests.status',
        'fallback' => 'not_started',
        'rules' => [
            ['hide' => ['auditing'], 'as' => 'in_progress', 'conditions' => [IsStaff::class]],
            ['show' => ['draft'], 'as' => 'x', 'keep_details' => true],
        ],
    ]);

    expect($view->rules())->toHaveCount(2)
        ->and($view->rules()[0]->hides())->toBeTrue()
        ->and($view->rules()[0]->conditions())->toBe([IsStaff::class])
        ->and($view->rules()[1]->keepsDetails())->toBeTrue()
        ->and($view->labelPrefix())->toBe('tests.status')
        ->and($view->fallbackKey())->toBe('not_started');
});
