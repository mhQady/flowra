<?php

namespace Flowra\Console;

use Flowra\Concretes\BaseWorkflow;
use Flowra\Support\WorkflowCache;
use Illuminate\Console\Command;

class WarmWorkflowCache extends Command
{
    protected $signature = 'flowra:cache:warm {workflow?*}';
    protected $description = 'Warm workflow transitions/states cache for configured workflows';

    public function handle(): int
    {
        $targets = $this->argument('workflow');
        $workflows = !empty($targets) ? $targets : [];

        if (empty($workflows)) {
            $this->info('No workflows specified or configured to warm.');
            return self::SUCCESS;
        }

        foreach ($workflows as $workflow) {
            $workflow = config('flowra.workflows_namespace')."\\$workflow\\$workflow";

            if (!class_exists($workflow) || !is_subclass_of($workflow, BaseWorkflow::class)) {
                $this->warn("Skipping {$workflow}: not found or not a BaseWorkflow.");
                continue;
            }

            try {
                // hydrate static caches
                $workflow::states();
                $workflow::transitions();
//                $workflow::stateGroups();
                $this->line("Warmed cache for {$workflow}");
            } catch (\Throwable $e) {
                WorkflowCache::forget($workflow);
                $this->error("Failed warming {$workflow}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
