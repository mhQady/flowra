<?php

namespace Flowra\Traits\Workflow;

use BackedEnum;
use Flowra\Concretes\BaseWorkflow;
use Flowra\DTOs\Transition;
use Flowra\Models\{Registry, Status};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnitEnum;

trait HasSubflow
{
    private static array $subflows = [];

    public array $parentFlowConfigurations = [
        'start_transition' => null,
        'exits' => [],
        'parent_current_status' => null,
        'parent' => null
    ];

    public static function bootHasSubflow(): void
    {
        static::initializeSubflows();
    }


    private static function initializeSubflows(): void
    {
        foreach (static::subflowsSchema() as $sub) {
            static::$subflows[static::class][$sub['key']] = $sub;
        }
    }

    protected static function subflowsSchema(): array
    {
        return [];
    }

    public static function subflows(): array
    {
        static::bootIfNotBooted();

        if (!isset(static::$subflows[static::class]))
            return [];

        return static::$subflows[static::class];
    }

    // ---- HOOKS (call these from the transition pipeline) ----

    protected function checkSubflowBeforeApplyTransition(Transition $t): void
    {
        if ($this->hasActiveInnerRunForParent($t)) {
            throw new RuntimeException('Workflow is blocked by a running inner workflow.');
        }

    }

    /**
     * @throws Throwable
     */
    protected function checkSubflowAfterApplyTransition(Transition $t, Status $status): void
    {
        $this->maybeApplyStartTransitionToSubflow($t, $status);
        $this->maybeApplyExitTransitionToParentflow($t, $status);
//        $this->maybeCompleteParentFromChild($to);
    }

    protected function hasActiveInnerRunForParent(Transition $t): bool
    {
        if (!$this->currentState instanceof UnitEnum) {
            return false;
        }

        $stateKey = $this->stateKey($this->currentState);
        $subflow = $this->resolveBoundSubflow($stateKey);

        if (!$subflow) {
            return false;
        }

        $childWorkflow = $this->{$subflow['key']} ?? null;
        if (!$childWorkflow instanceof BaseWorkflow) {
            return false;
        }

        $childStatus = $childWorkflow->status();
        if (!$childStatus) {
            return false;
        }

        $exits = $subflow['subflow']['exits'] ?? [];
        $childStateKey = $childStatus->to;

        if (array_key_exists($childStateKey, $exits)) {
            $allowedTransition = Str::camel($exits[$childStateKey]);
            return $allowedTransition !== Str::camel($t->key);
        }

        return true;
    }


//    public function flowDepth(): int
//    {
//        return (int) ($this->status()?->depth ?? 0);
//    }

    public function activeRunIdForThisWorkflow(): ?string
    {
        // Latest ATTACH for this workflow (if any)
        $attach = Registry::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', static::class)
            ->where('transition', 'like', '__inner_attach:%')
            ->latest('id')->first();

        if (!$attach) return null;

        $runId = Arr::get($attach->comment, '__inner.run_id');
        $parent = Arr::get($attach->comment, '__inner.parent');
        if (!$runId || !$parent) return null;

        // If parent already completed this run, it’s not active.
        $completed = Registry::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $parent)
            ->where('transition', '__inner_complete:'.$runId)
            ->exists();

        return $completed ? null : $runId;
    }

    // ---- Parent path ----

    /**
     * @throws Throwable
     */
    protected function maybeApplyStartTransitionToSubflow(Transition $t, Status $status): void
    {
        $subflow = $this->resolveBoundSubflow($status->to);

        if (!$subflow) {
            return;
        }

        $childWorkflow = $this->{$subflow['key']};

        $childWorkflow->parentFlowConfigurations['parent_current_status'] = $status;
        $childWorkflow->parentFlowConfigurations['parent'] = $this;

        // Avoid duplicates if a run for this state is already open
//        if ($this->hasActiveInnerRunForParentState(static::class, $stateKey)) return;

        if (!$startTransition = $childWorkflow->{$subflow['subflow']['start_transition']}) {
            throw new RuntimeException('SubFlow Start Transition is not defined');
        };

        $startTransition->apply();
    }

    /**
     * @throws Throwable
     */
    protected function maybeApplyExitTransitionToParentflow(Transition $t, Status $status): void
    {

        foreach ($this->parentFlowConfigurations['exits'] as $key => $value) {
            if ($status->to == $key) {
                $this->parentFlowConfigurations['parent']->{$value}?->apply();
                break;
            }
        }

    }

    private function resolveBoundSubflow(UnitEnum|string $state): ?array
    {
        $stateKey = $this->stateKey($state);

        foreach ($this::subflows() as $key => $wf) {
            if ($wf['bound_state'] === $stateKey) {
                return ['key' => $key, 'subflow' => $wf];
            }
        }

        return null;
    }

    protected function hasActiveInnerRunForParentState(string $parentWorkflow, string $parentState): bool
    {
        $start = $this->latestParentStartRow($parentWorkflow, $parentState);
        if (!$start) return false;
        $runId = Arr::get($start->comment, '__inner.run_id');
        return !$this->parentHasCompleteForRun($parentWorkflow, $runId, $start->id);
    }

    // ---- Child path ----
    protected function maybeCompleteParentFromChild(UnitEnum $childTo): void
    {
        $attach = $this->latestChildAttachRow(static::class);
        if (!$attach) return;

        $runId = Arr::get($attach->comment, '__inner.run_id');
        $parentClass = Arr::get($attach->comment, '__inner.parent');
        $childDepth = (int) ($attach->depth ?? 1);
        if (!$runId || !$parentClass || !class_exists($parentClass)) return;

        $start = $this->findParentStartByRun($parentClass, $runId);
        if (!$start) return;

        $map = Arr::get($start->comment, '__inner.map', []);
        $finalKey = is_string($childTo) ? $childTo : $childTo->value;
        $parentTransitionKey = $map[$finalKey] ?? null;
        if (!$parentTransitionKey) return;

        DB::transaction(function () use ($parentClass, $runId, $finalKey, $parentTransitionKey, $childDepth) {
            // COMPLETE (parent)
            $this->writeParentComplete($parentClass, static::class, $finalKey, $runId, $childDepth - 1);

            // Advance parent
            $parent = new $parentClass($this->model);
            $t = $parent->{$parentTransitionKey} ?? null;
            if ($t instanceof Transition) $t->apply();
        });
    }

    // ---- Registry writes (now include depth + run_id) ----
    protected function writeParentStart(
        string $parentWorkflow,
        string $parentState,
        string $childWorkflow,
        array $map,
        string $runId,
        int $depth
    ): void {
        Registry::query()->create([
            'owner_type' => $this->model->getMorphClass(),
            'owner_id' => $this->model->getKey(),
            'workflow' => $parentWorkflow,
            'transition' => '__inner_start:'.$runId,
            'from' => null,
            'to' => $parentState,
            'comment' => [
                '__inner' => [
                    'event' => 'start', 'run_id' => $runId, 'parent' => $parentWorkflow,
                    'parent_state' => $parentState, 'child' => $childWorkflow, 'map' => $map,
                ]
            ],
            'depth' => $depth,
            'run_id' => $runId,
        ]);
    }

    protected function writeChildAttach(
        string $childWorkflow,
        string $parentWorkflow,
        string $initialState,
        string $runId,
        int $depth
    ): void {
        Registry::query()->create([
            'owner_type' => $this->model->getMorphClass(),
            'owner_id' => $this->model->getKey(),
            'workflow' => $childWorkflow,
            'transition' => '__inner_attach:'.$runId,
            'from' => null,
            'to' => $initialState,
            'comment' => [
                '__inner' => [
                    'event' => 'attach', 'run_id' => $runId, 'parent' => $parentWorkflow, 'child' => $childWorkflow,
                ]
            ],
            'depth' => $depth,
            'run_id' => $runId,
        ]);
    }

    protected function writeParentComplete(
        string $parentWorkflow,
        string $childWorkflow,
        string $childFinal,
        string $runId,
        int $depth
    ): void {
        Registry::query()->create([
            'owner_type' => $this->model->getMorphClass(),
            'owner_id' => $this->model->getKey(),
            'workflow' => $parentWorkflow,
            'transition' => '__inner_complete:'.$runId,
            'from' => null,
            'to' => $childFinal,
            'comment' => [
                '__inner' => [
                    'event' => 'complete', 'run_id' => $runId, 'parent' => $parentWorkflow, 'child' => $childWorkflow,
                    'final' => $childFinal,
                ]
            ],
            'depth' => $depth,
            'run_id' => $runId,
        ]);
    }

    // ---- Reads ----
    protected function latestParentStartRow(string $parentWorkflow, ?string $parentState = null)
    {
        $q = Registry::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $parentWorkflow)
            ->where('transition', 'like', '__inner_start:%');

        if ($parentState !== null) $q->where('to', $parentState);
        return $q->latest('id')->first();
    }

    protected function parentHasCompleteForRun(string $parentWorkflow, string $runId, int $afterId): bool
    {
        return Registry::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $parentWorkflow)
            ->where('transition', '__inner_complete:'.$runId)
            ->where('id', '>', $afterId)
            ->exists();
    }

    protected function latestChildAttachRow(string $childWorkflow)
    {
        return Registry::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $childWorkflow)
            ->where('transition', 'like', '__inner_attach:%')
            ->latest('id')->first();
    }

    protected function findParentStartByRun(string $parentWorkflow, string $runId)
    {
        return Registry::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $parentWorkflow)
            ->where('transition', '__inner_start:'.$runId)
            ->latest('id')->first();
    }

    // ---- Boot helpers (respect depth) ----
    protected function ensureWorkflowBootedWithAttach(
        BaseWorkflow $wf,
        string $runId,
        string $parentWorkflow,
        int $childDepth
    ): void {
        $initial = $this->ensureWorkflowBootedAndGetInitialWithDepth($wf, $childDepth);
        if ($initial) $this->writeChildAttach($wf::class, $parentWorkflow, $initial->value, $runId, $childDepth);
    }

    protected function ensureWorkflowBootedAndGetInitialWithDepth(BaseWorkflow $wf, int $depth): ?UnitEnum
    {
        $status = $wf->status();
        if ($status) {
            if ((int) $status->depth !== $depth) $status->update(['depth' => $depth]);
            return $wf->currentState;
        }

        $cases = $wf->statesClass::cases();
        $initial = $cases[0] ?? null;
        if (!$initial instanceof UnitEnum) return null;

        Status::query()->create([
            'owner_type' => $wf->model->getMorphClass(),
            'owner_id' => $wf->model->getKey(),
            'workflow' => $wf::class,
            'transition' => '__boot',
            'from' => null,
            'to' => $initial->value,
            'comment' => ['__system' => 'boot'],
            'depth' => $depth,
            'run_id' => null,
        ]);

        Registry::query()->create([
            'owner_type' => $wf->model->getMorphClass(),
            'owner_id' => $wf->model->getKey(),
            'workflow' => $wf::class,
            'transition' => '__boot',
            'from' => null,
            'to' => $initial->value,
            'comment' => ['__system' => 'boot'],
            'depth' => $depth,
            'run_id' => null,
        ]);

        return $initial;
    }

    protected function resolveSubflowProperty(string $name): ?BaseWorkflow
    {
        if (in_array($name, array_keys(static::$subflows[static::class]))) {
            $subFlow = static::$subflows[static::class][$name];

            $workflow = $subFlow['workflow_instance_cache'];

            if (!$workflow) {
                $workflow = new $subFlow['workflow_class']($this->model);

                $this->model->registeredSubflows[] = $subFlow['workflow_class'];
                # cache subflow configurations in workflow
                $workflow->parentFlowConfigurations = [
                    'start_transition' => $subFlow['start_transition'],
                    'exits' => $subFlow['exits'],
                    'parent_current_status' => $this->currentStatus,
                    'parent' => $this
                ];

                $subFlow['workflow_instance_cache'] = $workflow;
            }

            return $workflow;
        }

        return null;
    }

    private function stateKey(UnitEnum|string $state): string
    {
        if ($state instanceof BackedEnum) {
            return (string) $state->value;
        }

        if ($state instanceof UnitEnum) {
            return $state->name;
        }

        return (string) $state;
    }
}
