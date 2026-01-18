# Flowra

Flowra is a database-driven workflow engine for Laravel applications. It lets you describe business processes as
workflows composed of **typed states**, **transitions**, **guards**, **actions**, and **state groups**—all persisted in
your database so processes can evolve without redeploying code.

The package provides artisan generators, migration stubs, rich Eloquent traits, and DTO helpers that make it simple to:

- attach workflows to any Eloquent model,
- track the current status and historical registry of each workflow run,
- nest state groups,
- gate transitions with guards and execute actions after state changes, and
- query models by workflow state (including grouped states) using fluent scopes.

---

## 📦 Installation

```bash
composer require mhqady/flowra
```

Register the service provider if you are not using package auto-discovery, then publish the assets as shown below. Add
`HasWorkflow` to any model that needs a workflow and use the stubs to generate your first workflow pair.

---

## ✨ Key Concepts

### Workflows & States

Every workflow extends `Flowra\Concretes\BaseWorkflow` and defines two classes:

- `YourWorkflow` – contains transition definitions.
- `YourWorkflowStates` – an enum describing the workflow states. Optional `groups()` declarations describe grouped
  states (e.g., a `draft` group that owns all of the draft states).

```php
enum MainWorkflowStates: string
{
    use Flowra\Enums\BaseEnum;

    case INIT = 'init';
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    // ...

    public static function groups(): array
    {
        return [
            \Flowra\DTOs\StateGroup::make(self::DRAFT)->children(
                self::INIT,
                // ...
            ),
        ];
    }
}
```

State groups are surfaced everywhere—switching, querying, and eager loading—so your workflow knows when it is in a
grouped state and scopes can refer to either the parent group or any of its child states.

### Transitions

Use the `Transition` DTO to define transitions, guards, and actions:

```php
class MainWorkflow extends BaseWorkflow
{
    public static function transitionsSchema(): array
    {
        return [
            Transition::make('filling_app_data', MainWorkflowStates::INIT, MainWorkflowStates::DRAFT)
                ->guard(CheckPermissions::class)
                ->action(fn ($transition) => event(new WorkflowStarted($transition))),
        ];
    }
}
```

Transitions are cached and exposed as magic properties (`$model->mainWorkflow->fillingAppData`) so you can call
`->apply()` on them fluently.

### Guards & Actions

- **Guards** (`Flowra\Contracts\GuardContract`) determine if a transition may proceed.
- **Actions** (`Flowra\Contracts\ActionContract`) run after persistence.

Both can be closures, container-resolved class names, or concrete instances.

### Persistence

Flowra publishes migrations for two tables:

- `statuses` – holds the current status per workflow & owner, including hierarchy data, type, and path.
- `statuses_registry` – an append-only log of every transition.

The included `Status` and `Registry` models provide accessors; workflows are kept in sync via the `HasStates`,
`HasStateGroups`, and `HasTransitions` traits in `BaseWorkflow`.

### Model Integration

Add `Flowra\Concretes\HasWorkflow` to your Eloquent model and define the workflows it uses:

```php
class Context extends Model
{
    use HasWorkflow;

    protected static array $workflows = [
        \App\Workflows\MainWorkflow\MainWorkflow::class,
    ];
}
```

`HasWorkflow` wires up:

- attribute casts (via `WorkflowAware`) so `$context->mainWorkflow` returns the live workflow instance,
- relations (`mainWorkflowStatus`, `mainWorkflowRegistry`, `statuses`, `registry`), and
- query scopes/macros (`whereMainWorkflowCurrentStatus`, `withWhereMainWorkflowCurrentStatus`, etc.) that understand
  grouped states.

## 🧰 Tooling

Flowra ships several artisan commands once registered through `FlowraServiceProvider`:

- `flowra:make-workflow` – scaffolds a workflow class and its states enum (with the `groups()` template).
- `flowra:make-guard`, `flowra:make-action` – generate guard/action classes.
- `flowra:list-workflow` – inspect registered workflows at runtime.
- `flowra:export-workflow` – export any workflow to a Mermaid or PlantUML diagram (print to the console or write to
  disk).
- `flowra:import-workflow` – convert a Mermaid/PlantUML diagram back into enum cases, transition snippets, and
  ready-to-use workflow/state files.

```bash
# print a Mermaid diagram
php artisan flowra:export-workflow "Flowra\Flows\MainFlow\MainWorkflow"
# ⇢ writes to storage/app/flowra/workflows/Flowra-Flows-MainFlow-MainWorkflow.mmd

# or pass just the class name (resolved from config('flowra.workflows_namespace'))
php artisan flowra:export-workflow "MainWorkflow"
# ⇢ resolves to App\Workflows\MainWorkflow\MainWorkflow by default

# save a PlantUML diagram to a file
php artisan flowra:export-workflow "Flowra\Flows\MainFlow\MainWorkflow" \
    --format=plantuml --output=storage/app/diagrams/main-workflow.puml

# convert a Mermaid file into PHP snippets + workflow/state classes
php artisan flowra:import-workflow "Flowra\Flows\MainFlow\MainWorkflow" \
    --path=storage/app/flowra/workflows/Flowra-Flows-MainFlow-MainWorkflow.mmd \
    --force  # overwrite the generated files if they already exist

# or paste a diagram directly (finish with CTRL+D/CTRL+Z) and let Flowra resolve PSR-4 paths automatically
cat diagram.mmd | php artisan flowra:import-workflow "App\Workflows\OrderWorkflow"

# a snippets summary is still stored at storage/app/flowra/imports/<Workflow>-import.php unless you override --output
```

`flowra:import-workflow` always writes the workflow class and its states enum into the same directory defined by
`config('flowra.workflows_path')` (default: `app/workflows/<Workflow>`). Update that config value to point Flowra at a
different base directory for generated workflows.

It also publishes config (`config/flowra.php`), migrations, stubs, and translations. After installing via Composer, run:

```bash
php artisan vendor:publish --tag=flowra-config
php artisan vendor:publish --tag=flowra-migrations
php artisan vendor:publish --tag=flowra-stubs
php artisan migrate
```

---

## 🚀 Usage

Once you have configured your model with the `HasWorkflow` trait and defined your workflows, you can start using them.

### Applying Transitions

There are several ways to apply transitions to a model.

#### Using Magic Properties

The easiest way is to use the magic properties on the workflow instance, which correspond to the transition keys defined
in your `transitionsSchema`.

```php
$context = Context::find(1);

// Apply transition using its key as a property
$context->mainWorkflow->filling_app_data->apply();

// Or using camelCase
$context->mainWorkflow->fillingAppData->apply();
```

#### Using the Transition DTO

You can also apply a transition by passing the `Transition` DTO directly.

```php
use Flowra\DTOs\Transition;

$context->mainWorkflow->apply(
    Transition::make('filling_app_data', MainWorkflowStates::INIT, MainWorkflowStates::DRAFT)
);
```

#### Adding Metadata

You can attach comments or the ID of the user who applied the transition.

```php
$context->mainWorkflow->filling_app_data
    ->comment('This is a reason for transition', 'Another comment')
    ->appliedBy(auth()->id())
    ->apply();
```

### Bulk Transitions

Flowra provides a way to apply transitions to multiple models efficiently.

```php
$models = Context::whereIn('id', [1, 2, 3])->get();

// Apply transition to a collection
MainWorkflow::applyMany($models, 'filling_app_data');

// You can also use the BulkTransitionService for more control
use Flowra\Services\BulkTransitionService;

BulkTransitionService::for(MainWorkflow::class)
    ->targets($models)
    ->transition('filling_app_data')
    ->appliedBy(auth()->id())
    ->comments(['Bulk update'])
    ->run();
```

### State Jumps

If you need to move a model to a specific state without following a defined transition (e.g., for administrative
resets), you can use the `jumpTo` method.

```php
$context->mainWorkflow->jumpTo(MainWorkflowStates::INIT, 'admin_reset');
```

### Accessing Status & History

You can easily access the current status and the full history of transitions.

```php
// Get current status model (Flowra\Models\Status)
$status = $context->mainWorkflow->status();
echo $status->to; // 'draft'

// Get current state (Enum case)
$state = $context->mainWorkflow->currentState;

// Get history (Collection of Flowra\Models\Registry)
$history = $context->mainWorkflow->registry();
```

---

## 🧪 Querying & Scopes

The `HasWorkflowScopes` trait (included in `HasWorkflow`) installs helper scopes and macros that allow you to query your
models based on their workflow state.

### Basic Scopes

```php
// Find all models in a specific state
Context::query()
    ->whereMainWorkflowCurrentStatus(MainWorkflowStates::PUBLISHED)
    ->get();

// Using orWhere
Context::query()
    ->whereMainWorkflowCurrentStatus(MainWorkflowStates::DRAFT)
    ->orWhereMainWorkflowCurrentStatus(MainWorkflowStates::INIT)
    ->get();
```

### State Group Expansion

Flowra's scopes automatically handle state groups. If you query by a parent state that has children, Flowra will include
models in any of those child states.

```php
// If DRAFT group includes INIT, FILLING_DATA, and SENT
// This will find models in any of those three states
Context::query()
    ->whereMainWorkflowCurrentStatus(MainWorkflowStates::DRAFT)
    ->get();
```

### Eager Loading Statuses

To avoid N+1 issues when you need to access workflow information for multiple models, use the `with` scopes.

```php
// Eager load the main workflow status
$models = Context::query()
    ->withMainWorkflowStatus()
    ->get();

// Eager load and filter at the same time
$models = Context::query()
    ->withWhereMainWorkflowCurrentStatus(MainWorkflowStates::READY_FOR_AUDITING)
    ->get();
```

---

Flowra brings workflow modeling, state management, and auditability straight into your Laravel models. Whether
you’re orchestrating multi-step approvals or complex business processes, Flowra gives you strongly typed, testable
workflows you can iterate on quickly. Happy flowing!
