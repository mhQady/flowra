<?php

namespace Flowra\Traits\Workflow;

use Flowra\Concretes\BaseWorkflow;
use Flowra\DTOs\Transition;
use Flowra\Models\{Registry, Status};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use UnitEnum;

/**
 * Infinite nested workflows using only statuses_registry.
 * Correlates parent↔child with a UUID run_id written to registry.comment.
 *
 * Parent writes:
 *   START   transition="__inner_start:{run_id}", workflow=Parent::class, to=parent_state
 *   COMPLETE transition="__inner_complete:{run_id}", workflow=Parent::class, to=child_final_state
 *
 * Child writes:
 *   ATTACH  transition="__inner_attach:{run_id}", workflow=Child::class, to=initial_state
 */
trait HasSubflow
{
    public int $depth = 0;
    public static array $subflows = [];

    public function initializeHasSubflow(): void
    {
        foreach (static::subflowsSchema() as $sub) {
            $innerWorkflow = new $sub->innerWorkflow($this->model);

            $innerWorkflow->boundState = $sub->boundState;
            $innerWorkflow->parentWorkflow = static::class;
            $innerWorkflow->startTransition = $sub->startTransition;
            $innerWorkflow->exits = $sub->exits;

            static::$subflows[$this::class][$sub->boundState] = $innerWorkflow;
        }
    }

    protected static function subflowsSchema(): array
    {
        return [];
    }

    // ---- HOOKS (call these from the transition pipeline) ----

    protected function __beforeTransitionApply(Transition $transition): void
    {
        if ($this->hasActiveInnerRunForParent(static::class)) {
            throw new RuntimeException('Workflow is blocked by a running inner workflow.');
        }
    }

    protected function hasActiveInnerRunForParent(string $parentWorkflow): bool
    {
        $start = $this->latestParentStartRow($parentWorkflow);

        if (!$start) return false;

        $runId = Arr::get($start->comment, '__inner.run_id');
        return !$this->parentHasCompleteForRun($parentWorkflow, $runId, $start->id);
    }

    protected function __afterTransitionApplied(?UnitEnum $from, UnitEnum $to): void
    {
        $this->maybeSpawnInnerWorkflowOnState($to);
        $this->maybeCompleteParentFromChild($to);
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
    protected function maybeSpawnInnerWorkflowOnState(UnitEnum $entered): void
    {
        $cfg = $this->subflows;
        if (!$cfg) return;
        $stateKey = is_string($entered) ? $entered : $entered->value;
        $binding = $cfg[$stateKey] ?? null;
        if (!$binding) return;

        $childClass = $binding['child'] ?? null;
        if (!$childClass || !class_exists($childClass)) return;

        // Avoid duplicates if a run for this state is already open
        if ($this->hasActiveInnerRunForParentState(static::class, $stateKey)) return;

        DB::transaction(function () use ($binding, $stateKey, $childClass) {
            $runId = (string) Str::uuid();
            $parentDepth = $this->flowDepth();

            // START (parent)
            $this->writeParentStart(static::class, $stateKey, $childClass, $binding['map'] ?? [], $runId, $parentDepth);

            // Boot child and ATTACH
            if (($binding['auto_start'] ?? true) === true) {
                $child = new $childClass($this->model);
                $this->ensureWorkflowBootedWithAttach($child, $runId, static::class, $parentDepth + 1);
            }
        });
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
}
