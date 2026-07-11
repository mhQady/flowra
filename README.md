# Flowra

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mhqady/flowra.svg)](https://packagist.org/packages/mhqady/flowra)
[![Total Downloads](https://img.shields.io/packagist/dt/mhqady/flowra.svg)](https://packagist.org/packages/mhqady/flowra)
[![License](https://img.shields.io/packagist/l/mhqady/flowra.svg)](https://packagist.org/packages/mhqady/flowra)

Flowra is a database-driven workflow (state machine) engine for Laravel. You describe a business
process as a set of **typed states** (a PHP backed enum), **transitions** between them, **guards**
that authorize a transition, and **actions** that run after it succeeds. The current state of every
model is persisted in the database together with a full, append-only transition history.

```php
$order->orderWorkflow->process
    ->appliedBy(auth()->id())
    ->comment('Payment confirmed')
    ->apply();
```

## Features

- Attach one or more workflows to any Eloquent model via a trait.
- Current state stored in a `statuses` table; every transition appended to a `statuses_registry` audit table.
- Transitions defined as fluent `Transition::make()` DTOs with guards and actions.
- Guards and actions as closures, class names (container-resolved), or instances.
- State groups for organizing related states and querying them as one unit.
- State jumps (`jumpTo`) to force a state outside the defined transitions (admin resets, corrections).
- Bulk transitions across many models with per-item error collection.
- Auto-registered Eloquent relations and query scopes per workflow.
- Artisan generators for workflows, guards, and actions.
- Export workflow definitions to Mermaid or PlantUML diagrams.
- Optional persistent caching of parsed workflow definitions.

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.3+ |
| Laravel | 12.x or 13.x |

States enums **must be string-backed enums** (`enum Foo: string`). Pure (non-backed) enums are not
supported.

## Installation

```bash
composer require mhqady/flowra
```

The service provider is auto-discovered. Publish the migrations and run them:

```bash
php artisan vendor:publish --tag=flowra-migrations
php artisan migrate
```

Optionally publish the config, generator stubs, and translations:

```bash
php artisan vendor:publish --tag=flowra-config        # config/flowra.php
php artisan vendor:publish --tag=flowra-stubs         # stubs/flowra/*
php artisan vendor:publish --tag=flowra-translations  # lang/vendor/flowra/*
```

`php artisan about` will report the installed Flowra version once the package is set up.

## Quick Start

### 1. Generate a workflow

```bash
php artisan flowra:make-workflow Order
```

This creates a dedicated folder (under `app/Workflows` by default):

- `app/Workflows/OrderWorkflow/OrderWorkflow.php`
- `app/Workflows/OrderWorkflow/OrderWorkflowStates.php`

The `Workflow` suffix is added automatically if missing. The states enum **must** be named
`{WorkflowClass}States` and live in the same namespace — Flowra resolves it by this convention.

### 2. Define states

```php
namespace App\Workflows\OrderWorkflow;

enum OrderWorkflowStates: string
{
    case PENDING    = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED  = 'completed';
    case CANCELLED  = 'cancelled';
}
```

### 3. Define transitions

```php
namespace App\Workflows\OrderWorkflow;

use App\Workflows\Actions\NotifyCustomerAction;
use App\Workflows\Guards\CheckStockGuard;
use Flowra\Concretes\BaseWorkflow;
use Flowra\DTOs\Transition;

class OrderWorkflow extends BaseWorkflow
{
    public static function transitionsSchema(): array
    {
        return [
            Transition::make('process', OrderWorkflowStates::PENDING, OrderWorkflowStates::PROCESSING)
                ->guard(CheckStockGuard::class)
                ->action(NotifyCustomerAction::class),

            Transition::make('complete', OrderWorkflowStates::PROCESSING, OrderWorkflowStates::COMPLETED),

            Transition::make('cancel', OrderWorkflowStates::PENDING, OrderWorkflowStates::CANCELLED),
        ];
    }
}
```

### 4. Prepare your model

```php
use Flowra\Concretes\HasWorkflow;
use Flowra\Contracts\HasWorkflowContract;
use Illuminate\Database\Eloquent\Model;

class Order extends Model implements HasWorkflowContract
{
    use HasWorkflow;

    protected static array $workflows = [
        \App\Workflows\OrderWorkflow\OrderWorkflow::class,
    ];
}
```

> **Note:** the `HasWorkflow` trait lives in `Flowra\Concretes`, not `Flowra\Traits`.

### 5. Apply transitions

Each registered workflow is exposed as a virtual attribute named after the workflow class in
camelCase (`OrderWorkflow` → `$order->orderWorkflow`). Transitions are accessed by their key:

```php
$order = Order::find(1);

// Apply a transition
$order->orderWorkflow->process->apply();

// With audit metadata
$order->orderWorkflow->process
    ->appliedBy(auth()->id())
    ->comment('Starting fulfilment')
    ->apply();

// Inspect current state
$order->orderWorkflow->currentState;   // OrderWorkflowStates::PROCESSING
$order->orderWorkflow->status();       // Flowra\Models\Status (current row)
$order->orderWorkflow->registry();     // Collection of Flowra\Models\Registry (full history)
```

Transition keys are normalized, so `$order->orderWorkflow->processOrder` resolves the key
`process_order`. Accessing an **undefined** transition key returns `null` — check your key spelling
if you see "Call to a member function apply() on null".

A model with no status yet is treated as being in the transition's `from` state, so the first
applicable transition starts the workflow.

## Guards

Guards decide whether a transition may proceed. They run before the transition is persisted; if a
guard fails, `Flowra\Exceptions\GuardDeniedException` is thrown and nothing is written.

```bash
php artisan flowra:make-guard CheckStock
```

```php
namespace App\Workflows\Guards;

use Flowra\Contracts\GuardContract;
use Flowra\DTOs\Transition;

class CheckStockGuard implements GuardContract
{
    public function allows(Transition $transition): bool
    {
        $order = $transition->appliedOnModel();

        return $order->items->every(fn ($item) => $item->inStock());
    }
}
```

Guards can be attached as class names, instances, or closures — and stacked:

```php
Transition::make('process', From::PENDING, To::PROCESSING)
    ->guard(CheckStockGuard::class)
    ->guard(fn (Transition $t) => $t->appliedOnModel()->total > 0);
```

> **Important:** a guard denies the transition only by returning `false` (strictly). Always return a
> real boolean from guards.

## Actions

Actions run **after** the transition has been committed to the database — side effects never fire
for a transition that failed to persist.

```bash
php artisan flowra:make-action NotifyCustomer
```

```php
namespace App\Workflows\Actions;

use Flowra\Contracts\ActionContract;
use Flowra\DTOs\Transition;

class NotifyCustomerAction implements ActionContract
{
    public function execute(Transition $t): void
    {
        $order = $t->appliedOnModel();

        $order->customer->notify(new OrderProcessing($order));
    }
}
```

Like guards, actions accept class names, instances, or closures, and multiple actions run in the
order they were attached.

## State Groups

Groups let you treat several states as one logical unit. Define them with a static `groups()`
method on the states enum:

```php
use Flowra\DTOs\StateGroup;

enum OrderWorkflowStates: string
{
    case PENDING    = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED  = 'completed';

    public static function groups(): array
    {
        return [
            StateGroup::make('active')->children(self::PENDING, self::PROCESSING),
        ];
    }
}
```

Group helpers are available on every workflow class:

```php
OrderWorkflow::stateGroups();                                   // all groups
OrderWorkflow::stateGroupChildren('active');                    // children metadata
OrderWorkflow::stateParentGroup(OrderWorkflowStates::PENDING);  // parent group metadata
OrderWorkflow::isGroupedState('active');                        // true
OrderWorkflow::hasParentGroup(OrderWorkflowStates::PENDING);    // true
```

Querying by a **group name** expands to all of its child states (see [Querying](#querying-models-by-state)).

## State Jumps

`jumpTo()` forces a state change without a defined transition — useful for admin resets or data
corrections. Jumps **skip guards and actions** and are recorded in the registry with a distinct
type, so the audit trail shows they were not normal transitions.

```php
$order->orderWorkflow->jumpTo(OrderWorkflowStates::PENDING, 'admin_reset', auth()->id());
```

A jump requires the workflow to already have a current state; jumping an unstarted workflow throws
`Flowra\Exceptions\ApplyJumpException`.

## Bulk Transitions

Apply one transition to many models. Each item goes through the full lifecycle (guards, validation,
persistence, actions) individually.

```php
use App\Workflows\OrderWorkflow\OrderWorkflow;

$orders = Order::where('created_at', '<', now()->subDay())->get();

$result = OrderWorkflow::applyMany(
    $orders,
    'process',
    appliedBy: auth()->id(),
    continueOnError: true,   // collect failures instead of stopping at the first one
    chunk: 500,              // process lazily in chunks
);

$result->successfulCount();  // int
$result->failedCount();      // int
$result->hasFailures();      // bool
$result->successes;          // [['target' => $model, 'status' => Status], ...]
$result->failures;           // [['target' => $model, 'exception' => Throwable], ...]
```

The same engine is available as a fluent service:

```php
use Flowra\Services\BulkTransitionService;

$result = BulkTransitionService::for(OrderWorkflow::class)
    ->targets($orders)
    ->transition('process')
    ->appliedBy(auth()->id())
    ->comments(['nightly batch'])
    ->continueOnError()
    ->chunk(500)
    ->run();
```

A convenience macro also exists on query builders and collections:

```php
Order::where('priority', 'high')->applyOrderWorkflowTransition('process');
$orders->applyOrderWorkflowTransition('process');
```

> **Important:** with `continueOnError: false` (the default), the first failure throws and stops the
> batch — but items that already succeeded stay committed. There is no batch-wide rollback. Use
> `continueOnError: true` and inspect the result when you need to know exactly what happened.

## Querying Models by State

### Relations

For each registered workflow, Flowra registers Eloquent relations named after the workflow alias:

```php
$order->orderWorkflowStatus;    // morphOne  -> current Flowra\Models\Status
$order->orderWorkflowRegistry;  // morphMany -> Flowra\Models\Registry history

// Generic, across all workflows:
$order->statuses();
$order->registry();

// Standard relations — eager load them:
Order::with('orderWorkflowStatus')->get();
```

### Scopes and macros

Generic scopes accept the workflow alias or FQCN plus one state or an array of states (enum cases
or raw values):

```php
Order::whereCurrentStatus('orderWorkflow', OrderWorkflowStates::PENDING)->get();
Order::whereNotCurrentStatus(OrderWorkflow::class, ['cancelled', 'completed'])->get();
```

Per-workflow macros are also registered:

```php
Order::whereOrderWorkflowCurrentStatus(OrderWorkflowStates::PENDING)->get();
Order::orWhereOrderWorkflowCurrentStatus(...);
Order::whereNotOrderWorkflowCurrentStatus(...);
Order::orWhereNotOrderWorkflowCurrentStatus(...);

// Filters rows AND eager-loads the matching status relation:
Order::withWhereOrderWorkflowCurrentStatus(OrderWorkflowStates::PENDING)->get();
```

Passing a **group name** expands to the group's child states:

```php
// Matches orders whose current state is 'pending' OR 'processing'
Order::whereOrderWorkflowCurrentStatus('active')->get();
```

## Workflow Introspection

```php
OrderWorkflow::states();       // ['pending' => Case, 'processing' => Case, ...]
OrderWorkflow::transitions();  // ['process' => Transition, 'complete' => Transition, ...]

$wf = $order->orderWorkflow;
$wf->currentState;             // enum case or null
$wf->currentStatus;            // Status model or null
$wf->statesEnum();             // OrderWorkflowStates::class
```

## Artisan Commands

| Command | Description |
|---|---|
| `flowra:make-workflow {name} [--namespace=App\\Workflows] [--force]` | Scaffold a workflow class + states enum in a dedicated folder |
| `flowra:make-guard {name} [--path=] [--namespace=] [--force]` | Generate a guard class |
| `flowra:make-action {name} [--path=] [--namespace=] [--force]` | Generate an action class |
| `flowra:export-workflow {workflow} [--format=mermaid\|plantuml] [--output=]` | Export a workflow as a Mermaid or PlantUML state diagram |

`flowra:export-workflow` accepts a short name (`Order`, resolved against the configured namespace)
or an FQCN. Without `--output` it prints the diagram and saves it under
`storage/app/flowra/workflows/`.

Generator stubs can be customized by publishing them (`--tag=flowra-stubs`) — published stubs in
`stubs/flowra/` take precedence over the package defaults.

> The source tree also contains diagram-import and cache warm/clear commands that are **not yet
> registered** with Artisan; they are not part of the public API of the current release.

## Configuration

`config/flowra.php`:

| Key | Default | Description |
|---|---|---|
| `workflows_path` | `app/Workflows` | Directory where generated workflows are written (env: `FLOWRA_WORKFLOWS_PATH`) |
| `workflows_namespace` | `App\Workflows` | Base namespace used to resolve short workflow names |
| `cache_workflows` | `true` | Persist parsed workflow definitions in a cache store (env: `FLOWRA_CACHE_WORKFLOWS`) |
| `cache_driver` | `database` | Cache store used for workflow definitions (env: `FLOWRA_CACHE_DRIVER`) |
| `tables.statuses` | `statuses` | Table holding each model's current state per workflow |
| `tables.registry` | `statuses_registry` | Append-only transition history table |

## Database Schema

Two tables, both keyed by UUID and polymorphic on `owner`:

- **`statuses`** — one row per `(owner, workflow)` pair (enforced by a unique index) holding the
  current state: `workflow` (FQCN), `transition`, `from`, `to`, `comment` (JSON), `applied_by`, `type`.
- **`statuses_registry`** — same columns, one row appended per applied transition or jump.

`applied_by` is an unsigned integer column — pass integer user IDs.

Status and registry writes happen inside a database transaction: the current-state update and the
history append always succeed or fail together.

## Definition Caching

When `cache_workflows` is enabled (the default), parsed states, transitions, and groups are stored
**indefinitely** in the configured cache store under `flowra:workflow:{class}:{key}` keys, in
addition to per-process memoization.

Things to know:

- **After changing a `transitionsSchema()` or states enum, flush the cached keys** — there is no
  registered cache-clear command in the current release. Clear your cache store (e.g.
  `php artisan cache:clear` for the relevant store) or delete keys matching `flowra:workflow:*`.
- Transitions that use **closure** guards/actions cannot be serialized; those definitions silently
  skip the persistent cache and are rebuilt per process. Class-based guards/actions cache fine.
- The default store is `database` — make sure the `cache` table migration exists, or point
  `FLOWRA_CACHE_DRIVER` at a store you actually run.
- During development, set `FLOWRA_CACHE_WORKFLOWS=false` to avoid stale definitions entirely.

## Best Practices

- **Eager load status relations** (`with('orderWorkflowStatus')`) when displaying state for lists of
  models. Accessing `$model->orderWorkflow` hydrates the workflow with a fresh status query per
  model, so prefer the relation for read-heavy listings.
- **Prefer class-based guards and actions** over closures: they are container-resolved (so they can
  have dependencies), testable in isolation, and compatible with definition caching.
- **Keep transition keys snake_case** (`'send_for_review'`) — magic property access normalizes
  camelCase to snake_case when looking up keys.
- **Reserve `jumpTo` for exceptional flows** (admin overrides, migrations). It bypasses guards and
  actions by design; regular business logic should go through defined transitions.
- **Use `continueOnError: true` for large batches** and act on `BulkTransitionResult::failures`
  rather than relying on exceptions to stop the run.

## Troubleshooting

**`RuntimeException: States enum not found`**
The states enum must be named exactly `{WorkflowClass}States` and live in the same namespace as the
workflow class (`OrderWorkflow` → `OrderWorkflowStates`).

**"Call to a member function apply() on null"**
The transition key you accessed isn't defined in `transitionsSchema()`. Keys are matched in
snake_case — check the spelling.

**`ApplyTransitionException: Applying transition (...) while current state is (...)`**
The model's current state doesn't match the transition's `from` state. Inspect
`$model->fooWorkflow->currentState` and define a transition from that state (or use `jumpTo` for a
forced correction).

**`ApplyTransitionException: Workflow (...) is not registered for model (...)`**
Add the workflow class to the model's `protected static array $workflows`.

**Changes to a workflow definition don't take effect**
Stale definition cache — see [Definition Caching](#definition-caching). Disable caching in dev or
flush `flowra:workflow:*` keys after deploys.

**`QueryException` mentioning the `cache` table**
The default `cache_driver` is `database`. Run Laravel's cache table migration or set
`FLOWRA_CACHE_DRIVER` to a configured store (`redis`, `file`, ...).

**Guard seems to be ignored**
Only a strict `false` return denies a transition. Make sure guards return a boolean — returning
`null`, `0`, or any object will let the transition proceed.

## Known Limitations

- Querying by a **specific state that belongs to a group** currently resolves to the group key and
  will not match rows; query by the group name or by ungrouped states. (Tracked in
  [AUDIT.md](AUDIT.md), fix planned.)
- The extra arguments (`appliedBy`, `comments`, ...) of the `apply{Workflow}Transition`
  builder/collection macro are not yet forwarded — use `Workflow::applyMany()` or
  `BulkTransitionService` when you need audit metadata on bulk runs.
- Diagram import (`flowra:import-workflow`) and cache warm/clear commands exist in the source but
  are not registered in the current release.

## Testing

```bash
composer test
```

## License

The MIT License (MIT).

## Issues & Contributions

Bug reports and pull requests are welcome on [GitHub](https://github.com/mhQady/flowra/issues).
