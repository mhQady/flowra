<?php

namespace Flowra\Console;

use Flowra\Flows\MainWorkflow\MainWorkflow;
use Flowra\Models\Context;
use Flowra\Support\WorkflowCache;
use Illuminate\Console\Command;

class ListWorkflow extends Command
{
    protected $signature = 'flowra:list';

    public function handle()
    {
        // $m = Context::whereCurrentStatus(MainWorkflow::class,MainWorkflowStates::PREPARE_APPLICATION_INFO)
        // // $m = Context::whereCurrentStatus(MainWorkflow::class,MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION)
        //     ->get();

        WorkflowCache::forget(MainWorkflow::class);
        /** @var MainWorkflow $workflow */
        $workflow = Context::firstOrCreate()->mainWorkflow->currentState;
//        $m = $workflow->initiating->apply();
        // $m = Context::create()->mainWorkflow->assigning_to_auditor->apply();
        // $m = Context::firstOrCreate()->mainWorkflow->filling_certificates_data->apply();
        // $m = Context::firstOrCreate()->mainWorkflow::stateGroups();
//        $m = WorkflowDefinition::query()->workflow(MainWorkflow::class)->first();

//        $s = $m;

        dd(
            $workflow,
//            '--------------------',
//            $m->mainWorkflow
//            FillAppDataWorkflow::transitions()
        );
    }
}
