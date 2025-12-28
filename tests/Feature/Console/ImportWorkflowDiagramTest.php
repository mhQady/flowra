<?php

it('generates workflow, states, and snippet files from a diagram', function () {
    $fixture = dirname(__DIR__, 2).'/Fixtures/main-workflow-diagram.mmd';
    $workflowClass = 'Flowra\Flows\Generated\DemoWorkflow';
    $baseDirectory = storage_path('app/testing/generated');
    config()->set('flowra.workflows_path', $baseDirectory);

    $workflowDir = "{$baseDirectory}/DemoWorkflow";
    $workflowPath = "{$workflowDir}/DemoWorkflow.php";
    $statesPath = "{$workflowDir}/DemoWorkflowStates.php";
    $snippetsPath = storage_path('app/testing/generated/demo-snippets.php');

    $cleanup = function () use ($workflowPath, $statesPath, $snippetsPath, $workflowDir) {
        collect([$workflowPath, $statesPath, $snippetsPath])->each(function ($path) {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        if (is_dir($workflowDir)) {
            @rmdir($workflowDir);
        }
    };

    $cleanup();

    $this->artisan('flowra:import-workflow', [
        'workflow' => $workflowClass,
        '--path' => $fixture,
        '--output' => $snippetsPath,
        '--force' => true,
    ])->assertSuccessful();

    expect(file_exists($workflowPath))->toBeTrue();
    expect(file_exists($statesPath))->toBeTrue();
    expect(file_exists($snippetsPath))->toBeTrue();

    $workflowContents = file_get_contents($workflowPath);
    $statesContents = file_get_contents($statesPath);
    $snippets = file_get_contents($snippetsPath);

    expect($workflowContents)->toContain('namespace Flowra\\Flows\\Generated;')
        ->and($workflowContents)->toContain('class DemoWorkflow extends BaseWorkflow')
        ->and($workflowContents)->toContain('Transition::make(')
        ->and($workflowContents)->toContain('DemoWorkflowStates::INIT')
        ->and($statesContents)->toContain('enum DemoWorkflowStates: string')
        ->and($statesContents)->toContain("case INIT = 'init';")
        ->and($statesContents)->toContain('use Flowra\\DTOs\\StateGroup;')
        ->and($statesContents)->toContain('public static function groups(): array')
        ->and($statesContents)->toContain('StateGroup::make(self::PREPARE_APPLICATION_INFO)')
        ->and($snippets)->toContain('DemoWorkflowStates')
        ->and($snippets)->toContain('Transition::make')
        ->and($snippets)->toContain('StateGroup::make(self::PREPARE_APPLICATION_INFO)');

    $cleanup();
});

it('does not write snippets when no output path is provided', function () {
    $fixture = dirname(__DIR__, 2).'/Fixtures/main-workflow-diagram.mmd';
    $workflowClass = 'Flowra\Flows\Generated\AnotherWorkflow';
    $baseDirectory = storage_path('app/testing/generated');
    config()->set('flowra.workflows_path', $baseDirectory);

    $workflowDir = "{$baseDirectory}/AnotherWorkflow";
    $workflowPath = "{$workflowDir}/AnotherWorkflow.php";
    $statesPath = "{$workflowDir}/AnotherWorkflowStates.php";
    $defaultSnippet = storage_path('app/flowra/imports/AnotherWorkflow-import.php');

    $cleanup = function () use ($workflowPath, $statesPath, $defaultSnippet, $workflowDir) {
        collect([$workflowPath, $statesPath, $defaultSnippet])->each(function ($path) {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        if (is_dir($workflowDir)) {
            @rmdir($workflowDir);
        }
    };

    $cleanup();

    $this->artisan('flowra:import-workflow', [
        'workflow' => $workflowClass,
        '--path' => $fixture,
        '--force' => true,
    ])->assertSuccessful();

    expect(file_exists($defaultSnippet))->toBeFalse();

    $cleanup();
});

it('writes workflow assets into app/workflows/<Workflow> by default', function () {
    $fixture = dirname(__DIR__, 2).'/Fixtures/main-workflow-diagram.mmd';
    $workflowClass = 'InlineWorkflow';
    $workflowShort = 'InlineWorkflow';
    $statesShort = $workflowShort.'States';

    config()->set('flowra.workflows_path', 'app/workflows');

    $workflowDir = base_path("app/workflows/{$workflowShort}");
    $workflowPath = "{$workflowDir}/{$workflowShort}.php";
    $statesPath = "{$workflowDir}/{$statesShort}.php";

    $cleanup = function () use ($workflowPath, $statesPath, $workflowDir) {
        collect([$workflowPath, $statesPath])->each(
            fn(string $path) => file_exists($path) ? unlink($path) : null
        );

        if (is_dir($workflowDir)) {
            @rmdir($workflowDir);
        }
    };

    $cleanup();

    $this->artisan('flowra:import-workflow', [
        'workflow' => $workflowClass,
        '--path' => $fixture,
        '--force' => true,
    ])->assertSuccessful();

    expect(file_exists($workflowPath))->toBeTrue();
    expect(file_exists($statesPath))->toBeTrue();
    expect(file_get_contents($workflowPath))->toContain('namespace App\\Workflows\\'.$workflowShort.';');
    expect(file_get_contents($statesPath))->toContain('namespace App\\Workflows\\'.$workflowShort.';');

    $cleanup();
});
