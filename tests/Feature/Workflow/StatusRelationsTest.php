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

beforeEach(function () {
    config([
        'flowra.cache_workflows' => false,
        'flowra.models.status' => Status::class,
    ]);

    StatusViewResolver::flush();

    Schema::create('applications', fn (Blueprint $table) => $table->id());
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    User::query()->create(['id' => 7, 'name' => 'Reviewer']);

    $this->at = function (string $state): StatusWorkflow {
        $application = Application::query()->create();

        Status::query()->create([
            'owner_type' => $application->getMorphClass(),
            'owner_id' => $application->getKey(),
            'workflow' => StatusWorkflow::class,
            'transition' => 'some_transition',
            'to' => $state,
            'comment' => [],
            'applied_by' => 7,
        ]);

        return new StatusWorkflow($application);
    };
});

it('loads the view relations on a real status', function () {
    $entry = ($this->at)('scored')->statusView('with_actor')->get();

    expect($entry->relations['actor']?->name)->toBe('Reviewer')
        ->and($entry->toArray()['relations']['actor']['name'])->toBe('Reviewer');
});

it('loads them on a remapped status only when its rule keeps the details', function () {
    $kept = ($this->at)('submitted')->statusView('with_actor')->get();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $dropped = ($this->at)('draft')->statusView('with_actor')->get();
    $hidden = ($this->at)('closed')->statusView('with_actor')->get();

    expect($kept->relations['actor']->name)->toBe('Reviewer')
        ->and($dropped->relations)->toBe([])
        ->and($hidden->relations)->toBe([])
        ->and($dropped->toArray())->not->toHaveKey('relations')
        ->and(json_encode($hidden))->not->toContain('Reviewer')
        // Nothing is loaded for an entry that would not carry it: only the two status reads ran.
        ->and(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"users"')))->toBeEmpty();
});

it('adds per-read relations to the view ones', function () {
    $entry = ($this->at)('scored')->statusView()->with('actor')->get();

    expect($entry->relations['actor']->name)->toBe('Reviewer');
});

it('hands over only the relations asked for, nested ones riding on them', function () {
    $entry = ($this->at)('scored')->statusView()->with('actor')->get();

    expect(array_keys($entry->relations))->toBe(['actor']);
});

it('reuses relations a list already eager-loaded', function () {
    foreach (['scored', 'submitted'] as $state) {
        ($this->at)($state);
    }

    $applications = Application::query()->with('statusWorkflowStatus.actor')->get();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $names = $applications->map(fn (Application $app) => $app->statusWorkflow->statusView()->with('actor')->get()->relations['actor']->name);

    expect(DB::getQueryLog())->toBe([])
        ->and($names->all())->toBe(['Reviewer', 'Reviewer']);
});

it('reads with from config', function () {
    expect(StatusView::fromArray('x', ['with' => ['actor']])->eagerLoads())->toBe(['actor']);
});
