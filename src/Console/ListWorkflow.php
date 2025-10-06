<?php

namespace Flowra\Console;

use Flowra\Flows\MainFlow\MainWorkflowStates;
use Flowra\Models\Context;
use Illuminate\Console\Command;

class ListWorkflow extends Command
{
    protected $signature = 'flowra:list';


    public function handle()
    {
        $m = Context::
        withWhereCurrentStatus(MainWorkflowStates::WAITING_ENGOFFICE_CREDENCE)
            ->get();


        $s = $m;

        dd(
            $s,
//            '--------------------',
//            $m->mainWorkflow
//            FillAppDataWorkflow::transitions()
        );
    }
}
