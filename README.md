# Flowra

Flowra is a database-driven workflow engine for Laravel applications. It lets you describe business processes as
workflows composed of **typed states**, **transitions**, **guards**, **actions**, and **state groups**—all persisted in
your database so processes can evolve without redeploying code.

The package provides artisan generators, migration stubs, rich Eloquent traits, and DTO helpers that make it simple to:

- attach workflows to any Eloquent model,
- track the current status and historical registry of each workflow run,
- nest inner workflows through state groups,
- gate transitions with guards and execute actions after state changes, and
- query models by workflow state (including grouped states) using fluent scopes.

---

## ✨ Key Concepts

### Workflows & States

Every workflow extends `Flowra\Concretes\BaseWorkflow` and defines two classes:

- `YourWorkflow` – contains transition definitions.
- `YourWorkflowStates` – an enum describing the workflow states. Optional `groups()` declarations describe grouped
  states that wrap nested flows (e.g., a `draft` group that owns all of the child fill-data states).

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
                FillAppDataWorkflowStates::INIT,
                FillAppDataWorkflowStates::OWNER_INFO_ENTERED,
                FillAppDataWorkflowStates::SENT,
            ),
        ];
    }
}
```

State groups are surfaced everywhere—switching, querying, and eager loading—so your parent workflow knows when a child
flow is running and scopes can refer to either the parent `draft` group or any of its nested child states.

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

### Subflows via State Groups

Instead of bespoke subflow contracts, Flowra uses state groups to represent nested workflows. When a parent state is
grouped, Flowra knows:

- the parent workflow should suspend until the child reaches one of the mapped exit states,
- the registry should record the nested path/parent IDs, and
- query scopes should normalize child states back to the parent group automatically.

---

## 🧰 Tooling

Flowra ships several artisan commands once registered through `FlowraServiceProvider`:

- `flowra:make-workflow` – scaffolds a workflow class and its states enum (with the `groups()` template).
- `flowra:make-guard`, `flowra:make-action` – generate guard/action classes.
- `flowra:list-workflow` – inspect registered workflows at runtime.
- `flowra:export-workflow` – export any workflow to a Mermaid or PlantUML diagram (print to the console or write to disk).
- `flowra:import-workflow` – convert a Mermaid/PlantUML diagram back into enum cases, transition snippets, and ready-to-use workflow/state files.

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

## 📦 Installation

```bash
composer require mhqady/flowra
```

Register the service provider if you are not using package auto-discovery, then publish the assets as shown above. Add
`HasWorkflow` to any model that needs a workflow and use the stubs to generate your first workflow pair.

---

## 🧪 Querying & Scopes

The `HasWorkflowScopes` trait installs helper scopes/macros:

```php
Context::query()
    ->whereMainWorkflowCurrentStatus(MainWorkflowStates::READY_FOR_AUDITING)
    ->orWhereMainWorkflowCurrentStatus(FillAppDataWorkflowStates::OWNER_INFO_ENTERED) // expands to the parent group
    ->withWhereMainWorkflowCurrentStatus(MainWorkflowStates::DRAFT) // eager loads grouped statuses
    ->get();
```

State groups are automatically expanded or collapsed depending on whether you filter by a parent state or a child state.

---

Flowra brings workflow modeling, nested state management, and auditability straight into your Laravel models. Whether
you’re orchestrating multi-step approvals or spinning up nested subflows, Flowra gives you strongly typed, testable
workflows you can iterate on quickly. Happy flowing!
