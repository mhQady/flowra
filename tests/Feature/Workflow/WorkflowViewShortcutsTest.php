<?php

use Flowra\Support\RegistryBuilder;
use Flowra\Support\RegistryViewResolver;
use Flowra\Support\StatusViewBuilder;
use Flowra\Support\StatusViewResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Models\Application;

beforeEach(function () {
    config(['flowra.cache_workflows' => false]);

    RegistryViewResolver::flush();
    StatusViewResolver::flush();

    Schema::create('applications', fn (Blueprint $table) => $table->id());

    $this->application = Application::query()->create();
});

it('reads the registry view straight off the model', function () {
    $builder = $this->application->statusWorkflowRegistryView();

    expect($builder)->toBeInstanceOf(RegistryBuilder::class)
        ->and($builder->get()->all())
        ->toEqual($this->application->statusWorkflow->registryView()->get()->all());
});

it('reads the status view straight off the model', function () {
    expect($this->application->statusWorkflowStatusView())->toBeInstanceOf(StatusViewBuilder::class);
});

it('leaves other calls to eloquent', function () {
    expect($this->application->statusWorkflowStatus())
        ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphOne::class);

    $this->application->unknownWorkflowRegistryView();
})->throws(BadMethodCallException::class);
