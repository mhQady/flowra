<?php

namespace Flowra\Console;

use Flowra\Flows\MainFlow\MainWorkflowStates;
use Flowra\Flows\MainFlow\MainWorkflow;
use Flowra\Models\Context;
use Illuminate\Console\Command;

class ListWorkflow extends Command
{
    protected $signature = 'flowra:list';


    public function handle()
    {
        $m = Context::whereCurrentStatus(MainWorkflow::class,MainWorkflowStates::PREPARE_APPLICATION_INFO)
        // $m = Context::whereCurrentStatus(MainWorkflow::class,MainWorkflowStates::READY_FOR_OPERATIONS_MANAGER_REVISION)
            ->get();

        // $m = Context::firstOrCreate()->mainWorkflow->initiating->apply();
        // $m = Context::firstOrCreate()->mainWorkflow::stateGroups();

        $s = $m;

        dd(
            $s,
//            '--------------------',
//            $m->mainWorkflow
//            FillAppDataWorkflow::transitions()
        );
    }
}
