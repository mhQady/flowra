<?php

namespace Flowra\Console;

use Flowra\Support\WorkflowDiagramExporter;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

class ExportWorkflowDiagram extends Command
{
    protected $signature = 'flowra:export-workflow
        {workflow : Workflow class name (FQN optional)}
        {--format=mermaid : Output format (mermaid or plantuml)}
        {--output= : Optional path to save the diagram instead of printing it}';

    protected $description = 'Export a workflow definition to Mermaid or PlantUML.';

    public function handle(WorkflowDiagramExporter $exporter, Filesystem $files): int
    {
        $workflow = trim((string) $this->argument('workflow'));
        $format = strtolower((string) $this->option('format'));
        $outputPath = $this->option('output');
        $workflowClass = $this->normalizeWorkflowClass($workflow);

        try {
            $diagram = $exporter->render($workflowClass, $format);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if (empty($outputPath)) {
            $outputPath = $this->defaultOutputPath($workflowClass, $format);
            $this->line($diagram);
        }

        $this->writeDiagram($files, $outputPath, $diagram);
        $this->info("Diagram exported to [{$outputPath}]");

        return self::SUCCESS;
    }

    private function writeDiagram(Filesystem $files, string $path, string $contents): void
    {
        $directory = dirname($path);

        if ($directory !== '.' && !$files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0777, true);
        }

        $files->put($path, $contents);
    }

    private function defaultOutputPath(string $workflow, string $format): string
    {
        $fileName = str_replace(['\\', '/'], '-', trim($workflow, '\\/'));
        $extension = $this->extensionForFormat($format);

        return storage_path("app/flowra/workflows/{$fileName}.{$extension}");
    }

    private function normalizeWorkflowClass(string $workflow): string
    {
        $workflow = ltrim($workflow, '\\');

        if ($workflow === '') {
            return $workflow;
        }

        if (str_contains($workflow, '\\')) {
            return $workflow;
        }

        $workflow = Str::studly($workflow);

        if (!Str::endsWith($workflow, 'Workflow')) {
            $workflow .= 'Workflow';
        }

        $namespaceRoot = trim((string) config('flowra.workflows_namespace', 'App\\Workflows'), '\\');
        $candidates = [
            "{$namespaceRoot}\\{$workflow}\\{$workflow}",
            "{$namespaceRoot}\\{$workflow}",
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    private function extensionForFormat(string $format): string
    {
        return match ($format) {
            'mermaid' => 'mmd',
            'plantuml', 'plant-uml', 'plant' => 'puml',
            default => 'txt',
        };
    }
}
