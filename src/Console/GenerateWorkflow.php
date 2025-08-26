<?php

namespace Flowra\Console;

// use App\Models\Workflow;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\form;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;

class GenerateWorkflow extends Command
{
    protected $signature = 'flowra:generate';

    protected $description = 'Generate a new workflow file with its states and transitions for selected model';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->line('<comment> ⚙️ ----------------------------------------------------------------⤵⤵ </comment>');
               $workflow = $this->collectWorkflowData();
               $this->line('<comment> ⚙️ ----------------------------------------------------------------|| </comment>');
        //        $states = $this->collectStates();
        //        $this->line('----------------------------------------------------------------|| ');
        //        $states = $this->assignParentStates($states);
        //        $this->line('----------------------------------------------------------------|| ');
        //        $transitions = $this->collectTransitions($states);
        //        $this->line('----------------------------------------------------------------|| ');
        //
        //        if (
        //            !confirm(
        //                label: "Are you sure you want to create workflow with name {$workflow['name']} and type {$workflow['model_type']}?",
        //                yes: 'I Do 😁',
        //                no: 'I Don\'t 😟',
        //                hint: 'The terms must be accepted to continue.'
        //            )
        //        ) {
        //            return;
        //        }
        //
        //        spin(
        //            message: 'Creating Workflow. Please wait... 🤔',
        //            callback: fn () => $this->generateWorkflowFile($workflow, $states, $transitions)
        //        );
        //
        //        $this->line('----------------------------------------------------------------⤴⤴ ');
    }

    private function collectWorkflowData(): Collection
    {
        $name = text(
            label: 'Workflow Name:',
            validate: [
                'name' => [
                    'required',
                    'min:3',
                    'max:255',
                    function ($attribute, $value, $fail) {

                        $value = getStandardStr($value);

                        if (Workflow::findByKey($value)->exists() || File::exists(config('workflow.schemas_dir')."/{$value}.schema.php")) {
                            $fail('Workflow with same name already exists.');
                        }
                    },
                ],
            ]
        );

        $label = [];
        foreach (config('app.locales') as $locale) {
            $label[$locale] = text(
                "Workflow Label ({$locale}):",
                required: "Workflow label ({$locale}) is required",
                validate: ['name' => 'min:3|max:255']

            );
        }

        $data = collect();

        $data->put('name', getStandardStr($name));

        $data->put(
            'model_type',
            select(
                'Select a model:',
                getModelsUsingTrait(config('workflow.has_workflow')),
                required: 'Model is required'
            )
        );

        $data->put('label', $label);

        return $data;
    }

    private function collectStates(): Collection
    {
        $states = textarea(
            label: 'Enter states separated by comma:',
            default: 'created,moved',
            required: 'States are required',
            validate: [
                'states' => function ($attribute, $value, $fail) {

                    $states = collect(explode(',', $value))->map(fn ($state) => getStandardStr($state));

                    if ($states->duplicates()->first()) {
                        $fail('Duplicate state name found.');
                    }

                    if ($states->count() < 2) {
                        $fail('You must enter at least two states.');
                    }

                    if ($states->contains(fn ($stateName) => $stateName === Workflow::INIT_STATE)) {
                        $fail(Workflow::INIT_STATE.' is reserved state name.');
                    }
                },
            ],
            hint: "Enter state names separated by comma, EX: Created,Deleted.\n  You must enter at least one state.",
            rows: 2
        );

        return collect(explode(',', $states))
            // Remove empty states
            ->filter(fn ($state) => ! empty($state))
            // Make state name standard & collect labels
            ->map(function ($state) {

                $label = [];

                foreach (config('app.locales') as $locale) {
                    $label[$locale] = text(
                        "Label for ({$state}) ({$locale}):",
                        required: "State label ({$locale}) is required",
                        validate: ['name' => 'min:3|max:255']
                    );
                }

                return ['name' => getStandardStr($state), 'label' => $label, 'parent_id' => null];
            })
            // Add init state
            ->prepend(['name' => Workflow::INIT_STATE, 'label' => ['ar' => 'البدء', 'en' => 'Initiated'], 'parent_id' => null]);
    }

    private function assignParentStates(Collection $states): Collection
    {
        if (! confirm(label: 'Do you want to assign parent states?', yes: 'I Do 😁', no: 'I Don\'t 😟')) {
            return $states;
        }

        $parentState = select(
            '️ Select Parent State:',
            $states->map(fn ($state) => $state['name'])->filter(fn ($state) => $state != Workflow::INIT_STATE)->values(),
            //            validate: fn ($val) => match ($this->filteredStatesForTransitions(
            //                $states,
            //                $val,
            //                $transitions
            //            )->isEmpty()) {
            //                true    => "You can't select {$val} as from state because all possible transitions that uses it as state are already created.",
            //                default => null
            //            },
            required: 'Parent state is required'
        );

        $childrenStates = multiselect('select children states:', $states->map(fn ($state) => $state['name'])->filter(fn ($name) => ! in_array($name, [$parentState, Workflow::INIT_STATE]))->values(), required: 'Children states are required');

        return $states->map(function ($state) use ($parentState, $childrenStates) {
            if (in_array($state['name'], $childrenStates)) {
                $state['parent_id'] = $parentState;
            }

            return $state;
        });
    }

    private function collectTransitions(Collection $states): Collection
    {
        $statesCount = $states->count();

        $states = $states->map(fn ($state) => $state['name'])->values();

        $transitions = collect();

        $transitions->push($this->collectStartTransition($states));

        while (true) {
            $transitions->push($this->drawTransitionForm(
                $states->filter(fn ($state) => $state != Workflow::INIT_STATE)->values(),
                $transitions
            ));

            // Break when all possible transitions are created
            if (
                $transitions->count() === calcPossibleTransitions($statesCount) || ! confirm(
                    label: 'Do you wanna add another transition?',
                    default: true,
                    yes: 'Yes',
                    no: 'No'
                )
            ) {
                break;
            }
        }

        return $transitions;
    }

    private function collectStartTransition(Collection $states)
    {
        $firstState = $states->first();

        $startTransition = $this->drawTransitionForm(
            $states->filter(fn ($state) => $state != $firstState),
            collect(),
            $firstState
        );

        info('Start transition has been set ✅.');

        return $startTransition;
    }

    private function drawTransitionForm(Collection $states, Collection $transitions, ?string $firstState = null)
    {
        $label = '('.$transitions->count() + 1 .') '.($firstState ? 'Start Transition Name 🚀:' : 'Transition Name:');

        $data = form()
            // Collect transition name
            ->text(
                $label,
                validate: [
                    'name' => [
                        'required',
                        'min:3',
                        'max:255',
                        function ($attribute, $value, $fail) use ($transitions) {

                            if ($transitions->pluck('name')->contains(getStandardStr($value))) {
                                $fail('Transition with same name already exists.');
                            }
                        },
                    ],
                ],
                name: 'name'
            )
            // Collect transition label
            ->add(function ($trans) {
                $label = [];
                foreach (config('app.locales') as $locale) {
                    $label[$locale] = text(
                        "Transition ({$trans['name']}) Label ({$locale}):",
                        required: "Workflow label ({$locale}) is required",
                        validate: ['name' => 'min:3|max:255']
                    );
                }

                return $label;
            }, name: 'label')
            // Collect from state
            ->add(function ($trans) use ($states, $transitions, $firstState) {
                // Set from state automatically for start transition
                if ($firstState) {
                    return $firstState;
                }

                return select(
                    "️ Select From state for {$trans['name']}:",
                    $states,
                    validate: fn ($val) => match ($this->filteredStatesForTransitions(
                        $states,
                        $val,
                        $transitions
                    )->isEmpty()) {
                        true => "You can't select {$val} as from state because all possible transitions that uses it as state are already created.",
                        default => null
                    },
                    required: 'From state is required'
                );
            }, name: 'from_state')
            // Collect to state
            ->add(
                fn ($trans) => select(
                    "Select To state for {$trans['name']} 👨🏽‍🦯:",
                    options: $this->filteredStatesForTransitions($states, $trans['from_state'], $transitions),
                    required: 'To state is required'
                ),
                name: 'to_state'
            )
            ->submit();

        $data['name'] = getStandardStr($data['name']);

        return $data;
    }

    // Make sure that the fromState is not the same as the toState & not created duplicated transitions
    private function filteredStatesForTransitions(Collection $states, string $fromState, Collection $transitions)
    {
        $usedStates = $transitions
            ->filter(fn ($item) => $item['from_state'] === $fromState)
            ->pluck('to_state');

        $values = $states->filter(function ($item) {
            return $item !== Workflow::INIT_STATE /* || $item !== $fromState */ ;
        })->values();

        return $values->diff($usedStates)->values();
    }

    private function generateWorkflowFile(Collection $workflow, Collection $states, Collection $transitions): bool
    {
        $stubPath = config('workflow.stubs_dir').'/workflow.schema.stub';

        if (! $this->files->exists($stubPath)) {
            error('Stub file does not exist!');

            return false;
        }

        $content = str_replace(
            ['$WORKFLOW$', '$STATES_ARRAY$', '$TRANSITIONS_ARRAY$'],
            [
                $this->convertArrayToString($workflow->toArray(), true),
                $this->convertArrayToString($states->toArray()),
                $this->convertArrayToString($transitions->toArray()),
            ],
            $this->files->get($stubPath)
        );

        $directory = config('workflow.schemas_dir');

        if (! $this->files->exists($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        $filePath = "$directory/{$workflow['name']}.schema.php";

        if ($this->files->put($filePath, $content)) {
            info("File '$filePath' created successfully 🎉.");

            return true;
        } else {
            error("Error creating file at $filePath 🙁.");

            return false;
        }
    }

    private function convertArrayToString(array $array, bool $withKeys = false, int $indentLevel = 1): string
    {
        $indent = str_repeat('   ', $indentLevel);
        $baseIndent = str_repeat('   ', $indentLevel - 1);

        $data = array_map(
            function ($value, $key) use ($withKeys, $indent, $indentLevel) {

                $value = match (true) {
                    is_array($value) => $this->convertArrayToString($value, true, $indentLevel + 1),
                    is_string($value) => "'$value'",
                    is_null($value) => 'null',
                    is_bool($value) => $value ? 'true' : 'false',
                    default => $value,
                };

                if (! $withKeys) {
                    return "{$indent}{$value}";
                }

                $key = is_string($key) ? "'$key'" : $key;

                return "{$indent}{$key} => $value";
            },
            $array,
            array_keys($array)
        );

        return "[\n".implode(', ', $data)."\n$baseIndent]";
    }
}
