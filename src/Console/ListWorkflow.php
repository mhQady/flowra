<?php

namespace Flowra\Console;

use Flowra\Models\Context;
use Illuminate\Console\Command;

class ListWorkflow extends Command
{
    protected $signature = 'flowra:list';


    public function handle()
    {
        $m = Context::firstOrCreate();


        // $s = $m->mainWorkflow->filling_app_data->apply();
        $s = $m->mainWorkflow->sendingForAuditing->apply();
        // $s = $m->mainWorkflow->fillAppDataWorkflow->filling_certificates_data->apply();

        dd(
            $s,
            //            $m->mainWorkflow->fillAppDataWorkflow,
            //            $m->mainWorkflow::subflows()
            //            '--------------------',
            //            $m->mainWorkflow
            //            FillAppDataWorkflow::transitions()
        );
    }
}
