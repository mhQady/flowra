# Flowra

Flowra is a database-driven workflow engine for Laravel applications. It lets you describe business processes as
workflows composed of **typed states**, **transitions**, **guards**, **actions**, and **state groups**—all persisted in
your database so processes can evolve without redeploying code.

The package provides artisan generators, migration stubs, rich Eloquent traits, and DTO helpers that make it simple to:

- **Attach workflows** to any Eloquent model.
- **Track current status** and historical registry of each workflow run.
- **Group states** to simplify querying and logic (e.g., "Draft" group containing "Initial" and "Pending").
- **Gate transitions** with custom Guard classes or closures.
- **Execute actions** automatically after a state change.
- **Bulk transitions** for processing multiple models efficiently.
- **Diagram export/import** (Mermaid & PlantUML) to visualize and scaffold workflows.
- **Fluent query scopes** that understand both specific states and state groups.

---

## 📦 Installation

```bash
composer require mhqady/flowra
```

### 1. Publish Assets

Publish the configuration, migrations, and stubs:

```bash
php artisan vendor:publish --tag=flowra-config
php artisan vendor:publish --tag=flowra-migrations
php artisan vendor:publish --tag=flowra-stubs
```

### 2. Run Migrations

Flowra uses two tables: `statuses` (current state) and `statuses_registry` (history).

```bash
php artisan migrate
```

---

## 🚀 Quick Start

### 1. Generate a Workflow

Use the artisan command to scaffold a workflow and its states:

```bash
php artisan flowra:make-workflow "OrderWorkflow"
```

This creates:

- `app/Workflows/OrderWorkflow/OrderWorkflow.php`
- `app/Workflows/OrderWorkflow/OrderWorkflowStates.php`

### 2. Define States and Transitions

In `OrderWorkflowStates.php`, define your cases and optional groups:

```php
enum OrderWorkflowStates: string
{
    use Flowra\Enums\BaseEnum;

    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';

    public static function groups(): array
    {
        return [
            \Flowra\DTOs\StateGroup::make('active')->children(self::PENDING, self::PROCESSING),
        ];
    }
}
```

In `OrderWorkflow.php`, define the allowed transitions:

```php
class OrderWorkflow extends BaseWorkflow
{
    public static function transitionsSchema(): array
    {
        return [
            Transition::make('process', OrderWorkflowStates::PENDING, OrderWorkflowStates::PROCESSING)
                ->guard(CheckStock::class)
                ->action(NotifyCustomer::class),
        ];
    }
}
```

### 3. Prepare Your Model

Add the `HasWorkflow` trait and implement `HasWorkflowContract`:

```php
use Flowra\Contracts\HasWorkflowContract;
use Flowra\Traits\HasWorkflow;

class Order extends Model implements HasWorkflowContract
{
    use HasWorkflow;

    protected static array $workflows = [
        \App\Workflows\OrderWorkflow\OrderWorkflow::class,
    ];
}
```

---

## ✨ Key Concepts

### Guards & Actions

- **Guards** (`Flowra\Contracts\GuardContract`) determine if a transition can proceed. If a guard returns `false`, an
  exception is thrown.
- **Actions** (`Flowra\Contracts\ActionContract`) run after the transition is successfully persisted.

Both can be:

- **Closures**: `->action(fn($t) => ...)`
- **Class Names**: `->guard(MyGuard::class)`
- **Instances**: `->action(new MyAction($param))`

### Dynamic Scopes

Flowra automatically adds scopes to your model based on the registered workflows. If you have `OrderWorkflow`, you get:

```php
// Find orders currently in 'pending' state
Order::whereOrderWorkflowCurrentStatus(OrderWorkflowStates::PENDING)->get();

// Find orders in 'active' group (includes pending and processing)
Order::whereOrderWorkflowCurrentStatus('active')->get();

// Eager load status to avoid N+1
Order::withOrderWorkflowStatus()->get();
```

### Dynamic Relations

Flowra automatically registers Laravel relations on your model for each workflow. If your workflow is `OrderWorkflow`,
you can access:

```php
// Returns the current Flowra\Models\Status for this workflow
$order->orderWorkflowStatus;

// Returns a collection of Flowra\Models\Registry (history) for this workflow
$order->orderWorkflowRegistry;
```

These are standard Eloquent relations, so you can eager load them or query through them:

```php
$orders = Order::with('orderWorkflowStatus')->get();
```

---

## 🧰 Tooling & Commands

- `flowra:make-workflow {name}` – Scaffolds workflow and state classes.
- `flowra:make-guard {name}` / `flowra:make-action {name}` – Generates guard or action classes.
- `flowra:list-workflow` – Lists all registered workflows.
- `flowra:export-workflow {class}` – Exports to Mermaid or PlantUML.
- `flowra:import-workflow {class} --path={file}` – Imports from Mermaid/PlantUML.
- `flowra:cache:warm` / `flowra:cache:clear` – Manage workflow definition cache.

---

## 🛠 Usage Examples

### Applying Transitions

```php
$order = Order::find(1);

// Using magic property (key from transitionsSchema)
$order->orderWorkflow->process->apply();

// With metadata
$order->orderWorkflow->process
    ->appliedBy(auth()->id())
    ->comment('Starting the order')
    ->apply();
```

### Bulk Transitions

Process multiple models efficiently:

```php
$orders = Order::where('status', 'pending')->get();

OrderWorkflow::applyMany($orders, 'process', appliedBy: auth()->id());
```

### State Jumps

Force a state change without a defined transition (e.g., for admin resets):

```php
$order->orderWorkflow->jumpTo(OrderWorkflowStates::PENDING, 'admin_reset');
```

### History & Audit

```php
// Current status
$status = $order->orderWorkflow->status();

// Full transition history
$history = $order->orderWorkflow->registry();
```

---

Flowra makes complex business processes manageable, testable, and visible. Happy flowing!
