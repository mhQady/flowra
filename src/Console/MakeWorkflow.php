<?php

namespace Flowra\Console;

use Flowra\Support\WorkflowPathResolver;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

class MakeWorkflow extends Command
{
    protected $signature = 'flowra:make-workflow
        {name : Workflow name, e.g. Main}
        {--namespace=App\\Workflows : Root namespace for generated classes}
        {--force : Overwrite existing files}';

    protected $description = 'Generate a workflow skeleton (Workflow.php + WorkflowStates.php) into a dedicated folder';

    public function handle(Filesystem $files): int
    {
        $this->line('<info> ⚙️ ----------------------------------------------------------------⤵⤵ </info>');

        $studly = Str::studly(trim($this->argument('name')));

        if (!Str::endsWith($studly, 'Workflow')) {
            $studly .= 'Workflow';
        }

        $snake = Str::snake($studly);

        $dir = WorkflowPathResolver::workflowDirectory($studly);
        $namespaceRoot = rtrim($this->option('namespace'), '\\');
        $namespace = $namespaceRoot.'\\'.$studly;
        $statesEnum = $studly.'States';

        $this->__creatWorkflowDirectory($files, $dir);

        $replacements = [
            '{{ namespace }}' => $namespace,
            '{{ root_namespace }}' => $namespaceRoot,
            '{{ class }}' => $studly,
            '{{ states_class }}' => $statesEnum,
            '{{ flow_key }}' => $snake,
            '{{ flow_title }}' => Str::of($snake)->replace('_', ' ')->title(),
        ];

        $renderedWorkflow = strtr($this->__getStub('workflow.stub'), $replacements);
        $renderedStates = strtr($this->__getStub('workflowstates.stub'), $replacements);

        $workflowFileName = $studly.'.php';
        $statesFileName = $statesEnum.'.php';

        $written = 0;
        $written += $this->__putFile($files, $dir.'/'.$workflowFileName, $renderedWorkflow,
            (bool) $this->option('force'));
        $written += $this->__putFile($files, $dir.'/'.$statesFileName, $renderedStates,
            (bool) $this->option('force'));

        if ($written === 0) {
            $this->warn('⏭  Nothing written. Use --force to overwrite.');
            return self::SUCCESS;
        }

        $this->info("✅ Workflow generated: {$namespace}\\{$studly} (and {$statesEnum})");
        $this->line("<comment>• Folder:</comment> {$dir}");
        $this->line("<comment>• Files:</comment>  $workflowFileName, $statesFileName");

        $this->line('<info> ----------------------------------------------------------------⤴⤴ </info>');

        return self::SUCCESS;
    }

    /**
     * Get the contents of a stub file.
     *
     * @param  string  $name  The name of the stub file.
     * @return string The contents of the stub file.
     * @throws RuntimeException If the stub file is not found.
     */
    private function __getStub(string $name): string
    {
        $appStub = base_path('stubs/flowra/'.$name);
        if (is_file($appStub)) {
            $contents = file_get_contents($appStub);
            if ($contents !== false) {
                return $contents;
            }
        }

        $packageStub = dirname(__DIR__, 1).'/stubs/'.$name;
        if (is_file($packageStub)) {
            $contents = file_get_contents($packageStub);
            if ($contents !== false) {
                return $contents;
            }
        }

        throw new RuntimeException(
            "Stub not found: {$name}\n".
            "Looked in:\n - {$appStub}\n - {$packageStub}"
        );
    }

    /**
     * Write flow files to disk.
     *
     * @param  Filesystem  $files  The filesystem instance.
     * @param  string  $path  The path to the file.
     * @param  string  $contents  The contents of the file.
     * @param  bool  $force  Whether to overwrite the file if it exists.
     *
     * @return int The number of files written (0 or 1).
     */
    private function __putFile(Filesystem $files, string $path, string $contents, bool $force): int
    {
        if ($files->exists($path) && !$force) {
            $this->warn("   • Skipped (exists): {$path}");
            return 0;
        }
        $files->put($path, $contents);
        $this->line("   • Wrote: <info>{$path}</info>");
        return 1;
    }

    /**
     * Make flow base folder
     *
     * @param  Filesystem  $files
     * @param  string  $dir
     * @return void
     */
    private function __creatWorkflowDirectory(Filesystem $files, string $dir): void
    {
        if (!$files->isDirectory($dir)) {
            $files->makeDirectory($dir, 0777, true);
        }
    }
}
