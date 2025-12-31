<?php

namespace Flowra\Console;

use Flowra\Support\WorkflowDiagramImporter;
use Flowra\Support\WorkflowPathResolver;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/**
 * Artisan command that converts Mermaid/PlantUML diagrams into Flowra workflow classes.
 */
final class ImportWorkflowDiagram extends Command
{
    protected $signature = 'flowra:import-workflow
        {workflow : Fully qualified workflow class name}
        {--format=auto : Diagram format (auto, mermaid, plantuml)}
        {--path= : Optional path to the Mermaid/PlantUML file}
        {--force : Overwrite workflow/state files if they already exist}';

    protected $description = 'Convert a Mermaid/PlantUML diagram into Flowra enum cases and transition definitions.';

    /**
     * Execute the import process and generate the workflow/state artifacts.
     *
     * @param  Filesystem  $files
     * @return int
     */
    public function handle(Filesystem $files): int
    {
        $format = strtolower((string) $this->option('format'));
        $diagramPath = $this->option('path');

        try {

            $diagram = $this->__resolveDiagramInput($diagramPath);

            throw_if(
                $diagram === null,
                new \RuntimeException('No diagram input detected. Provide --path or pipe the diagram via STDIN.')
            );

            [
                'namespace' => $workflowNamespace,
                'workflowShort' => $workflowShort,
                'statesShort' => $statesEnumShort,
                'workflowPath' => $workflowPath,
                'statesPath' => $statesPath,
            ] = $this->__resolveWorkflowContext((string) $this->argument('workflow'));


            $parsed = $this->__getParsedData($diagram, $format, $statesEnumShort);


        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        try {
            $this->__writeStatesFile(
                $files,
                $statesPath,
                $workflowNamespace,
                $statesEnumShort,
                $parsed['states_snippet'],
                $this->option('force'),
                $parsed['groups_snippet']
            );
            $this->__writeWorkflowFile(
                $files,
                $workflowPath,
                $workflowNamespace,
                $workflowShort,
                $parsed['transitions_snippet'],
                $this->option('force')
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Generated states enum {$statesEnumShort} at [{$statesPath}].");
        $this->info("Generated workflow class {$workflowShort} at [{$workflowPath}].");

        return self::SUCCESS;
    }


    private function __getParsedData($diagram, $format, $statesEnumShort): array
    {
        $parsed = new WorkflowDiagramImporter()->parse($diagram, $format);

        return [
            'states_snippet' => $this->buildStatesSnippet($parsed['states']),
            'groups_snippet' => $this->buildGroupsSnippet($parsed['groups'], $parsed['states']),
            'transitions_snippet' => $this->buildTransitionsSnippet($parsed['states'], $parsed['transitions'],
                $statesEnumShort),
        ];
    }

    /**
     * Split a fully-qualified class name into namespace and short class components.
     *
     * @return array{0: string, 1: string}
     */
    private function __describeClass(string $class): array
    {
        $class = ltrim($class, '\\');

        if (!str_contains($class, '\\')) {
            return ['', $class];
        }

        $namespace = Str::beforeLast($class, '\\');
        $short = Str::afterLast($class, '\\');

        return [$namespace, $short];
    }

    /**
     * Resolve the diagram contents either from a file on disk or STDIN.
     *
     * @throws \InvalidArgumentException
     */
    private function __resolveDiagramInput(?string $path): ?string
    {
        if ($path) {
            if (!is_file($path)) {
                throw new \InvalidArgumentException("Diagram file [{$path}] does not exist.");
            }

            return file_get_contents($path) ?: null;
        }

        $this->info('📥 Paste your diagram below:');
        $this->comment('⌨️ Note: Finish with CTRL+D (macOS/Linux) or CTRL+Z then ENTER (Windows).');

        # read from standard input
        $contents = stream_get_contents(STDIN);

        return trim($contents) !== '' ? $contents : null;
    }

    /**
     * Determine the matching states enum class for the supplied workflow class.
     */
    private function __inferstatesEnum(string $workflow): string
    {
        $workflow = ltrim($workflow, '\\');

        if (Str::endsWith($workflow, 'Workflow')) {
            return "{$workflow}States";
        }

        if (Str::endsWith($workflow, 'WorkflowStates')) {
            return $workflow;
        }

        return "{$workflow}WorkflowStates";
    }

    /**
     * Prepare normalized class, namespace, and file path data for workflow imports.
     *
     * @param  string  $workflow
     * @return array{
     *     namespace: string,
     *     workflowShort: string,
     *     statesShort: string,
     *     workflowPath: string,
     *     statesPath: string
     * }
     */
    private function __resolveWorkflowContext(string $workflow): array
    {
        $workflowClass = $this->__normalizeWorkflowClass(trim($workflow));
        $statesEnum = $this->__inferstatesEnum($workflowClass);

        [$namespace, $workflowShort] = $this->__describeClass($workflowClass);
        $statesShort = Str::afterLast($statesEnum, '\\');
        $workflowDirectory = $this->defaultWorkflowDirectory($workflowShort);

        $workflowPath = $this->determineClassPath($workflowClass, $workflowDirectory);
        $statesPath = $this->determineSiblingStatesPath($workflowPath, $statesEnum);

        return [
            'namespace' => $namespace,
            'workflowShort' => $workflowShort,
            'statesShort' => $statesShort,
            'workflowPath' => $workflowPath,
            'statesPath' => $statesPath,
        ];
    }

    /**
     * Build the enum case snippet for the generated states class.
     *
     * @param  array<string, array{id: string, case: string, value: string, label: string}>  $states
     * @return string
     */
    private function buildStatesSnippet(array $states): string
    {
        $lines = [];

        foreach ($states as $state) {
            $lines[] = sprintf("    case %s = '%s';", $state['case'], $state['value']);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Build the method body for the workflow transitions schema.
     *
     * @param  array<string, array{id: string, case: string, value: string, label: string}>  $states
     * @param  array<int, array{key: string, from: string, to: string, label: string}>  $transitions
     * @return string
     */
    private function buildTransitionsSnippet(array $states, array $transitions, string $statesEnumShort): string
    {
        $snippets = [];

        foreach ($transitions as $transition) {
            $fromCase = $states[$transition['from']]['case'] ?? strtoupper($transition['from']);
            $toCase = $states[$transition['to']]['case'] ?? strtoupper($transition['to']);

            $snippets[] = <<<PHP
        Transition::make(
            key: '{$transition['key']}',
            from: {$statesEnumShort}::{$fromCase},
            to: {$statesEnumShort}::{$toCase},
        ),
    PHP;
        }

        return "        return [\n".implode(PHP_EOL.PHP_EOL, $snippets)."\n        ];";
    }


    /**
     * Determine where a generated class should be written on disk.
     *
     * @param  string  $class
     * @param  string  $workflowDirectory
     */
    private function determineClassPath(string $class, string $workflowDirectory): string
    {
        return rtrim($workflowDirectory,
                DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->__describeClass($class)[1].'.php';
    }

    /**
     * States enum should live next to its workflow file.
     *
     * @param  string  $workflowPath
     * @param  string  $statesEnum
     */
    private function determineSiblingStatesPath(string $workflowPath, string $statesEnum): string
    {
        $directory = rtrim(dirname($workflowPath), DIRECTORY_SEPARATOR);
        $directory = $directory === '' ? DIRECTORY_SEPARATOR : $directory;

        return $directory.DIRECTORY_SEPARATOR.$this->__describeClass($statesEnum)[1].'.php';
    }

    /**
     * Default directory for auto-generated workflows.
     *
     * @param  string  $workflowShort
     */
    private function defaultWorkflowDirectory(string $workflowShort): string
    {
        return WorkflowPathResolver::workflowDirectory($workflowShort);
    }


    /**
     * Normalize user input into a fully-qualified workflow class name.
     *
     * @param  string  $workflow
     */
    private function __normalizeWorkflowClass(string $workflow): string
    {
        $workflow = ltrim($workflow, '\\');

        if (str_contains($workflow, '\\')) {
            return $workflow;
        }

        $workflow = Str::studly($workflow);

        return "App\\Workflows\\{$workflow}\\{$workflow}";
    }

    /**
     * Build the groups() method snippet describing nested state groups.
     *
     * @param  array<int, array{parent: string, children: array<int, string>}>  $groups
     * @param  array<string, array{id: string, case: string, value: string, label: string}>  $states
     * @return string|null
     */
    private function buildGroupsSnippet(array $groups, array $states): ?string
    {
        if ($groups === []) {
            return null;
        }

        $blocks = [];

        foreach ($groups as $group) {
            if ($group['children'] === []) {
                continue;
            }

            $parentCase = $states[$group['parent']]['case'] ?? strtoupper($group['parent']);
            $childrenCases = array_map(
                fn(string $child) => $states[$child]['case'] ?? strtoupper($child),
                $group['children']
            );

            $childrenBody = implode(",\n                ", array_map(
                fn(string $case) => "self::{$case}",
                $childrenCases
            ));

            $blocks[] = <<<PHP
            StateGroup::make(self::{$parentCase})->children(
                {$childrenBody},
            ),
    PHP;
        }

        if ($blocks === []) {
            return null;
        }

        $body = implode(PHP_EOL.PHP_EOL, $blocks);

        return <<<PHP
    /**
     * Describe states that act as groups/nodes for nested states.
     *
     * @return array<StateGroup|array>
     */
    public static function groups(): array
    {
        return [
{$body}
        ];
    }

PHP;
    }

    /**
     * Write the generated enum file to disk.
     *
     * @param  Filesystem  $files
     * @param  string  $path
     * @param  string  $namespace
     * @param  string  $class
     * @param  string  $cases
     * @param  bool  $force
     * @param  string|null  $groupsSnippet
     */
    private function __writeStatesFile(
        Filesystem $files,
        string $path,
        string $namespace,
        string $class,
        string $cases,
        bool $force,
        ?string $groupsSnippet = null
    ): void {
        $header = "<?php\n\n";
        if ($namespace !== '') {
            $header .= "namespace {$namespace};\n\n";
        }
        $header .= "use Flowra\\Enums\\BaseEnum;\n";
        if ($groupsSnippet !== null) {
            $header .= "use Flowra\\DTOs\\StateGroup;\n";
        }
        $header .= "\n";

        $body = rtrim($cases);

        if ($groupsSnippet !== null) {
            $body .= "\n\n".rtrim($groupsSnippet);
        }

        $body .= "\n";

        $contents = <<<PHP
{$header}
enum {$class}: string
{
    use BaseEnum;

{$body}
}

PHP;

        $this->writeFile($files, $path, $contents, $force);
    }

    /**
     * Write the generated workflow class to disk.
     *
     * @param  Filesystem  $files
     * @param  string  $path
     * @param  string  $namespace
     * @param  string  $class
     * @param  string  $transitionsSnippet
     * @param  bool  $force
     */
    private function __writeWorkflowFile(
        Filesystem $files,
        string $path,
        string $namespace,
        string $class,
        string $transitionsSnippet,
        bool $force
    ): void {
        $header = "<?php\n\n";
        if ($namespace !== '') {
            $header .= "namespace {$namespace};\n\n";
        }
        $header .= "use Flowra\\Concretes\\BaseWorkflow;\nuse Flowra\\Contracts\\BaseWorkflowContract;\nuse Flowra\\DTOs\\Transition;\n\n";

        $contents = <<<PHP
{$header}
class {$class} extends BaseWorkflow implements BaseWorkflowContract
{
    /**
     * @return array|Transition[]
     */
    public static function transitionsSchema(): array
    {
{$transitionsSnippet}
    }
}

PHP;

        $this->writeFile($files, $path, $contents, $force);
    }

    /**
     * Persist file contents to disk.
     *
     * @param  Filesystem  $files
     * @param  string  $path
     * @param  string  $contents
     * @param  bool  $force
     */
    private function writeFile(Filesystem $files, string $path, string $contents, bool $force): void
    {
        $directory = dirname($path);

        if (!$files->isDirectory($directory)) {
            $files->makeDirectory($directory, 0777, true);
        }

        if ($files->exists($path) && !$force) {
            throw new \RuntimeException("File [{$path}] already exists. Use --force to overwrite.");
        }

        $files->put($path, $contents);
    }
}
