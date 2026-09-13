# Flowra

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mhqady/flowra.svg)](https://packagist.org/packages/mhqady/flowra)
[![Total Downloads](https://img.shields.io/packagist/dt/mhqady/flowra.svg)](https://packagist.org/packages/mhqady/flowra)
[![License](https://img.shields.io/packagist/l/mhqady/flowra.svg)](https://packagist.org/packages/mhqady/flowra)

Flowra is a database-driven workflow (state machine) engine for Laravel. You describe a business
process as a set of **typed states** (a PHP backed enum), **transitions** between them, **guards**
that authorize a transition, and **actions** that run after it succeeds. The current state of every
model is persisted in the database together with a full, append-only transition history — which you
can read back raw, or through **registry views** shaped for each audience.

```php
$order->orderWorkflow->process
    ->appliedBy(auth()->id())
    ->comment('Payment confirmed')
    ->apply();

$order->orderWorkflow->registryView('customer')->for($user)->get();
```

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Defining Workflows](#defining-workflows) — states, phases, guards, actions
- [Applying Transitions](#applying-transitions) — inspecting, state jumps, bulk transitions
- [Querying Models by State](#querying-models-by-state)
- [Registry Views](#registry-views) — phases, conditions, attribution, masking, labels, JSON
- [Artisan Commands](#artisan-commands)
- [Configuration](#configuration)
- [Database Schema](#database-schema)
- [Definition Caching](#definition-caching)
- [Best Practices](#best-practices)
- [Troubleshooting](#troubleshooting)
- [Known Limitations](#known-limitations)
- [Testing](#testing)

## Features

- Attach one or more workflows to any Eloquent model via a trait.
- Current state stored in a `statuses` table; every transition appended to a `statuses_registry` audit table.
- Transitions defined as fluent `Transition::make()` DTOs with guards and actions.
- Guards and actions as closures, class names (container-resolved), or instances.
- Phases for organizing related states into logical steps and querying them as one unit.
- State jumps (`jumpTo`) to force a state outside the defined transitions (admin resets, corrections).
- Bulk transitions across many models with per-item error collection.
- Auto-registered Eloquent relations and query scopes per workflow.
- **Registry views** — read-only, per-audience projections of the transition history:
  - collapse a run of transitions into one logical step (a *phase*);
  - hide rows from an audience with SQL scopes or PHP filters;
  - attribute entries to a team or service account, or **mask** who acted;
  - translated labels and a JSON-ready payload, with pagination.
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

That is a working workflow. The sections below cover each piece in depth.

## Defining Workflows

### Phases

Phases let you treat several **states** as one logical step. Define them with a static
`phases()` method on the states enum:

```php
use Flowra\DTOs\Phase;

enum OrderWorkflowStates: string
{
    case PENDING    = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED  = 'completed';

    public static function phases(): array
    {
        return [
            Phase::make('active')->children(self::PENDING, self::PROCESSING),
        ];
    }
}
```

A phase's key is either a synthetic name like `'active'` that no transition ever lands on, or a
real state case that contains sub-states (`Phase::make(self::PENDING)->children(...)`).
`->label()` optionally names the phase for display — see [Labels and translations](#labels-and-translations).

Phase helpers are available on every workflow class:

```php
OrderWorkflow::phases();                                        // all phases, keyed by phase key
OrderWorkflow::phase('active');                                 // one phase's metadata, by its key
OrderWorkflow::phaseChildren('active');                         // children metadata
OrderWorkflow::stateParentPhase(OrderWorkflowStates::PENDING);  // parent phase metadata
OrderWorkflow::isPhase('active');                               // true
OrderWorkflow::hasParentPhase(OrderWorkflowStates::PENDING);    // true
```

One declaration serves two purposes:

- **Querying** — passing a phase key to a scope matches every state inside it
  (see [Querying by phase](#querying-by-phase)).
- **History** — a collapsed registry view shows a phase as one step in place of the individual
  rows that landed inside it (see [Phases in registry views](#phases-in-registry-views)). Whether
  a phase actually collapses is decided per view.

> **Keep phases one level deep.** A state should belong to at most one phase, and phases do not
> nest — nesting would make run detection recursive and the spanning `from`/`to` ambiguous.
> This is not validated: if a state appears in two phases, the later declaration wins.

#### Migrating from state groups

Phases used to be called *state groups*. The old names still work, but they are deprecated and
will be removed in a future breaking release:

| Deprecated | Use instead |
|---|---|
| `Flowra\DTOs\StateGroup` | `Flowra\DTOs\Phase` |
| `groups()` on the states enum | `phases()` — `groups()` is only read when the enum has no `phases()` |
| `stateGroups()` | `phases()` |
| `stateGroupFor()` | `phase()` |
| `stateGroupChildren()` | `phaseChildren()` |
| `stateParentGroup()` | `stateParentPhase()` |
| `isGroupedState()` | `isPhase()` |
| `hasParentGroup()` | `hasParentPhase()` |

Phases are cached under new keys, so upgrading needs no cache flush; `WorkflowCache::forget()`
also removes the keys the old names were cached under.

### Guards

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

### Actions

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

## Applying Transitions

The basics are covered in [Quick Start](#5-apply-transitions). This section covers inspection,
forced changes, and bulk operations.

### Workflow Introspection

```php
OrderWorkflow::states();       // ['pending' => Case, 'processing' => Case, ...]
OrderWorkflow::transitions();  // ['process' => Transition, 'complete' => Transition, ...]

$wf = $order->orderWorkflow;
$wf->currentState;             // enum case or null
$wf->currentStatus;            // Status model or null
$wf->statesEnum();             // OrderWorkflowStates::class
$wf->currentPhase();           // ['key' => 'active', 'label' => null], or null outside any phase
```

### State Jumps

`jumpTo()` forces a state change without a defined transition — useful for admin resets or data
corrections. Jumps **skip guards and actions** and are recorded in the registry with a distinct
type, so the audit trail shows they were not normal transitions.

```php
$order->orderWorkflow->jumpTo(OrderWorkflowStates::PENDING, 'admin_reset', auth()->id());
```

A jump requires the workflow to already have a current state; jumping an unstarted workflow throws
`Flowra\Exceptions\ApplyJumpException`.

### Bulk Transitions

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

### Querying by phase

Passing a **phase key** expands to the phase's child states (plus the phase key itself, which
matters when the key is a real state). Passing a **member state** matches only that state — it
never widens to the whole phase:

```php
// Matches orders whose current state is 'pending' OR 'processing'
Order::whereOrderWorkflowCurrentStatus('active')->get();

// Matches only 'pending'
Order::whereOrderWorkflowCurrentStatus(OrderWorkflowStates::PENDING)->get();
```

## Registry Views

### How it works

Every transition and jump appends a row to the `statuses_registry` table. You can read that
history in two ways:

| | Returns | Use it for |
|---|---|---|
| `registry()` | Raw `Registry` Eloquent rows — every row, unfiltered | Audits, internal tooling |
| `registryView()` | `RegistryEntry` DTOs, shaped by a named view | Timelines, API responses, per-audience UIs |

Both read the **same rows**. A registry view is a **read-only presentation layer**: nothing is
collapsed on write, no column is added, and `registry()` keeps returning everything.

A view decides four things for its audience:

1. **Shape** — one entry per row (`detailed`), or runs of rows folded into [phases](#phases-in-registry-views) (`collapsed`).
2. **Visibility** — which rows the audience may see ([conditions](#filtering-rows-per-audience)).
3. **Attribution** — which actor each entry renders as ([appliedBy](#who-an-entry-is-attributed-to)).
4. **Privacy** — whose identity must be hidden ([masks](#hiding-an-actor-with-masks)).

```php
$order->orderWorkflow->registry();                        // raw Registry rows — unchanged
$order->orderWorkflow->registryView();                    // default view — the same rows, projected
$order->orderWorkflow->registryView('applicant');         // a named view
$order->orderWorkflow->registryView()->collapsed();       // override the view's shape per read
$order->orderWorkflow->registryView()->for($user)->get(); // pass the viewer to the conditions
```

### Declaring views

Declare views on the workflow class with a static `registryViews()` method. Closures are allowed
here:

```php
use Flowra\DTOs\RegistryView;

class OrderWorkflow extends BaseWorkflow
{
    public static function transitionsSchema(): array { /* ... */ }

    public static function registryViews(): array
    {
        return [
            RegistryView::make('applicant')->collapsed()->when(HideInternalRows::class),
            RegistryView::make('reviewer')->detailed(),
            RegistryView::make('mine')->detailed()->when(
                fn ($row, $owner, $viewer) => $viewer !== null && $row->applied_by === $viewer->id
            ),
        ];
    }
}
```

Or register views for **every** workflow in `config/flowra.php`:

```php
'registry_views' => [
    'default_view' => 'default',
    'views' => [
        'auditor' => ['shape' => 'detailed', 'conditions' => []],
        'partner' => ['shape' => 'collapsed', 'applied_by' => 'sole'],
        'public' => [
            'shape' => 'collapsed',
            'mask' => ['phases' => ['under_review' => 'review_committee']],
        ],
    ],
],
```

A config view accepts these keys:

| Key | Value | Fluent equivalent |
|---|---|---|
| `shape` | `'detailed'` or `'collapsed'` | `->detailed()` / `->collapsed()` |
| `collapse` | phases to collapse; omit to collapse all | `->collapse(...)` |
| `expand` (alias `dont_collapse`) | phases to leave expanded | `->dontCollapse(...)` |
| `conditions` | class-strings of `RegistryScopeContract` / `RegistryFilterContract` | `->when(...)` |
| `applied_by` | an actor id, or `'first'` / `'last'` / `'sole'` | `->appliedBy(...)` |
| `mask.phases` | `[phase => stand-in actor]` | `->maskPhase(...)` |
| `mask.transitions` | `[transition => stand-in actor]` | `->maskTransition(...)` |

> **Config-declared views cannot hold closures.** `php artisan config:cache` refuses to serialize
> them. Closure conditions and closure appliers belong on the workflow class, which is never
> serialized.

**Which view wins.** Views resolve in increasing precedence:

1. the built-in `default` view — detailed and unconditional (the raw trail);
2. `config('flowra.registry_views.views')` — shared by every workflow, and may override `default`;
3. the workflow's `registryViews()` — wins on a name clash.

`registryView()` with no name uses `registry_views.default_view`. Asking for a view that is not
registered throws `Flowra\Exceptions\RegistryViewNotFoundException` — it never silently returns
everything.

### Reading a view

`registryView()` returns a lazy `Flowra\Support\RegistryBuilder`. Nothing hits the database until a
terminal call, so the shape, viewer and conditions all compose first. Every option is seeded from
the view and can be overridden per read:

| Builder method | Effect |
|---|---|
| `for($viewer)` | The audience, passed to every condition and closure |
| `detailed()` / `collapsed()` | Change the shape; `collapsed()` discards the view's phase selection |
| `collapse(...)` / `dontCollapse(...)` | Adjust which phases collapse (adds to the view's selection) |
| `when(...$conditions)` | Add conditions on top of the view's |
| `appliedBy($actor)` | Override attribution; `null` hands the decision back to the rows |
| `maskPhase(...)` / `maskTransition(...)` | Add masks on top of the view's |
| `oldest()` / `latest()` | Oldest first (default) or newest first |
| `get()` / `first()` / `count()` / `paginate()` | Terminal calls |
| `query()` | Escape hatch: the Eloquent builder with SQL scopes applied (PHP filters are **not**) |

The builder is `IteratorAggregate`, `Countable`, `Arrayable` and `JsonSerializable`, so it drops
straight into a Blade `@foreach` or an API response.

`get()` returns a `Flowra\Support\RegistryCollection` of `Flowra\DTOs\RegistryEntry` — not an
Eloquent collection, because a collapsed entry is not a row and has no id:

```php
$entries = $order->orderWorkflow->registryView('applicant')->for($user)->get();

foreach ($entries as $entry) {
    $entry->key;              // 'under_review' — the phase, or a leaf's landing state
    $entry->label();          // "Under Review"
    $entry->transition;       // the move that produced a leaf; null on a collapsed entry
    $entry->from;             // 'submitted' — raw on the DTO, paired with its label in toArray()
    $entry->fromLabel();      // "Submitted"
    $entry->to;               // 'approved'
    $entry->toLabel();        // "Approved"
    $entry->fromState();      // OrderWorkflowStates::SUBMITTED, or null
    $entry->toState();        // OrderWorkflowStates::APPROVED, or null
    $entry->phase;            // 'under_review' — the phase the landing state sits in
    $entry->phaseLabel();     // "Under Review", or null when the state is in no phase
    $entry->type;             // 1 transition | 2 reset (a jump) | 3 phase (a collapsed run)
    $entry->type();           // TransitionTypesEnum case
    $entry->typeLabel();      // "Transition" — the name for that kind
    $entry->comment;          // array of comment lines (merged across a run)
    $entry->startedAt;        // Carbon — a leaf's created_at, a phase's first row
    $entry->endedAt;          // Carbon — the same instant on a leaf, the run's end on a phase
    $entry->appliedBy;        // the actor it renders as — the only one in the payload
    $entry->recordedBy;       // the actor the row recorded — DTO only, null once masked
    $entry->participants();   // [1, 2, 3]
    $entry->registryId;       // the Registry row id on a leaf; null on a collapsed entry
    $entry->isCollapsed();    // true for a phase entry
    $entry->isAttributed();   // false — the actor came off the rows
    $entry->isRedacted();     // false — no mask claimed this entry
    $entry->isJump();         // false — true only for a jumpTo() row
    $entry->children;         // Collection<RegistryEntry> — the underlying rows, phases only
}

$entries->leaves();           // flatten collapsed entries back to the full trail
$entries->collapsed();        // only the phase entries
$entries->toArray();          // JSON-ready — see JSON payload
```

### Ordering and pagination

Rows are always read `created_at ASC, id ASC` (registry ids are ordered UUIDs, so rows written in
the same second keep insert order). `latest()` collapses chronologically first, then reverses the
entries — a run is never split by the direction.

```php
$order->orderWorkflow->registryView()->paginate(15);              // SQL LIMIT/OFFSET
$order->orderWorkflow->registryView()->collapsed()->paginate(15); // paginated entries
```

Same call either way, but two strategies run underneath:

- **Detailed, with no PHP-side condition** (no closure, no `RegistryFilterContract`) — paginated
  and counted in SQL, where one row is one entry.
- **Collapsed, or behind a PHP-side condition** — a SQL `LIMIT` would split a run across the page
  boundary and produce entries with the wrong `from`/`to`. These load the owner's filtered rows —
  bounded to one model and one workflow — build the entries, then paginate the **entries** with a
  `LengthAwarePaginator`.

For a pathological registry, bound the rows yourself through `->query()`.

### Phases in registry views

A [phase](#phases) is the logical step a collapsed view shows in place of the individual rows
behind it: a registry row belongs to a phase when the state it landed in (`to`) sits in that
phase. Transitions declare nothing, so moving a state into a phase also applies to rows written
before the phase existed.

The examples in this section use a review process:

```php
use Flowra\DTOs\Phase;
use Flowra\Enums\BaseEnum;

enum OrderWorkflowStates: string
{
    use BaseEnum;

    case DRAFT     = 'draft';
    case SUBMITTED = 'submitted';
    case IN_REVIEW = 'in_review';
    case DOCS_OK   = 'docs_ok';
    case SCORED    = 'scored';
    case APPROVED  = 'approved';
    case ARCHIVED  = 'archived';

    /** Declaration order is phase order. */
    public static function phases(): array
    {
        return [
            Phase::make('under_review')->children(self::IN_REVIEW, self::DOCS_OK, self::SCORED),
            Phase::make('closed')->children(self::APPROVED, self::ARCHIVED),
        ];
    }
}
```

Given these registry rows:

| # | transition | from → to | applied_by |
|---|---|---|---|
| 1 | `submit` | draft → submitted | 5 |
| 2 | `start_review` | submitted → in_review | 7 |
| 3 | `docs_checked` | in_review → docs_ok | 7 |
| 4 | `score` | docs_ok → scored | 8 |
| 5 | `approve` | scored → approved | 9 |

a **detailed** read returns five entries, one per row. A **collapsed** read returns three:

| Entry `key` | Kind | Rows | from → to | applied_by | participants |
|---|---|---|---|---|---|
| `submitted` | leaf (state in no phase) | 1 | draft → submitted | 5 | `[5]` |
| `under_review` | phase | 2–4 | submitted → scored | 8 | `[7, 8]` |
| `closed` | phase | 5 | scored → approved | 9 | `[9]` |

**An entry *is* the status it stands at**, not the move that got it there. A collapsed entry's
`key` is the phase it stands for; a leaf's `key` is the state it landed in. The move itself is
never lost — a leaf carries it on `transition`, and a collapsed entry has one per child:

```php
$entry->key;         // 'docs_ok'      — a leaf: the state it landed in
$entry->transition;  // 'docs_checked' — the move that put it there

$phase->key;         // 'under_review' — a collapsed entry: the phase it stands for
$phase->transition;  // null           — it stands for several moves, all on ->children
```

A jump is keyed the same way, by where it landed; the name it was forced under stays on
`transition`, and `isJump()` tells the two apart.

> **A phase ends when the model leaves its states.** A transition out of the phase
> (`approve`, scored → approved) belongs to the phase it moved *into*, not the one it left.
> Put your outcome states in a phase too and the timeline reads as a clean run of phases.

> **A self-loop inside a phase is absorbed.** A row that lands back on a state already inside
> the phase is part of it. To keep such a step visible, either move it to a state outside the
> phase or read through a detailed view.

Because a phase is resolved from the state, the model always knows which one it is in — no
registry read, and still correct after a `jumpTo()`:

```php
$order->orderWorkflow->currentPhase();   // ['key' => 'under_review', 'label' => null]
OrderWorkflow::phaseForState(OrderWorkflowStates::DOCS_OK);
OrderWorkflow::statePhases();            // state value => phase, in declaration order
```

> **Adding phases to a live app?** Phase definitions are cached forever when `cache_workflows`
> is on, so clear them once after declaring phases — otherwise the stale payload has no phases
> and collapsing quietly does nothing:
> `Flowra\Support\WorkflowCache::forget(OrderWorkflow::class)`.

### Choosing what collapses

Collapsing is a property of the **audience**, not of the phase. Each view decides, so two views
over the same rows can disagree:

```php
RegistryView::make('applicant')->collapsed();                  // every phase
RegistryView::make('summary')->collapse('under_review');       // only this one
RegistryView::make('ops')->dontCollapse('under_review');       // every phase except this one
RegistryView::make('audit')->detailed();                       // nothing
```

Name a phase by its key or by any state inside it — `collapse(OrderWorkflowStates::DOCS_OK)`
and `collapse('under_review')` mean the same thing. `collapse()` and `dontCollapse()` both imply
the collapsed shape; used together, the allow-list applies first and the deny-list subtracts
from it.

Per read, the same methods adjust the view's selection:

```php
$order->orderWorkflow->registryView('ops')->collapse('closed')->get();
$order->orderWorkflow->registryView('ops')->collapsed()->get();  // discards the view's selection
```

In config, where closures cannot go:

```php
'summary' => ['shape' => 'collapsed', 'collapse' => ['under_review']],
'ops'     => ['shape' => 'collapsed', 'expand' => ['under_review']],
```

### Collapsing rules

| | |
|---|---|
| **Consecutive only** | A run is *adjacent* rows sharing a phase key. A phase re-entered later in the timeline is a separate episode and gets its own entry — workflows loop, and a second review round is genuinely a second round. |
| **from / to** | First row's `from`, last row's `to`. |
| **Timestamps** | Both ends survive: `startedAt` (first row) and `endedAt` (last row). |
| **applied_by** | The actor who *left* the phase, unless a view or the system user names another — see [Who an entry is attributed to](#who-an-entry-is-attributed-to). `participants()` returns everyone involved, in order. |
| **Comments** | Merged across the run, in order. |
| **Type** | A collapsed entry has type `PHASE` (3); its children keep the types their rows recorded. |
| **Detail** | Never discarded — `children` holds the untouched leaf entries, and `->get()->leaves()` flattens a collapsed read back to rows. |
| **One-row phases** | Still wrapped, so a step's label never depends on how many internal transitions happened to be recorded. |
| **Expanded phases** | A phase the view chose not to collapse passes through as leaves that keep their `phase` key, and breaks any run around it. |
| **Jumps** | `jumpTo()` rows are **never** collapsed, carry no phase, and break any run they land in. A forced state change is exactly what an audit trail must not hide. |
| **States in no phase** | A row landing in a state that belongs to no phase carries no phase and passes through as a leaf. Renaming or removing a *transition* changes nothing — phases resolve from the state. |

### Filtering rows per audience

A view is a shape plus a set of **conditions**. Conditions accept closures, instances or
class-strings (resolved from the container), exactly like `guard()` and `action()`. A row is kept
only when every condition returns a truthy value.

Implement `Flowra\Contracts\RegistryScopeContract` when the condition can be a query — it is pushed
into SQL so hidden rows are never loaded:

```php
use Flowra\Contracts\HasWorkflowContract;
use Flowra\Contracts\RegistryScopeContract;
use Illuminate\Database\Eloquent\Builder;

class OnlyPublishedSteps implements RegistryScopeContract
{
    public function apply(Builder $query, HasWorkflowContract $owner, mixed $viewer = null): void
    {
        $query->whereNotIn('transition', ['internal_note', 'fraud_flag']);
    }
}
```

Implement `Flowra\Contracts\RegistryFilterContract` when it cannot, and it runs once per fetched
row:

```php
use Flowra\Contracts\HasWorkflowContract;
use Flowra\Contracts\RegistryFilterContract;
use Flowra\Models\Registry;

class HideInternalRows implements RegistryFilterContract
{
    public function allows(Registry $row, HasWorkflowContract $owner, mixed $viewer = null): bool
    {
        return $row->transition !== 'internal_note'
            || ($viewer !== null && $viewer->isStaff());
    }
}
```

A closure condition receives `($row, $owner, $viewer)` and behaves like a filter. A class may
implement both contracts — the scope narrows in SQL, the filter refines in PHP.

**The viewer is whatever you pass to `->for()`** — a user, a token, a role string, or `null` in
queue and CLI context. Flowra never calls `auth()`, never inspects roles, and never assumes the
viewer is authenticatable. Your condition decides what `null` means.

**Filtering happens before collapsing.** A row the viewer cannot see must not contribute its state
or timestamp to an entry the viewer *can* see. The practical consequence: to make a phase render
as one step, don't filter its children out — collapsing hides internal *detail*, filtering makes
rows *vanish*. Hiding an internal note that sat in the middle of a review run makes the rows on
either side adjacent, so they collapse into a single "Under Review" entry.

### Who an entry is attributed to

A collapsed entry stands in for several rows, so *someone* has to be the applier it renders as. By
default that is whoever left the phase. Declare an applier on the **view** when it should be
something else — the office rather than the four reviewers behind it, a service account, or nobody
in particular:

```php
use Flowra\Enums\RegistryActorEnum;

RegistryView::make('applicant')->collapsed()->appliedBy(99);
RegistryView::make('ops')->collapsed()->appliedByFirst();
RegistryView::make('courier')->collapsed()->appliedBy(RegistryActorEnum::SOLE);
RegistryView::make('desk')->collapsed()->appliedBy(
    fn (array $rows, $owner, $viewer) => $owner->assigned_desk_id
);

// Per read.
$order->orderWorkflow->registryView('applicant')->appliedBy(99)->get();
```

Who an entry renders as is a property of the **audience**, so it is declared on the view and never
on the phase — the same phase can credit the warehouse in one view and the picker in another.

An applier is an actor id, a `RegistryActorEnum` strategy, or (everywhere except the config file) a
closure receiving the registry rows behind the entry — one row for a leaf, the whole run for a
collapsed entry. A closure must return an actor id (`int|string`) or `null`.

| Strategy | Shortcut | Picks |
|---|---|---|
| `RegistryActorEnum::LAST` | `appliedByLast()` | The actor who closed the run. The default. |
| `RegistryActorEnum::FIRST` | `appliedByFirst()` | The actor who opened it. |
| `RegistryActorEnum::SOLE` | `appliedBySole()` | The single actor behind the run — and *nobody* when several took part, which then falls through to the system user. |

On a leaf every strategy resolves to the row's own `applied_by`.

**The system user.** Rows written by a queue, a console command or a webhook carry
`applied_by = null`, and every UI then invents its own "System" placeholder. Point
`registry_views.system_user` at an account in your application and *every* entry that would
otherwise have no actor — collapsed parent or detailed leaf — renders as it. It is `null` by
default, which leaves those entries actorless.

```php
// config/flowra.php
'registry_views' => [
    'system_user' => env('FLOWRA_REGISTRY_SYSTEM_USER'),  // e.g. 1
],
```

**Nothing is written differently.** Attribution is a read-time projection, like collapsing: the row
keeps the `applied_by` it was written with, and the entry carries it alongside the rendered one.

```php
$entry->appliedBy;      // who the entry renders as — 99
$entry->recordedBy;     // who the row recorded — 5
$entry->isAttributed(); // true: a declaration (or the system user) put that actor there
$entry->participants(); // [99] — an entry shown as one actor exposes one actor
$entry->children;       // the leaves, with their own actors, untouched
```

An entry attributed to a single applier reports only that applier in `participants()`. A
*strategy* names no new actor — it picks one out of the rows — so `participants()` keeps listing
everyone. The full trail is always one `children` (or `registry()`) away — which is exactly what a
**mask** takes away.

### Hiding an actor with masks

`appliedBy()` decides what an entry *shows*; it does not hide anything. The rows keep their
recorded actor, `$entry->recordedBy` still exposes it, and a collapsed parent emits its children
with their own actors.

When an audience must not learn who acted — an applicant has no business knowing which reviewer
rejected them — mask the phase or the transition instead:

```php
RegistryView::make('applicant')
    ->collapsed()
    ->maskPhase('under_review', 'review_committee')  // phase key, or any state in it
    ->maskTransition('reject', 'review_committee');  // one transition, leaf or not

// Per read, on top of whatever the view already masks.
$order->orderWorkflow->registryView('applicant')->maskPhase('under_review', 99)->get();
```

```php
$entry->appliedBy;      // 'review_committee'
$entry->recordedBy;     // null — the mask dropped it
$entry->isRedacted();   // true
$entry->participants(); // ['review_committee']
$entry->children;       // masked too
```

A mask is stricter than an attribution in three ways:

| | |
|---|---|
| **It redacts** | `recordedBy` is dropped, and a collapsed parent's children are masked too — so a serialized entry carries the real actor nowhere. `registry()` is untouched. |
| **It cannot be lifted** | A mask outranks every `appliedBy` declaration, including a per-read one. A privacy rule a looser declaration could defeat would not be worth declaring. |
| **It covers jumps and every shape** | A `jumpTo()` row landing in a masked phase is masked like any other, and a detailed read redacts masked rows too. |

A transition mask is the more specific of the two and wins on a row both would claim. Masks take an
actor id, a translatable actor key, or a closure — but not a `RegistryActorEnum` strategy: a
strategy picks a real actor out of the rows, which is the opposite of hiding one. A mask whose
closure returns `null` still redacts — it just falls through to the system user for the stand-in.

### Actor resolution order

The highest declaration present decides:

1. a **mask** — `maskTransition()`, then `maskPhase()`, on the view or the read;
2. the builder — `registryView()->appliedBy(...)`;
3. the view — `RegistryView::make(...)->appliedBy(...)`;
4. the default — a leaf keeps its own `applied_by`, a run takes the actor who closed it;
5. `config('flowra.registry_views.system_user')`, if the step that decided resolved to `null`.

Only the last step is a fallback: a view whose closure returns `null` lands on the system user, not
back on a looser declaration.

### Labels and translations

Every entry names itself, so an API response needs no lookup table on the client. Each accessor
tries a translation first and falls back to a humanized value (`under_review` → *Under Review*):

| Accessor | Resolution order | `null` when |
|---|---|---|
| `label()` on a **phase** | `Phase->label()` → `flowra::flowra.phases.{key}` → humanized key | never |
| `label()` on a **leaf** | `flowra::flowra.states.{to}` → humanized state | never |
| `fromLabel()` / `toLabel()` | `flowra::flowra.states.{value}` → humanized value | there is no state |
| `phaseLabel()` | same as a phase's `label()` | the state is in no phase (always on a jump) |
| `typeLabel()` | `flowra::flowra.types.{transition\|reset\|phase}` → humanized case name | the stored type matches no case |

A few details:

- `Phase::make('under_review')->label('workflows.order.under_review')` is used as a
  translation key and falls back to itself — so it also accepts a literal like `'Under Review'`.
- On a phase, `toLabel()` names the state the run **ended at**; the phase itself is `label()` /
  `phaseLabel()`.
- `flowra::flowra.transitions.{key}` is consulted only for a row that recorded no landing state at
  all. A leaf that has one is always named after its state.
- `typeLabel()` translates on the lower-cased case *name*, because `flowra.types.phase` reads where
  `3` does not.

Publish the translations (`--tag=flowra-translations`) and fill in the buckets you need:

```php
// lang/vendor/flowra/en/flowra.php
'phases' => ['under_review' => 'Under Review'],
'states' => ['docs_ok' => 'Documents Verified'],
'types'  => ['phase' => 'Step', 'reset' => 'Forced Change'],
```

**Naming actors.** Flowra resolves no actor name of its own: `applied_by` is what travels, whether
it is an account id or a mask's stand-in key like `'review_committee'`. Naming it is the host's
call. The published lang file keeps an `actors` bucket as a convenient place for stand-in names:

```php
// lang/vendor/flowra/en/flowra.php
'actors' => [
    'review_committee' => 'Review Committee',
    '1' => 'System',   // the configured system_user
],

// In your API resource:
$key  = "flowra::flowra.actors.{$entry->appliedBy}";
$name = trans()->has($key) ? __($key) : User::find($entry->appliedBy)?->name;
```

### JSON payload

`toArray()` / `jsonSerialize()` produce the shape below. This is the `under_review` entry from the
[phases example](#phases-in-registry-views), with default (untranslated) labels:

```jsonc
{
  "key": "under_review",
  "label": "Under Review",
  "type": { "key": 3, "label": "Phase" },
  "transition": null,
  "phase": {
    "key": "under_review",
    "label": "Under Review",
    "started_at": "2026-01-01T09:02:00+00:00",
    "ended_at": "2026-01-01T09:04:00+00:00"
  },
  "from": { "key": "submitted", "label": "Submitted" },
  "to": { "key": "scored", "label": "Scored" },
  "comment": [],
  "applied_by": 8,
  "applied_at": "2026-01-01T09:02:00+00:00",
  "attributed": false,
  "redacted": false,
  "children": [
    {
      "key": "in_review",
      "label": "In Review",
      "type": { "key": 1, "label": "Transition" },
      "transition": "start_review",
      "phase": { "key": "under_review", "label": "Under Review", "started_at": null, "ended_at": null },
      "from": { "key": "submitted", "label": "Submitted" },
      "to": { "key": "in_review", "label": "In Review" },
      "comment": [],
      "applied_by": 7,
      "applied_at": "2026-01-01T09:02:00+00:00",
      "attributed": false,
      "redacted": false
    }
    // … one object per row in the run
  ]
}
```

| Field | Notes |
|---|---|
| `type.key` | `1` transition, `2` reset (a `jumpTo()`), `3` phase. There is no `collapsed` flag — `type.key === 3` *is* a collapsed entry. |
| `phase`, `from`, `to` | `null` outright — not a shell of nulls — when there is no phase or no state, so a client tests the field rather than reaching into it. |
| `phase.started_at` / `ended_at` | Filled only on the entry that stands for the phase. A leaf inside a phase reports `null` rather than passing its own instant off as the step's duration. |
| `applied_at` | One ISO-8601 instant: a leaf's `created_at`; on a phase, when the run began (equal to `phase.started_at`). |
| `applied_by` | The rendered actor — the only actor in the payload. `recordedBy` stays on the DTO and in `registry()`. |
| `attributed` / `redacted` | Whether `applied_by` came from a declaration, the system user, or a mask rather than the row. `redacted` always implies `attributed`. |
| `children` | Present only on a phase; a leaf omits the key rather than repeating an empty array down the tree. |

### Views and caching

Views are memoized per process and rebuilt on every application boot, but deliberately **not**
pushed through `Support\WorkflowCache`: they can hold closures and bound objects, so persisting
them would fail on every request. The phase map costs nothing extra — it is derived from the
already-cached phases.

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
| `models.status` | `Flowra\Models\Status::class` | Eloquent class used for status rows |
| `models.registry` | `Flowra\Models\Registry::class` | Eloquent class used for registry rows |
| `registry_views.default_view` | `default` | View used when `registryView()` is called with no name (env: `FLOWRA_REGISTRY_DEFAULT_VIEW`) |
| `registry_views.system_user` | `null` | Actor a rendered entry falls back to when nothing else resolves one (env: `FLOWRA_REGISTRY_SYSTEM_USER`) |
| `registry_views.views` | `[]` | Named registry views shared by every workflow — see [Declaring views](#declaring-views) |

### Using your own Status / Registry models

Point `models.status` / `models.registry` at your own classes to add casts, relations, scopes, or
other behavior to the rows Flowra writes. Your class **must extend** the corresponding Flowra
model:

```php
namespace App\Models;

use Flowra\Models\Status as BaseStatus;

class Status extends BaseStatus
{
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
```

```php
// config/flowra.php
'models' => [
    'status' => \App\Models\Status::class,
    'registry' => \App\Models\Registry::class,
],
```

Every relation (`{alias}Status`, `{alias}Registry`, `statuses()`, `registry()`) and every internal
read/write (`$workflow->status()`, `$workflow->registry()`, `registryView()`, transition
persistence) resolves the class through this config at call time, so the swap applies package-wide
with no other code changes.

## Database Schema

Two tables, both keyed by UUID and polymorphic on `owner`:

- **`statuses`** — one row per `(owner, workflow)` pair (enforced by a unique index) holding the
  current state: `workflow` (FQCN), `transition`, `from`, `to`, `comment` (JSON), `applied_by`, `type`.
- **`statuses_registry`** — same columns, one row appended per applied transition or jump.

`applied_by` is a nullable unsigned integer column — pass integer user IDs. Registry views never
write to it: attribution and masks are read-time only.

Status and registry writes happen inside a database transaction: the current-state update and the
registry append always succeed or fail together.

## Definition Caching

When `cache_workflows` is enabled (the default), parsed states, transitions, and phases are stored
**indefinitely** in the configured cache store under `flowra:workflow:{class}:{key}` keys, in
addition to per-process memoization.

Things to know:

- **After changing a `transitionsSchema()`, a states enum, or its `phases()`, flush the cached
  keys** — there is no registered cache-clear command in the current release. Call
  `Flowra\Support\WorkflowCache::forget(OrderWorkflow::class)`, clear your cache store (e.g.
  `php artisan cache:clear` for the relevant store), or delete keys matching `flowra:workflow:*`.
- Transitions that use **closure** guards/actions cannot be serialized; those definitions silently
  skip the persistent cache and are rebuilt per process. Class-based guards/actions cache fine.
- Registry views are never written to this cache — see [Views and caching](#views-and-caching).
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
- **Serve `registryView()` to users, not `registry()`.** The raw relation returns every row and
  every actor; a view is where visibility and privacy rules live.
- **Prefer `RegistryScopeContract` for visibility rules.** Hidden rows are never loaded, and
  detailed reads keep paginating in SQL.
- **Mask, don't just attribute,** when an audience must not learn who acted — `appliedBy()` leaves
  the real actor one `children` away.
- **Set `registry_views.system_user`** so rows written by queues and commands render consistently.

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

**`RegistryViewNotFoundException: Registry view (...) is not defined for workflow (...)`**
The name isn't registered in `registry_views.views` or the workflow's `registryViews()`. If you
added it to config, run `php artisan config:clear`.

**A collapsed view returns every row as a leaf**
Check, in order: the landing states actually belong to a phase; the phase cache isn't stale
(`WorkflowCache::forget(OrderWorkflow::class)`); the view's `collapse()` / `expand` selection
includes the phase; the rows aren't jumps, which never collapse.

**`config:cache` fails after adding registry views**
A config view holds a closure. Move closure conditions and appliers to the workflow's
`registryViews()`.

**`InvalidArgumentException: A registry attribution closure must return an actor id`**
An `appliedBy` or mask closure returned an object (e.g. a `User`). Return `$user->id` instead.

**A phase label shows a raw key like `workflows.order.under_review`**
`Phase->label()` is treated as a translation key and falls back to itself. Add the key to your
lang files, or pass a literal label.

## Known Limitations

- The extra arguments (`appliedBy`, `comments`, ...) of the `apply{Workflow}Transition`
  builder/collection macro are not yet forwarded — use `Workflow::applyMany()` or
  `BulkTransitionService` when you need audit metadata on bulk runs.
- Diagram import (`flowra:import-workflow`) and cache warm/clear commands exist in the source but
  are not registered in the current release.
- Phases are **one level deep**: a state belongs to at most one phase, and phases do not nest.
  Multi-phase membership is not validated.
- Collapsed registry reads, and reads behind a PHP-side condition, load all of the owner's rows for
  that workflow before paginating. Bound very large histories through `->query()`.
- A mask hides an actor from a **rendered view**, not from the database: `registry()`, the raw
  relation, still returns every `applied_by`. Authorize who may read the unmasked trail.

## Testing

```bash
composer test
```

## License

The MIT License (MIT).

## Issues & Contributions

Bug reports and pull requests are welcome on [GitHub](https://github.com/mhQady/flowra/issues).
