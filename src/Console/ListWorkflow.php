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


//        dd($m->workflows);
        $s = $m->fillAppDataWorkflow;
        dd(
            $s,
//            FillAppDataWorkflow::transitions()
        );
    }
}
