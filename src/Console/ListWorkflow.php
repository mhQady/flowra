<?php

namespace Flowra\Console;

use Flowra\Flows\MainFlow\MainWorkflowStates;
use Flowra\Models\Context;
use Illuminate\Console\Command;

class ListWorkflow extends Command
{
    protected $signature = 'flowra:list';
//                            {workflow? : The name of the workflow}
//                            {--s|states : Load states}
//                            {--t|transitions : Load transitions}
//                            {--a|all : Load both states and transitions}';

    protected $description = 'List all workflows with its states and transitions Or information about a specific workflow';

    public function handle()
    {

        $flow = Context::query()->firstOrCreate(['id' => 1]);

//        $t = $flow->mainFlow->cancellingBySurveyorWhileEditing->apply(['test for comment']);
//        $t = $flow->mainFlow->jump(MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION);

        dd($flow->currentStatus());
        dd(Context::withWhereHas('mainFlowStatus',
            fn($query) => $query->where('to', MainWorkflowStates::CANCELLED_BY_SURVEYOR))->get());
//        dd($flow->mainFlow->currentStatus());
        dd($flow->mainFlow->jump(MainWorkflowStates::SENT_BACK_TO_SURVEYOR_FOR_REVISION));
//        spin(message: 'Loading Workflows. Please wait... 🤔', callback: function () {
// 
//            $workflows = Workflow::when($this->argument('workflow'),
//                fn($query) => $query->where('name', $this->argument('workflow')))
//                ->get(['id', 'name', 'model_type', 'label']);
//
//            $this->line('----------------------------------------------------------------⤵⤵ ');
//
//            $showWorkflows = [];
//            $workflows->each(function ($item) use (&$showWorkflows) {
//                $showWorkflows[] = $item->getAttributes();
//            });
//
//            info("Workflows ({$workflows->count()}) loaded.👇🏼");
//            table(headers: ['ID', 'Name', 'Model Type', 'label'], rows: $showWorkflows);
//
//            if ($this->option('states') || $this->option('all')) {
//                $this->showStates();
//            }
//
//            if ($this->option('transitions') || $this->option('all')) {
//                $this->showTransitions();
//            }
//
//            $this->line('----------------------------------------------------------------⤴⤴ ');
//
//            $this->newLine(2);
//        });
    }

//    public function showStates(): void
//    {
//        $states = \DB::table('states')
//            ->join('workflows', 'states.workflow_id', '=', 'workflows.id')
//            ->when($this->argument('workflow'),
//                fn($query) => $query->where('workflows.name', $this->argument('workflow')))
//            ->orderBy('workflows.name')
//            ->select('states.id as id', 'workflows.name as workflow_name', 'states.name as name',
//                'states.label as state_label')
//            ->get()
//            ->map(fn($item) => (array) $item);
//
//        $this->line('----------------------------------------------------------------|| ');
//        info("States ({$states->count()}) loaded.👇🏼");
//        table(headers: ['ID', 'Workflow', 'Name', 'Label'], rows: $states->toArray());
//    }
//
//    public function showTransitions(): void
//    {
//        $transitions = \DB::table('transitions')
//            ->join('workflows', 'transitions.workflow_id', '=', 'workflows.id')
//            ->when($this->argument('workflow'),
//                fn($query) => $query->where('workflows.name', $this->argument('workflow')))
//            ->orderBy('workflows.name')
//            ->join('states', 'transitions.from_state_id', '=', 'states.id')
//            ->join('states as to_states', 'transitions.to_state_id', '=', 'to_states.id')
//            ->select('transitions.id as id', 'workflows.name as workflow_name',
//                'transitions.name as name', 'transitions.label as transition_label',
//                'transitions.is_start as is_start', 'states.name as from_state', 'to_states.name as to_state')
//            ->get()
//            ->map(fn($item) => (array) $item);
//
//        $this->line('----------------------------------------------------------------|| ');
//        info("Transitions ({$transitions->count()}) loaded.👇🏼");
//        table(headers: ['ID', 'Workflow', 'Name', 'Label', 'Is Start', 'From State', 'To State'],
//            rows: $transitions->toArray());
//    }
}
