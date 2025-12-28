# Workflow caching discussion (transitions/states)

Goal: explicit memoization of `transitions()` and `states()` per workflow with optional caching toggle for large apps.

Current state:

- `Bootable` + static properties already cache transitions/states in memory for the process lifetime.
- No external cache; reboots per PHP request still rebuild arrays.

Proposed additions:

- Config flag (e.g., `flowra.cache_workflows` + `flowra.cache_driver`) to enable/disable persistent cache.
- Cache keys per workflow class: `flowra:workflow:{class}:transitions` and `...:states`.
- Serialization strategy: store DTO arrays (Transition key/from/to) and state enums as scalar values; rebuild objects on
  hydrate.
- Fallback to current behavior if cache disabled or missing.

Implementation sketch:

1) Add a `WorkflowCache` helper (or trait) to get/set/forget entries using Laravel cache manager.
2) In `HasTransitions::transitions()`, try cache first; on miss, build via `bootHasTransitions()` and store if enabled.
3) In `HasStates::states()` and `statesEnum()`, do similar: cache map of value -> enum name; rebuild enums with
   `::tryFrom`.
4) Invalidate cache on deploy or when workflows change: expose `flowra:cache:clear` artisan command to forget
   per-workflow keys.

Questions:

- Is config-driven enabling sufficient, or should caching default on for production?
- Any need for TTL, or should entries persist until cleared?
- Should we expose a helper to prewarm cache for all registered workflows?
  Answers:
- Config-driven enabling is sufficient.
- Entries persist until cleared (no TTL).
- Expose a helper/command to prewarm cache for all registered workflows.

Code example + detailed plan

Config (add to `config/flowra.php`):

```php
'cache_workflows' => env('FLOWRA_CACHE_WORKFLOWS', false),
'cache_driver' => env('FLOWRA_CACHE_DRIVER', null), // null => use default cache store
```

Helper (`src/Support/WorkflowCache.php`):

```php
namespace Flowra\Support;

use Illuminate\Support\Facades\Cache;

class WorkflowCache
{
    public static function get(string $workflow, string $key): mixed
    {
        if (!config('flowra.cache_workflows')) {
            return null;
        }
        return Cache::store(config('flowra.cache_driver'))->get(self::k($workflow, $key));
    }

    public static function put(string $workflow, string $key, mixed $value): void
    {
        if (!config('flowra.cache_workflows')) {
            return;
        }
        Cache::store(config('flowra.cache_driver'))->forever(self::k($workflow, $key), $value);
    }

    public static function forget(string $workflow): void
    {
        if (!config('flowra.cache_workflows')) {
            return;
        }
        Cache::store(config('flowra.cache_driver'))->forget(self::k($workflow, 'transitions'));
        Cache::store(config('flowra.cache_driver'))->forget(self::k($workflow, 'states'));
    }

    private static function k(string $workflow, string $key): string
    {
        return "flowra:workflow:{$workflow}:{$key}";
    }
}
```

Use in `HasTransitions::transitions()`:

```php
public static function transitions(): array
{
    static::bootIfNotBooted();

    $cached = WorkflowCache::get(static::class, 'transitions');
    if ($cached !== null) {
        $statesEnum = static::$statesEnum[static::class] ?? null;
        return $statesEnum
            ? array_map(
                fn($row) => Transition::make($row['key'], $statesEnum::from($row['from']), $statesEnum::from($row['to'])),
                $cached
            )
            : [];
    }

    if (!isset(static::$transitions[static::class])) {
        return [];
    }

    $transitions = static::$transitions[static::class];

    $payload = array_map(
        fn(Transition $t) => ['key' => $t->key, 'from' => $t->from->value, 'to' => $t->to->value],
        $transitions
    );

    WorkflowCache::put(static::class, 'transitions', $payload);

    return $transitions;
}
```

Use in `HasStates::states()`:

```php
public static function states(): array
{
    static::bootIfNotBooted();

    $cached = WorkflowCache::get(static::class, 'states');
    if ($cached !== null) {
        $class = static::$statesEnum[static::class] ?? null;
        return $class
            ? array_combine(
                array_keys($cached),
                array_map(fn($value) => $class::from($value), $cached)
            )
            : [];
    }

    if (!isset(static::$states[static::class])) {
        return [];
    }

    WorkflowCache::put(
        static::class,
        'states',
        array_map(fn($enum) => $enum->value, static::$states[static::class])
    );

    return static::$states[static::class];
}
```

Artisan command (`flowra:cache:clear`):

```php
public function handle(): int
{
    foreach (config('flowra.workflows', []) as $workflow) {
        WorkflowCache::forget($workflow);
        $this->info("Cleared cache for {$workflow}");
    }

    return self::SUCCESS;
}
```

Implementation plan:

1) Add config flags to `config/flowra.php` and document env vars.
2) Create `Support/WorkflowCache` helper.
3) Wire cache reads/writes into `HasTransitions::transitions()` and `HasStates::states()`, preserving current static
   arrays as the source on first build.
4) Add `flowra:cache:clear` artisan command to invalidate per-workflow cache.
5) (Optional) Add `flowra:cache:warm` to iterate `appliedWorkflows()` and populate cache at deploy time.
