<?php

namespace App\Console\Commands\Workflow;

use App\Models\Workflow;
use App\Services\Workflow\ValidateWorkflowSchemaService;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\search;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class SyncWorkflow extends Command
{
    protected $signature = 'workflow:sync {workflow? : The name of the workflow} {--force : Sync all workflows without interaction}';

    protected $description = 'Sync your workflow data with your database.';

    private string $schemaName;

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    /**
     * @throws Throwable
     */
    public function handle()
    {
        if ($this->option('force')) {
            foreach (getWorkflowsNames() as $name) {
                $this->sync($name);
            }
        } else {
            $this->sync($this->argument('workflow'));
        }
    }

    public function sync($workflowName)
    {

        $this->line('-------------------------------------------------------------------------------⤵⤵ ');
        if (! $this->option('force')) {
            $this->schemaName = match ($this->argument('workflow')) {
                null => (function () {
                    return search(
                        label: 'Search for required Workflow Schema 🔍',
                        options: fn (string $value) => strlen($value) > 0
                            ? getWorkflowsNames()
                            : []
                    );
                })(),
                default => $this->argument('workflow'),
            };
            try {

                ValidateWorkflowSchemaService::validate($this->schemaName);

                $schema = workflow($this->schemaName);
                $dbWorkflow = $this->WorkflowDataToSchema();

                if (! isset($dbWorkflow['workflow']['name'])) {
                    info('There is no matching workflow in your database.🤷‍♂️');
                    $message = 'Would you like to create a new workflow with name: '.$schema['workflow']['name'].'?';
                } else {

                    $diffs = $this->showDiffData($schema, $dbWorkflow);

                    if (count($diffs)) {

                        if (isset($diffs['update'])) {
                            warning("Updates:\n========");
                            table(['Path ', 'Schema', 'DB'], array_values($diffs['update']));
                        }

                        if (isset($diffs['create'])) {
                            warning("Creates:\n========");
                            table(['Path ', 'Schema', 'DB'], array_values($diffs['create']));
                        }

                        if (isset($diffs['delete'])) {
                            warning("Deletes:\n========");
                            table(['Path ', 'Schema', 'DB'], array_values($diffs['delete']));
                        }

                        $message = 'Would you like to sync your workflow with name: '.$schema['workflow']['name'].'?';
                    } else {
                        info("{$schema['workflow']['name']} workflow is already synced with database.");
                        $this->line('-------------------------------------------------------------------------------⤴⤴ ');

                        return;
                    }

                }

                $this->line('-------------------------------------------------------------------------------|| ');

                if (confirm(
                    label: $message,
                    default: false,
                    yes: 'I Do 😁',
                    no: 'I Don\'t 😟',
                )) {

                    $this->syncToDatabase($schema);

                    info('Workflow has been synced with database.✅');
                }

                $this->line('-------------------------------------------------------------------------------⤴⤴ ');

            } catch (Throwable $e) {
                error($e->getMessage());
            }
        } else {
            $this->schemaName = $workflowName;
            try {
                ValidateWorkflowSchemaService::validate($this->schemaName);
                $schema = workflow($this->schemaName);
                $this->syncToDatabase($schema);
                info('Workflow has been synced with database.✅');
            } catch (Throwable $e) {
                error($e->getMessage());
            }
        }

    }

    /**
     * @throws Throwable
     */
    public function syncToDatabase(array $schema)
    {

        try {

            \DB::beginTransaction();
            $workflow = Workflow::updateOrcreate(
                [
                    'name' => $schema['workflow']['name'],
                    'model_type' => $schema['workflow']['model_type'],
                ],
                ['label' => $schema['workflow']['label']]
            );

            $states = collect(array_map(fn ($state) => $workflow->states()->updateOrCreate(
                ['name' => $state['name']],
                ['label' => $state['label']]
            ), $schema['states']));

            foreach ($schema['states'] as $state) {
                $states->where('name', getStandardStr($state['name']))->first()->update([
                    'parent_id' => $state['parent_id'] ? $states->where('name', getStandardStr($state['parent_id']))->first()?->id : null,
                ]);
            }
            $transitions = array_map(function ($transition) use ($workflow, $states) {

                return $workflow->transitions()->updateOrCreate(
                    [
                        'name' => $transition['name'],
                    ],
                    [
                        'from_state_id' => $states->where(fn ($state
                        ) => $state->name == getStandardStr($transition['from_state'])
                        )->first()?->id,
                        'to_state_id' => $states->where(
                            fn ($state) => $state->name == getStandardStr($transition['to_state'])
                        )->first()?->id,
                        'label' => $transition['label'],
                    ]);
            }, $schema['transitions']);

            // Delete transitions that are not in the schema & not used
            $workflow->transitions()->notUsed()->whereNotIn('name', array_column($transitions, 'name'))
                ->get()->each(fn ($transition) => $transition->delete());

            // Delete states that are not in the schema & not used
            $workflow->states()->notUsed()->whereNotIn('name', array_column($states->toArray(), 'name'))->delete();

            \DB::commit();

        } catch (\Exception $exception) {
            error($exception->getMessage());
            \DB::rollBack();
        }
    }

    private function showDiffData(array $schemaWorkflow, array $dbWorkflow)
    {
        $schema = $this->getFlattenData($schemaWorkflow);
        $db = $this->getFlattenData($dbWorkflow);

        $result = [];
        $all_keys = array_unique(array_merge(array_keys($schema), array_keys($db)));

        foreach ($all_keys as $key) {
            $schema_value = $schema[$key] ?? '';
            $db_value = $db[$key] ?? '';

            if ($schema_value !== $db_value) {

                $level = match (true) {
                    $schema_value && $db_value => 'update',
                    $schema_value && ! $db_value => 'create',
                    default => 'delete',
                };

                $result[$level][] = [
                    'path' => $key,
                    'schema' => $schema_value,
                    'db' => $db_value,
                ];
            }
        }

        return $result;
    }

    private function getFlattenData(array $dbWorkflow, string $parentKey = '')
    {
        $result = [];

        foreach ($dbWorkflow as $key => $value) {

            if (is_numeric($key) && isset($value['name'])) {
                $key = $value['name'];
            }

            $newKey = $parentKey ? "$parentKey/$key" : $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->getFlattenData($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }

        }

        return $result;
    }

    private function WorkflowDataToSchema()
    {
        $workflow = Workflow::findByKey($this->schemaName)->first(['id', 'name', 'model_type', 'label']);

        $states = $workflow?->states()->with('parent:id,name')->get(['name', 'label', 'parent_id'])->map(function ($item) {
            return [
                'name' => $item->name,
                'label' => $item->getTranslations('label'),
                'parent_id' => $item->parent?->name,
            ];
        });
        $transitions = $workflow?->transitions()->with('fromState:name,id', 'toState:name,id')
            ->get(['name', 'label', 'from_state_id', 'to_state_id'])
            ->map(function ($transition) {
                return [
                    'name' => $transition->name,
                    'label' => $transition->getTranslations('label'),
                    'from_state' => $transition->fromState?->name,
                    'to_state' => $transition->toState?->name,
                ];
            });

        $workflow = $workflow?->toArray();

        unset($workflow['id']);

        return [
            'workflow' => $workflow,
            'states' => $states?->toArray(),
            'transitions' => $transitions?->toArray(),
        ];
    }
}
