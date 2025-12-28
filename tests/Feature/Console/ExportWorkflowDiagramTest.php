<?php

use Flowra\Flows\MainWorkflow\MainWorkflow;

it('exports a workflow as a mermaid diagram', function () {
    $path = storage_path('app/flowra/workflows/Flowra-Flows-MainFlow-MainWorkflow.mmd');

    if (file_exists($path)) {
        unlink($path);
    }

    $this->artisan('flowra:export-workflow', [
        'workflow' => MainWorkflow::class,
    ])
        ->expectsOutputToContain('stateDiagram-v2')
        ->assertSuccessful();

    expect(file_exists($path))->toBeTrue();
    expect(file_get_contents($path))->toContain('stateDiagram-v2');

    unlink($path);
});

it('writes a PlantUML workflow diagram to disk', function () {
    $path = tempnam(sys_get_temp_dir(), 'flowra-diagram-');

    $this->artisan('flowra:export-workflow', [
        'workflow' => MainWorkflow::class,
        '--format' => 'plantuml',
        '--output' => $path,
    ])
        ->expectsOutputToContain("Diagram exported to [{$path}]")
        ->assertSuccessful();

    $contents = file_get_contents($path);

    expect($contents)->toContain('@startuml')
        ->and($contents)->toContain(MainWorkflow::class)
        ->and($contents)->toContain('sending_for_processing')
        ->and($contents)->toContain('initiating');

    @unlink($path);
});
