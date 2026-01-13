# Bulk transition planning (discussion)

Goal: design a bulk transition helper that applies the same workflow transition to many models while reusing the
existing `CanApplyTransitions` behaviour.

## Proposed implementation (Option 2: separate BulkTransitionService)

We will extract bulk transition logic into a dedicated service instead of mixing it into workflows. Workflows remain
responsible for *single‑model* transitions, while the service handles orchestration, batching, and error strategy.

### Core idea

- Introduce `BulkTransitionService` under `src/Services/`.
- The service orchestrates applying **one transition** across **many models** using an existing workflow class.
- Each item still uses the normal workflow lifecycle (guards, validation, save, actions).

### Public API

```php
BulkTransitionService::for(OrderWorkflow::class)
    ->apply(
        iterable $targets,
        string|Transition $transition,
        ?BulkTransitionOptions $options = null
    ): BulkTransitionResult;

// ergonomic fluent alternative
BulkTransitionService::for(OrderWorkflow::class)
    ->transition(string|Transition $transition)
    ->targets(iterable $targets)
    ->appliedBy(int|string|null $userId)
    ->comments(array $comments)
    ->continueOnError(bool $value = true)
    ->chunk(int $size)
    ->run(): BulkTransitionResult;
```

### Options configuration

Each option is controlled via its own dedicated method on the service in the fluent API. For the `apply(...)` API we can
still accept an optional `BulkTransitionOptions` value object for reuse, but **no options are passed as a
single `->options(...)` call**.

```php
use App\Services\BulkTransitionOptions;

$options = BulkTransitionOptions::make()
    ->appliedBy(auth()->id())
    ->comments(['bulk shipment'])
    ->continueOnError(true)
    ->chunk(500);

// fluent per-option configuration
$result = BulkTransitionService::for(OrderWorkflow::class)
    ->transition('ship')
    ->targets($orders)
    ->appliedBy(auth()->id())
    ->comments(['bulk shipment'])
    ->continueOnError(true)
    ->chunk(500)
    ->run();
```

(This object is optional and mainly useful when you want to reuse the same options across multiple bulk runs.)

Suggested `BulkTransitionOptions` surface:

- `appliedBy(int|string|null $userId): self`
- `comments(array $comments): self`
- `addComment(string $comment): self`
- `continueOnError(bool $value = true): self`
- `chunk(int $size): self`

Optional (consider later if needed):

- `onSuccess(callable $fn): self` (per-item hook)
- `onFailure(callable $fn): self` (per-item hook)

Defaults:

- `continueOnError = false` (fail-fast)
- `chunk = null` (no chunking)

### Service usage example

```php
use App\Models\Order;
use App\Services\BulkTransitionService;
use App\Workflows\OrderWorkflow;
use App\Services\BulkTransitionOptions;

$orders = Order::query()->whereKey([1, 2, 3])->get();

$options = BulkTransitionOptions::make()
    ->appliedBy(auth()->id())
    ->comments(['bulk shipment'])
    ->continueOnError(true);

$result = BulkTransitionService::for(OrderWorkflow::class)
    ->apply($orders, 'ship', $options);

// fluent per-option configuration
$result = BulkTransitionService::for(OrderWorkflow::class)
    ->transition('ship')
    ->targets($orders)
    ->appliedBy(auth()->id())
    ->comments(['bulk shipment'])
    ->continueOnError(true)
    ->run();

$result->successes;
$result->failures;
```

### Optional workflow proxy (ergonomic only)

```php
abstract class BaseWorkflow
{
    public function applyMany(iterable $targets, string|Transition $transition, ?BulkTransitionOptions $options = null)
    {
        return app(BulkTransitionService::class)
            ->for(static::class)
            ->apply($targets, $transition, $options);
    }
}
```

This keeps bulk logic out of workflows while preserving DX.

### Builder / Collection macros

Macros should delegate directly to the service:

```php
Builder::macro('applyTransition', function ($transition, ?BulkTransitionOptions $options = null) {
    $modelClass = $this->getModel()::class;
    $workflowClass = $modelClass::workflowClass();

    return BulkTransitionService::for($workflowClass)
        ->apply($this->get(), $transition, $options);
});
```

### Execution model (per item)

1. Resolve transition once (by key if needed).
2. For each target:
    - Instantiate workflow with the model.
    - Clone transition and attach workflow.
    - Apply `applied_by` / comments.
    - Run guards + structural validation.
    - Persist inside the existing workflow transaction.
    - Hydrate state and execute actions.
3. Record success or failure.

### Why this design

- Clear separation of responsibilities
- No static state or hidden coupling
- Easy to test and extend (chunking, async, metrics)
- Safe per‑item transaction boundaries

```
