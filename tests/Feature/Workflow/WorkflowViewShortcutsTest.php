<?php

use Flowra\Exceptions\RegistryViewNotFoundException;
use Flowra\Exceptions\StatusViewNotFoundException;
use Flowra\Models\Registry;
use Flowra\Models\Status;
use Flowra\Support\RegistryBuilder;
use Flowra\Support\RegistryViewResolver;
use Flowra\Support\StatusViewBuilder;
use Flowra\Support\StatusViewResolver;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Application;
use Tests\Fixtures\Workflows\StatusWorkflow\StatusWorkflow;

beforeEach(function () {
    config([
        'flowra.cache_workflows' => false,
        'flowra.registry_views.views' => ['customer' => ['shape' => 'collapsed']],
    ]);

    RegistryViewResolver::flush();
    StatusViewResolver::flush();

    Schema::create('applications', fn (Blueprint $table) => $table->id());

    $this->application = Application::query()->create();

    $row = [
        'owner_type' => $this->application->getMorphClass(),
        'owner_id' => $this->application->getKey(),
        'workflow' => StatusWorkflow::class,
        'comment' => [],
    ];

    foreach ([['start_review', 'submitted', 'in_review'], ['score', 'in_review', 'scored']] as $minute => [$transition, $from, $to]) {
        Registry::query()->create($row + compact('transition', 'from', 'to') + ['created_at' => now()->addMinutes($minute)]);
    }

    Status::query()->create($row + ['transition' => 'process', 'from' => 'scored', 'to' => 'processing']);
});

it('registers the registry view beside the relations, starting from the default view', function () {
    $builder = $this->application->statusWorkflowRegistryView();

    expect($builder)->toBeInstanceOf(RegistryBuilder::class)
        ->and($builder->viewName())->toBe('default')
        ->and($builder->get()->map->key->all())->toBe(['in_review', 'scored']);
});

it('switches the registry view with view()', function () {
    $entries = $this->application->statusWorkflowRegistryView()->view('customer')->latest()->get();

    expect($entries->map->key->all())->toBe(['under_review'])
        ->and(json_encode($entries))
        ->toBe(json_encode($this->application->statusWorkflow->registryView('customer')->latest()->get()));
});

it('registers the status view the same way and switches it with view()', function () {
    $builder = $this->application->statusWorkflowStatusView();

    expect($builder)->toBeInstanceOf(StatusViewBuilder::class)
        ->and($builder->get()->key)->toBe('processing')
        ->and($this->application->statusWorkflowStatusView()->view('applicant')->get()->key)->toBe('in_progress');
});

it('carries the viewer over to the switched view', function () {
    $staff = (object) ['staff' => true];

    // The applicant view hides processing from everyone but staff.
    expect($this->application->statusWorkflowStatusView()->for($staff)->view('applicant')->get()->key)->toBe('processing');
});

it('refuses to switch views after another per-read option', function () {
    $this->application->statusWorkflowRegistryView()->collapsed()->view('customer');
})->throws(LogicException::class, 'Call view() before');

it('refuses a status view switch after another per-read option', function () {
    $this->application->statusWorkflowStatusView()->phaseOnly()->view('applicant');
})->throws(LogicException::class, 'Call view() before');

it('throws on an unknown view name', function (string $accessor, string $exception) {
    expect(fn () => $this->application->{$accessor}()->view('nope'))->toThrow($exception);
})->with([
    'registry' => ['statusWorkflowRegistryView', RegistryViewNotFoundException::class],
    'status' => ['statusWorkflowStatusView', StatusViewNotFoundException::class],
]);

it('leaves the relations and other calls to eloquent', function () {
    expect($this->application->statusWorkflowStatus())->toBeInstanceOf(MorphOne::class);

    $this->application->unknownWorkflowRegistryView();
})->throws(BadMethodCallException::class);
