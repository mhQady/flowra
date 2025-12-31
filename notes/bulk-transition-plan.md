# Bulk transition planning (discussion)

Goal: design a bulk transition helper that applies the same workflow transition to many models while reusing the
existing `CanApplyTransitions` behaviour.

Proposed implementation (pending your go-ahead):

- Add `src/Traits/ ` that mixes into workflows alongside `CanApplyTransitions`.
- Public API: `applyMany(iterable $targets, string|Transition $transition, array $options = []): BulkTransitionResult`.
    - `$targets` can be models or workflow instances; for models we will instantiate the workflow per item using the
      existing constructor.
    - `$transition` can be a transition key or a `Transition` instance; we will clone it per target to avoid the
      internal `workflow` lock on the DTO.
    - `$options` seeds: `applied_by`, `comments`, `continue_on_error` (default false), `chunk` size for large batches.
- Result object holds `successes`, `failures` (with exceptions and model ids), and any emitted `Status` records for
  insight.

Execution flow per item:

1) Resolve the transition (key -> `transitions()` lookup) and clone it; inject per-call `appliedBy`/comments if
   provided.
2) Spin up a workflow instance for the target model; hydrate states so `validateTransitionStructure` works.
3) Run guards, structural validation, and save inside the existing `__save` transaction; then `hydrateStates` and
   `__executeActions`.
4) Error strategy: default fail-fast (bubble the first exception); if `continue_on_error`, catch, record, and keep
   going.
5) Optionally chunk large iterables to limit memory/transaction scopes.

Edge considerations:

- Transition DTO keeps a workflow reference; cloning is required to attach one per item.
- We should not share a single DB transaction for all items to avoid rolling back successes; per-item transactions via
  `__save` keep behaviour consistent.
- Need to ensure `model` exists and workflow is registered for each item; reuse current checks.
- Actions/guards may be heavy; consider eager-loading relationships before invoking the bulk helper to avoid N+1 issues.

Testing outline:

- Happy path: multiple models move from the same `from` to `to` state and statuses/registries are written.
- Guard failure: verify fail-fast aborts the batch and `continue_on_error` reports partial success.
- Invalid transition key: ensure it is caught before processing (or reported in failures when continue mode is on).

Code demo (concept):

```php
use App\Models\Order;
use App\Workflows\OrderWorkflow;

$orders = Order::query()->whereKey([1, 2, 3])->get();

$workflow = new OrderWorkflow($orders->first()); // seed with any model; per-item instances will be created

$result = $workflow->applyMany(
    $orders,
    'ship', // or provide Transition::make('ship', OrderStates::PAID, OrderStates::SHIPPED)
    [
        'applied_by' => auth()->id(),
        'comments' => ['bulk shipment'],
        'continue_on_error' => true, // set false to fail-fast
    ]
);

// inspect results
$result->successes; // array of model ids (or models) that moved successfully
foreach ($result->failures as $failure) {
    logger()->warning('Bulk transition failure', [
        'model_id' => $failure->id,
        'error' => $failure->exception->getMessage(),
    ]);
}
```

Extending usage to Builder/Collection (idea):

- Add an Eloquent macro (e.g., in a service provider) so you can call
  `Order::query()->whereKey([1,2,3])->applyTransition('ship', $options)`. The macro would:
    - Resolve the workflow for the model (e.g., `new OrderWorkflow(new Order)`).
    - Call `$workflow->applyMany($builder->get(), 'ship', $options);`
- Similarly, a `Collection` macro could forward to the same helper.

Macro demo (concept):

```php
// AppServiceProvider::boot
Builder::macro('applyTransition', function (string|Transition $transition, array $options = []) {
    /** @var \Illuminate\Database\Eloquent\Builder $this */
    $modelClass = $this->getModel()::class;
    $workflowClass = $modelClass::workflowClass(); // assume your models expose this

    $workflow = new $workflowClass(new $modelClass); // seed workflow

    return $workflow->applyMany($this->get(), $transition, $options);
});

Collection::macro('applyTransition', function (string|Transition $transition, array $options = []) {
    /** @var \Illuminate\Support\Collection $this */
    $first = $this->first();
    $modelClass = $first::class;
    $workflowClass = $modelClass::workflowClass();
    $workflow = new $workflowClass($first);

    return $workflow->applyMany($this->all(), $transition, $options);
});

// usage
Order::query()
    ->whereKey([1, 2, 3])
    ->applyTransition('ship', ['continue_on_error' => true]);
```

Inner applyMany sketch (performance + same rules as apply):

```php
public function applyMany(iterable $targets, string|Transition $transition, array $options = []): BulkTransitionResult
{
    $continue = $options['continue_on_error'] ?? false;
    $appliedBy = $options['applied_by'] ?? null;
    $comments = $options['comments'] ?? [];

    // resolve transition once (by key) to avoid repeated lookups
    $resolved = is_string($transition)
        ? static::transitions()[$transition] ?? null
        : $transition;

    if (!$resolved) {
        throw new ApplyTransitionException("Transition [$transition] not found.");
    }

    $success = [];
    $failures = [];

    foreach ($targets as $target) {
        $workflow = $target instanceof BaseWorkflow ? $target : new static($target);

        // clone DTO so we can attach workflow without mutating the shared one
        $t = clone $resolved;
        $t->workflow($workflow);
        if ($appliedBy !== null) {
            $t->appliedBy($appliedBy);
        }
        if ($comments) {
            $t->comment(...$comments);
        }

        try {
            $workflow->evaluateGuards($t);
            $workflow->validateTransitionStructure($t);
            $status = $workflow->__save($t); // per-item transaction inside
            $workflow->hydrateStates($status);
            $workflow->__executeActions($t);

            $success[] = $target;
        } catch (\Throwable $e) {
            $failures[] = ['target' => $target, 'exception' => $e];
            if (!$continue) {
                throw $e;
            }
        }
    }

    return new BulkTransitionResult($success, $failures);
}
```

- For very large sets, wrap the loop with `collect($targets)->chunk($options['chunk'] ?? 500)` to cap memory while
  keeping per-item transactions.
- Reuses the same guard validation, transition structure checks, DB transaction save, state hydration, and actions as
  `apply()`, but isolates each item so a failure does not roll back successes unless `continue_on_error` is false.

Minimal-query idea (2 writes: statuses + registry):

- Feasible if we precompute all applicable transitions in memory, then bulk write:
    - Read all current statuses in one query:
      `Status::whereIn('owner_id', ids)->where('workflow', static::class)->get();`
    - For each target, run guard + validation using the hydrated current state (no writes yet).
    - Prepare two arrays: one for `Status::upsert([...], ['owner_type','owner_id','workflow'])` and one for
      `Registry::insert([...])`.
- Caveats:
    - No per-item transaction isolation; a late failure would require manual rollback logic for already inserted
      registry rows.
    - Actions still run per item and may perform their own queries.
    - Upsert keys must match your unique index; ensure `owner_type/owner_id/workflow` is unique.
    - If you need fail-fast, do it before the bulk writes; otherwise you need compensating deletes/updates on error.

Minimal-query code demo (concept):

```php
public function applyManyMinimalQuery(iterable $targets, string|Transition $transition, array $options = []): BulkTransitionResult
{
    $continue = $options['continue_on_error'] ?? false;
    $appliedBy = $options['applied_by'] ?? null;
    $comments = $options['comments'] ?? [];

    $models = collect($targets)->all();
    $ids = array_map(fn($m) => $m->getKey(), $models);
    $ownerType = $models[0]->getMorphClass();

    $resolved = is_string($transition) ? static::transitions()[$transition] ?? null : $transition;
    if (!$resolved) {
        throw new ApplyTransitionException("Transition [$transition] not found.");
    }

    $currentStatuses = Status::query()
        ->where('owner_type', $ownerType)
        ->whereIn('owner_id', $ids)
        ->where('workflow', static::class)
        ->get()
        ->keyBy('owner_id');

    $statusRows = [];
    $registryRows = [];
    $success = [];
    $failures = [];

    foreach ($models as $model) {
        $workflow = new static($model);

        $t = clone $resolved;
        $t->workflow($workflow);
        $appliedBy !== null && $t->appliedBy($appliedBy);
        $comments && $t->comment(...$comments);

        // hydrate current state from pre-fetched status
        $workflow->hydrateStates($currentStatuses[$model->getKey()] ?? null);

        try {
            $workflow->evaluateGuards($t);
            $workflow->validateTransitionStructure($t);

            $statusRows[] = [
                'owner_type' => $ownerType,
                'owner_id' => $model->getKey(),
                'workflow' => static::class,
                'transition' => $t->key,
                'from' => $t->from->value,
                'to' => $t->to->value,
                'comment' => $t->comments,
                'applied_by' => $t->appliedBy,
                'type' => $t->type,
            ];

            $registryRows[] = $statusRows[array_key_last($statusRows)];
            $success[] = $model;
        } catch (\Throwable $e) {
            $failures[] = ['target' => $model, 'exception' => $e];
            if (!$continue) {
                throw $e;
            }
        }
    }

    // two write queries (statuses upsert + registry insert)
    if ($statusRows) {
        Status::query()->upsert(
            $statusRows,
            ['owner_type', 'owner_id', 'workflow'],
            ['transition', 'from', 'to', 'comment', 'applied_by', 'type']
        );
    }
    if ($registryRows) {
        Registry::query()->insert($registryRows);
    }

    // optional: run actions after writes (may generate extra queries)
    foreach ($success as $idx => $model) {
        $workflow = new static($model);
        $t = clone $resolved;
        $t->workflow($workflow);
        $appliedBy !== null && $t->appliedBy($appliedBy);
        $comments && $t->comment(...$comments);
        $workflow->__executeActions($t);
    }

    return new BulkTransitionResult($success, $failures);
}
```

Questions for you:

- Should the batch stop on first failure or try all targets by default? → Default: stop on first failure, option to
  continue.
- Will input always be models of the same workflow class, or should we support mixed models/workflows? → Always same
  model + workflow class.
- Do you need per-item callbacks/hooks to inspect results, or is a summary object enough?
