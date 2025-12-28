<?php

namespace Flowra\Console;

use Flowra\Support\WorkflowCache;
use Illuminate\Console\Command;

class ClearWorkflowCache extends Command
{
    protected $signature = 'flowra:cache:clear {workflow?*}';
    protected $description = 'Clear cached Flowra workflow transitions/states';

    public function handle(): int
    {
        $targets = $this->argument('workflow');

        if (!empty($targets)) {
            foreach ($targets as $workflow) {
                WorkflowCache::forget($workflow);
                $this->line("Cleared cache for {$workflow}");
            }

            return self::SUCCESS;
        }

        // No workflows passed → clear ALL cached workflows
        $cleared = WorkflowCache::forgetAll();

        if (empty($cleared)) {
            $this->info('No cached workflows found.');
            return self::SUCCESS;
        }

        foreach ($cleared as $workflow) {
            $this->line("Cleared cache for {$workflow}");
        }

        return self::SUCCESS;
    }
}
