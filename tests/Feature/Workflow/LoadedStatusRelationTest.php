<?php

use Flowra\Models\Status;
use Flowra\Support\StatusViewResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Application;
use Tests\Fixtures\Workflows\StatusWorkflow\StatusWorkflow;
use Tests\Fixtures\Workflows\StatusWorkflow\StatusWorkflowStates as S;

beforeEach(function () {
    config(['flowra.cache_workflows' => false]);

    StatusViewResolver::flush();

    Schema::create('applications', fn (Blueprint $table) => $table->id());

    foreach (['submitted', 'auditing', 'closed'] as $state) {
        $application = Application::query()->create();

        Status::query()->create([
            'owner_type' => $application->getMorphClass(),
            'owner_id' => $application->getKey(),
            'workflow' => StatusWorkflow::class,
            'transition' => 'some_transition',
            'to' => $state,
            'comment' => [],
        ]);
    }

    Application::query()->create(); // no status yet
});

it('builds each workflow from an eager-loaded status relation without a query per row', function () {
    $applications = Application::query()->with('statusWorkflowStatus')->get();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $keys = $applications->map(fn (Application $app) => $app->statusWorkflow->statusView('applicant')->get()?->key)->all();

    expect(DB::getQueryLog())->toBe([])
        ->and($keys)->toBe(['submitted', 'in_progress', 'closed', null]);
});

it('still reads the status itself when the relation is not loaded', function () {
    $applications = Application::query()->get();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $states = $applications->map(fn (Application $app) => $app->statusWorkflow->currentState)->all();

    expect(DB::getQueryLog())->toHaveCount(4)
        ->and($states)->toBe([S::SUBMITTED, S::AUDITING, S::CLOSED, null]);
});

it('keeps a loaded relation in step with a status the workflow writes', function () {
    $application = Application::query()->with('statusWorkflowStatus')->latest('id')->first();

    expect($application->statusWorkflowStatus)->toBeNull();

    $application->statusWorkflow->submit->apply();

    expect($application->statusWorkflowStatus?->to)->toBe('submitted')
        ->and((new StatusWorkflow($application))->currentState)->toBe(S::SUBMITTED);

    $application->statusWorkflow->jumpTo(S::CLOSED);

    expect($application->statusWorkflowStatus->to)->toBe('closed')
        ->and((new StatusWorkflow($application))->currentState)->toBe(S::CLOSED);
});
