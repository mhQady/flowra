# Flowra — Production-Grade Package Audit

> **Audited:** 2026-07-11 at `dev` (e9cd201) · ~3,950 LOC in `src/` · PHP 8.4.7 local · Laravel 12.56 in vendor
> **Overall score: 38 / 100** — not production-ready yet, but fixable. See [Final Verdict](#final-verdict).
>
> **How to use this file:** every finding has a checkbox. Work through Phase 1 → 2 → 3 in the
> [Refactoring Plan](#prioritized-refactoring-plan). Tick the finding *and* its plan entry when done.
> Re-run the test suite after each phase.

---

## Executive Summary

Flowra has a genuinely good idea at its core — DB-persisted workflow state with typed enum states,
guards, actions, groups, and diagram round-tripping — and the single-model happy path
(`$order->orderWorkflow->process->apply()`) is coherently designed. The DTO-based transition schema,
the clone-before-use strategy for cached transitions, and the dual `statuses` / `statuses_registry`
(current + audit) table design are sound decisions.

However, **the package is not production-ready in its current state**. The audit found: a fatal
facade alias pointing to a class that does not exist; PHP 8.4-only syntax in a package that declares
`php: ^8.3`; a core advertised feature (querying by a state that belongs to a group) that silently
returns wrong results; bulk-transition macros that reference an undefined variable and silently drop
user arguments; a guard API (`GuardDecision`) that is shipped but ignored, so `GuardDecision::deny()`
is treated as *allowed*; a persistent forever-cache enabled **by default** whose clear/warm commands
are commented out of the service provider; and a test suite in which **6 of 7 tests fail on a clean
checkout** — with no CI running tests. There is also an accidentally committed 180 KB `package.xml`
(xdebug's PEAR manifest) shipped in every dist install, and no LICENSE file despite declaring MIT.

None of these are hard to fix. But together they indicate that releases are being tagged without a
working verification loop, which is the single most important thing to change.

### Score breakdown

| Dimension | /10 | Rationale |
|---|---|---|
| Reliability | 3 | Broken facade, broken group queries, broken bulk macros, guard-decision bypass, 8.3 parse error |
| Stability | 3 | 6/7 tests fail; no test CI; forever-cache with no registered invalidation |
| Maintainability | 5 | Small, mostly cohesive classes; heavy commented-out dead code, `__`-prefixed methods, README/tests/code drift |
| Readability | 6 | Naming is mostly clear; trait decomposition is understandable |
| Extensibility | 4 | No events, hard-coded model classes, marker-only contracts, convention-locked states enum |
| Scalability | 4 | Per-instance query on hydration (N+1 by design), 3 queries + 1 transaction per row in bulk |
| Performance | 4 | Same as above; eager-loaded relations exist but are never used by the engine |
| Security | 6 | No injection vectors; guard-ordering and `GuardDecision` bypass are authorization-adjacent defects |
| Developer Experience | 5 | Fluent API is pleasant; typo'd transitions return `null`, README uses wrong import path, guard stub doesn't compile cleanly |

Scoring method: started from 100; −10 per Critical (capped), −3 per High, −1 per Medium,
floor-adjusted for strengths (clean core lifecycle, good schema design, working generators/exporter).

---

## Critical Issues

### - [ ] C1. Facade alias points to a class that does not exist — fatal error
**Where:** `composer.json:47`
`"Flowra": "Flowra\\Facades\\Flowra"` is registered, but there is **no `src/Facades` directory** and
the class exists nowhere. Package discovery registers the alias lazily; the moment any app code,
tinker session, or third-party package touches `\Flowra::…`, PHP fatals with
"Class 'Flowra\Facades\Flowra' not found".
**Fix (Low effort):** either create the facade (with a bound service behind it) or delete the alias
block. There is currently no container binding for it to proxy to, so deleting is the honest fix
until a `Flowra` manager service exists.

### - [ ] C2. PHP 8.4-only syntax in a package declaring `php: ^8.3`
**Where:** `src/Console/ImportWorkflowDiagram.php:94`
```php
$parsed = new WorkflowDiagramImporter()->parse($diagram, $format);
```
Method chaining on `new` without wrapping parentheses is a **PHP 8.4 feature**. On PHP 8.3 this file
fails to *compile* — a `ParseError` the moment the class is autoloaded. It works locally because the
dev machine runs 8.4.7.
**Fix (Low):** `(new WorkflowDiagramImporter())->parse(...)`, and add a CI matrix leg for PHP 8.3.

### - [ ] C3. Querying by a grouped state silently returns wrong results
**Where:** `src/Traits/HasWorkflowScopes.php:309-315` (`expandStateForWorkflow`)
For a state that *belongs to* a group, the code returns the **parent group's key**:
```php
$parent = $workflowClass::stateParentGroup($state);
if ($parent) {
    return [$parentState['value'] ?? ...];  // returns the GROUP key
}
```
The database `to` column only ever stores actual enum case values
(`CanApplyTransitions.php:151`), never group keys. With the README's own example (`active` group
containing `PENDING`), the flagship query `Order::whereOrderWorkflowCurrentStatus(OrderWorkflowStates::PENDING)`
becomes `WHERE to IN ('active')` and **matches zero rows, silently**. Group-name → children expansion
(the other direction) is correct; the parent expansion is backwards.
**Fix (Low):** a concrete state must always expand to *itself*: `return [$value];`. Delete the parent
branch. Add a regression test with grouped states.

### - [ ] C4. Bulk transition macros reference an undefined variable and drop all arguments
**Where:** `src/Traits/HasWorkflowScopes.php:205` and `:223`
Both the `Builder` and `Collection` `apply{Alias}Transition` macros accept
`$appliedBy, $comments, $continueOnError, $chunk` and then call:
```php
return BulkTransitionService::for($class)->apply($collection, $transition, $options);
```
`$options` is never defined. PHP 8 emits an "Undefined variable" warning and passes `null` — meaning
**every user-supplied argument is silently ignored**: no `appliedBy` audit attribution, no comments,
`continueOnError` always false, no chunking. Leftover from the earlier `BulkTransitionOptions` design
(see commented block above it and `src/bulk-transition-plan.md`).
**Fix (Low):** pass the actual parameters. Add a test asserting `applied_by` lands in the registry
when using the macro.

### - [ ] C5. `GuardDecision` is shipped but ignored — `deny()` is treated as *allowed*
**Where:** `src/DTOs/GuardDecision.php` (unused) · `src/Traits/Workflow/CanEvaluateGuards.php:21-25`
```php
$res = $instance instanceof GuardContract ? $instance->allows($t) : $instance($t);
if ($res === false) { throw new GuardDeniedException(...); }
```
A closure guard returning `GuardDecision::deny('not owner')` is a truthy object → **the transition
proceeds**. Authorization-bypass footgun baked into the public API surface. Secondary issues: the
guard's denial reason is discarded (hard-coded English message, not run through `lang/`), and
`null`/`0` returns also pass.
**Fix (Low/Medium):** either delete `GuardDecision` for 1.0, or support it properly: treat anything
that is not `true` / `GuardDecision::allow()` as denial (fail-closed), and surface `message`/`code`
in `GuardDeniedException`.

### - [ ] C6. The test suite fails on a clean checkout — and no CI runs it
**Verified by execution: 6 failed, 1 passed** (the passing one is `expect(true)->toBeTrue()`).
- `tests/Feature/Console/GenerateWorkflowTest.php` asserts a `contexts` table that never existed in this package.
- `tests/Feature/Console/ExportWorkflowDiagramTest.php:3` imports `Flowra\Flows\MainWorkflow\MainWorkflow` — a class that exists nowhere in the repo (from the author's host application).
- `tests/Feature/Console/ImportWorkflowDiagramTest.php` calls a command that is commented out of the provider and passes an `--output` option the command doesn't define.

The only GitHub workflow is release-please — so releases (v0.1.9 tagged) ship while the suite is red.
**Fix (Medium):** add workbench fixture workflows under `workbench/`, rewrite the console tests
against them, delete the stale generate test, and add a `tests.yml` workflow
(PHP 8.3/8.4 × Laravel 12/13 matrix) required before release-please.

### - [ ] C7. Forever-cache on by default, invalidation commands not registered
**Where:** `src/config/flowra.php:27` (`cache_workflows` defaults `true`, driver `database`) ·
`src/Support/WorkflowCache.php:54` (`forever()`) · `src/FlowraServiceProvider.php:24-26`
(`flowra:cache:clear` / `flowra:cache:warm` commented out).
Consequences in production:
1. Deploying a changed `transitionsSchema()` keeps serving the **stale cached definition** with no built-in way to clear it.
2. Transitions containing closures can't be serialized; the `catch` in `remember()` silently skips caching — behavior differs invisibly between closure-based and class-based workflows.
3. If the app's `cache` table is missing, `remember()`'s catch calls `$store->forget()` which throws the same `QueryException` **uncaught** (`WorkflowCache.php:43-46`).

**Fix (Medium):** default `cache_workflows` to `false` (or `cache_driver` to `null` = app default
store), register both commands, hash the schema into the cache key (self-invalidating), and make the
recovery path exception-safe.

---

## High Priority Issues

### - [ ] H1. TOCTOU race in transition application — no locking
Current-state validation (`CanApplyTransitions.php:95`) reads the status **outside** the write
transaction (`:61-69`). Two concurrent requests can both validate `pending → processing`, both
commit; `updateOrCreate` may also race on the unique index and surface a raw `QueryException`. The
registry then contains two contradictory rows and the last write wins.
**Fix (Medium):** move validation inside the transaction with `lockForUpdate()` on the status row,
and map unique-violation to `ApplyTransitionException`.

### - [ ] H2. `jumpTo()` leaves the in-memory state stale
`CanApplyTransitions.php:40-52`: `apply()` calls `hydrateStates($status)` after saving; `jumpTo()`
does not. After a jump, `$workflow->currentState` still reports the pre-jump state, so a subsequent
`apply()` on the same instance validates against a stale state. Also undocumented: jumps skip guards
and actions entirely.
**Fix (Low):** hydrate after save; document jump semantics.

### - [ ] H3. Guards run before the transition is even validated
`apply()` evaluates guards first, then `validateTransitionStructure()`. Guards (which may hit the DB,
call services, have side effects) execute for transitions that are unregistered or not applicable
from the current state.
**Fix (Low):** swap the two calls — cheap structural validation → guards → persist.

### - [ ] H4. Config copy-paste bug: namespace reads the *path* env var
`src/config/flowra.php:19`: `'workflows_namespace' => env('FLOWRA_WORKFLOWS_PATH', 'App\\Workflows')`.
Setting `FLOWRA_WORKFLOWS_PATH=app/Custom` silently corrupts the namespace to `app/Custom`, breaking
`flowra:export-workflow` resolution and `WarmWorkflowCache`.
**Fix (Low):** `FLOWRA_WORKFLOWS_NAMESPACE`.

### - [ ] H5. `Status::comment` accessor throws on NULL and duplicates a native cast
`src/Models/Status.php:27-33`: `json_decode($value, true, 512, JSON_THROW_ON_ERROR)` — the column is
`nullable()`, and `json_decode(null)` is coerced to `""` (with a deprecation) which throws
`JsonException: Syntax error`. Any legacy/manual row with NULL comment makes reads explode.
`Registry.php:27` has the same accessor *without* the throw flags — inconsistent behavior between the
two models for identical data.
**Fix (Low):** delete both accessors; use `protected $casts = ['comment' => 'array'];` (and cast
`type` to `TransitionTypesEnum` while at it).

### - [ ] H6. Bulk service silently mis-binds already-bound transitions
`src/DTOs/Transition.php:84-89`: `workflow()` no-ops when the readonly `$workflow` is already
initialized. `BulkTransitionService.php:169-177` clones the user-passed transition and calls
`workflow($workflow)` — but if the user passed a *bound* transition (e.g.
`$order->orderWorkflow->process`, the most natural thing to grab), every clone keeps the **original
model's** workflow. State changes go to the right target, but every guard and action calling
`$t->appliedOnModel()` sees the wrong model for every target. Silent, data-corrupting for side effects.
**Fix (Medium):** make binding explicit — add `withWorkflow()` returning a rebound copy, or throw
when re-binding instead of silently ignoring.

### - [ ] H7. `applied_by` column type contradicts the API
Migration uses `foreignId('applied_by')` (unsigned bigint) (`create_flowra_tables.php:42`) while the
whole API accepts `int|string|null` — string/UUID user IDs fail at the DB layer. Also, `foreignId`
without `constrained()` is misleading naming for a plain column.
**Fix (Low, breaking — do before 1.0):** `$table->string('applied_by')->nullable()` (or make it
configurable/morphable).

### - [ ] H8. Migration `down()` has swapped fallback defaults
`create_flowra_tables.php:23-26`: `dropIfExists(config('flowra.tables.registry', 'statuses'))` and
`dropIfExists(config('flowra.tables.statuses', 'statuses_registry'))` — the defaults are crossed.
Harmless while config is merged, wrong the moment it isn't.

### - [ ] H9. `WorkflowCache::forgetAll()` is broken for Redis and fragile for database
`src/Support/WorkflowCache.php:112-141`: the Redis path scans `flowra:workflow:*:*` on the **default
Redis connection**, ignoring both the cache store's configured connection and Laravel's cache key
prefix (real keys look like `laravel_cache_flowra:workflow:...`), so it matches nothing. The database
path hardcodes the `cache` table (ignores `cache.stores.database.table`) and uses the `\DB` root
alias. Every other store (file, array, memcached) is silently unsupported.
**Fix (Medium):** keep a per-workflow key registry (one known cache key listing cached workflow
FQCNs) so `forgetAll` works store-agnostically.

### - [ ] H10. Repository ships junk and lacks a license file
- `package.xml` (180 KB — **xdebug's PEAR manifest**, committed by accident) is tracked and not export-ignored → shipped in every `composer require`.
- No `LICENSE` file despite `"license": "MIT"` — MIT requires the license text to accompany the software.
- `.gitattributes` doesn't export-ignore `phpunit.xml`, `workbench/`, or `src/bulk-transition-plan.md` (an internal planning doc shipped inside `src/`).
**Fix (Low):** delete `package.xml`, add `LICENSE`, move the plan doc to `notes/`, extend `.gitattributes`.

### - [ ] H11. `guard.stub` generates code that doesn't compile cleanly
`src/stubs/guard.stub` imports `Flowra\Flows\BaseWorkflow` — a namespace that does not exist anywhere
in the package (stale from an old structure). Generated guards carry a bogus import, and the empty
`allows(): bool` body returns null → `TypeError` if used unmodified.

### - [ ] H12. Pure (non-backed) enums fatal despite `UnitEnum` type hints
The API accepts `UnitEnum` everywhere (`Transition.php:28`), but `HasStates` calls `::tryFrom()` and
`->value` unconditionally (`HasStates.php:60`, `CanApplyTransitions.php:95`). A pure enum states
class → `Error: Call to undefined method ...::tryFrom()`. Also `tryFrom($status?->to)` passes `null`
→ deprecation on every hydration of an unstarted workflow.
**Fix (Low):** require `BackedEnum` explicitly (constructor assertion + type hints), and guard the
null: `$status?->to !== null ? ...::tryFrom($status->to) : null`.

---

## Medium Priority Issues

- [ ] **M1. Typo'd transition names return `null`, not an error.** `BaseWorkflow::__get` (`BaseWorkflow.php:50-57`) returns `?Transition`; `$order->orderWorkflow->proccess->apply()` becomes "Call to a member function apply() on null" far from the typo. Throw an `UnknownTransitionException` listing valid keys instead.
- [ ] **M2. Alias collisions are silent.** Casts, relations, and global Builder macros are all keyed by `class_basename` camel/pascal — two workflows named `OrderWorkflow` in different namespaces silently overwrite each other's casts/macros (`WorkflowAware.php:46`, `HasWorkflowScopes.php:104`). Detect and throw.
- [ ] **M3. Global Builder macros for model-specific behavior.** `whereOrderWorkflowCurrentStatus` is registered on *every* builder; calling it on an unrelated model produces a confusing scope-not-found error. Local scopes or a dedicated builder class would be cleaner.
- [ ] **M4. Contracts don't contract.** `HasWorkflowContract` is empty while the engine calls `appliedWorkflows()`, `getMorphClass()`, `getKey()` on it; `BaseWorkflowContract` exists but `BaseWorkflow` doesn't implement it (only stub-generated classes do). Put the real methods in the interfaces.
- [ ] **M5. `BaseEnum` is app code leaked into a package** (`src/Enums/BaseEnum.php`): `__('enum.'.$baseName...)` assumes a host-app `enum.php` lang file; `\Str` root alias; generic `\Exception('Invalid key')`; `rand()`; loose `==` comparisons; untyped returns. Trim to `values()`/`keys()` or move labeling behind the package's own translation namespace.
- [ ] **M6. Root-namespace aliases** (`use Str;` in `WorkflowAware.php:10`, `HasWorkflowRelations.php:9`, `\Str`/`\DB` elsewhere) depend on the host app's alias config. Packages must import `Illuminate\Support\Str` directly.
- [ ] **M7. No events.** `TransitionApplying` / `TransitionApplied` / `WorkflowJumped` events are the Laravel-native extension point (listeners, queued side effects, broadcasting) and would let actions become optional sugar rather than the only hook.
- [ ] **M8. Models are hard-coded.** `Status`/`Registry` can't be swapped (no `flowra.models.*` config), so apps can't add tenancy scopes, casts, or observers.
- [ ] **M9. `status()`/`registry()` ignore eager-loaded relations** (`BaseWorkflow.php:32-48`) — see [Performance](#performance-findings).
- [ ] **M10. Exporter emits invalid Mermaid comments.** `WorkflowDiagramExporter.php:119`: `sprintf('    %% %s', ...)` collapses `%%` to a single `%` — Mermaid comments require `%%`, so exported diagrams contain an invalid line (the fixture `tests/Fixtures/main-workflow-diagram.mmd` carries the bug: `% Flowra\Flows\...`). Some renderers reject the file.
- [ ] **M11. Command inconsistencies:** `WarmWorkflowCache` force-prefixes the configured namespace so FQCNs can't be warmed, while `ClearWorkflowCache` expects FQCNs; `flowra:list-workflow` (README) doesn't exist; import/clear/warm are commented out of the provider while the README advertises all of them.
- [ ] **M12. `phpunit.xml` coverage source points at `app/`** — a directory that doesn't exist here; should be `src/`.
- [ ] **M13. Dead code everywhere:** commented `JsonSerializable` in Transition, ~60 commented lines in `registerTransitionMacros`, event bus in `Bootable`, commented provider registrations, commented config keys, `{{ flow_key }}`/`{{ flow_title }}` replacements no stub uses. Delete — git remembers.
- [ ] **M14. `__`-prefixed private methods** (`__executeActions`, `__resolveDiagramInput`, `__inferstatesEnum`…) — PHP reserves the `__` prefix for magic methods; also `__inferstatesEnum` casing, `creatWorkflowDirectory` typo.
- [ ] **M15. `0777` directory permissions** in all generators/exports — should be `0755`.
- [ ] **M16. Hard-coded English exceptions** (`GuardDeniedException`, the jump message in `CanApplyTransitions.php:130`) while sibling messages go through `lang/` — inconsistent i18n.
- [ ] **M17. `TransitionTypesEnum` stored as bare int** (`type` column) but exposed as `int $type` on the DTO, not the enum; no cast on the models.

---

## Low Priority Improvements

- [ ] **L1.** Add `declare(strict_types=1)` across the package (currently **zero** files have it).
- [ ] **L2.** `BulkTransitionResult` could expose `successfulTargets()`, `failedTargets()`, `throwIfFailed()` conveniences.
- [ ] **L3.** `Transition::comment()`/`appliedBy()` carry `//TODO` markers — resolve or track in issues, don't ship TODOs in the hot path.
- [ ] **L4.** `StateGroup` supports nested groups structurally but nothing validates cycles or multi-parent membership; document or validate.
- [ ] **L5.** `registry()` results are unordered — add explicit `orderBy('created_at')` for a deterministic audit trail, and an index on `(owner_type, owner_id, workflow)` for the registry table.
- [ ] **L6.** `Status` rows are silently overwritten via `updateOrCreate` — consider storing `previous_status_id`/version for optimistic concurrency later.
- [ ] **L7.** `.gitignore` `/notes` entry vs the tracked `src/bulk-transition-plan.md` — pick one home for planning docs.
- [ ] **L8.** Emoji-heavy console output is noisy for CI logs; consider `components->info()` and `--quiet`-friendly output.
- [ ] **L9.** README "1. Publish Assets" implies publishing is required; migrations could also be auto-loadable via `loadMigrationsFrom()` with publishing optional.

---

## Security Findings

No remotely exploitable vulnerability was found — the package's inputs are developer-controlled
(workflow definitions, console arguments). In order of weight:

1. **Authorization-adjacent (highest):** the `GuardDecision::deny()` bypass (C5) and non-strict guard evaluation. Guards are this package's authorization layer; evaluation must fail closed (only explicit `true`/`allow()` proceeds).
2. **Deserialization surface:** cached `Transition`/enum payloads are `unserialize()`d from the cache store on every boot. If an attacker can write to shared cache (real scenario in shared Redis setups), they get object injection into every request. Standard Laravel-cache trade-off, but storing plain arrays (as `notes/workflow-cache-plan.md` originally proposed) would eliminate it.
3. **SQL:** all persistence goes through the query builder with bindings; table names come from config (developer-controlled). No injection found.
4. **Filesystem:** generators write inside `base_path()`/`storage_path()` with dev-supplied names; `--output`/`--path` accept arbitrary paths but only in artisan context. `0777` perms (M15) are the only hygiene issue.
5. **Mass assignment:** `$guarded = ['id']` on `Status`/`Registry` is acceptable since attribute arrays are package-constructed; explicit `$fillable` would be stricter.
6. **Information disclosure:** exception messages embed FQCNs and model classes — fine for server logs; nothing user-facing by default.
7. No `eval`/`exec`/shell usage anywhere. `app($class)` instantiation of guard/action class-strings is developer-defined configuration, not user input — acceptable.

---

## Performance Findings

1. **N+1 by design:** every `$model->orderWorkflow` access hydrates a workflow whose constructor chain (`initializeHasStates` → `hydrateStates` → `status()`) fires a query — per model, even when `orderWorkflowStatus` was eager-loaded. The relations exist (`HasWorkflowRelations.php:29`); the engine never consults `$this->model->relationLoaded(...)`. **Fix: prefer the loaded relation in `status()` — the single biggest win.**
2. **Bulk isn't bulk:** `applyMany` runs per row: status read (via validation), status upsert, registry insert, each in its own transaction — ~3-4 queries/row. For thousands of rows: preload statuses per chunk (one `whereIn`), wrap chunks in one transaction, batch-insert registry rows.
3. **Eager clone of the whole transition map** per workflow instantiation (`initializeHasTransitions`) — pay for all transitions even when accessing one. The lazy path in `accessCachedTransitionAsProperty` already handles the empty case; drop the eager clone.
4. **`registry()`** loads the full history unbounded — offer a query-builder-returning variant.
5. Static memoization of states/transitions/groups is well done — per-process cost is one build per workflow class. 👍

---

## Architecture Review

**Strengths**
- Clear separation between definition (`Transition` DTOs, states enum), engine (`BaseWorkflow` + workflow traits), persistence (two thin models), and integration (model traits + cast). The cast-based hydration (`$order->orderWorkflow`) is an elegant Laravel-native trick.
- Status/Registry split (current state vs append-only audit) is the right persistence model.
- Clone-on-access for cached transitions correctly prevents per-use state leaking between consumers.
- `Bootable` is a faithful, compact reimplementation of Eloquent's trait bootstrapping.

**Weaknesses**
- **Convention lock-in without escape hatch:** the `{Workflow}States` enum resolution (`HasStates.php:105-114`) is convention-only; a `protected static string $statesEnum` override would cost three lines and remove the constraint.
- **Interfaces are decorative** (empty marker, unimplemented contract) — dependency inversion in name only. The engine depends on concrete `Status`/`Registry`, concrete cache facade, concrete config keys.
- **Trait explosion over composition:** `BaseWorkflow` is assembled from 6 traits with interdependencies. A `WorkflowDefinition` value object (states + transitions + groups) held by the workflow would make dependencies explicit, kill most static-property caching complexity, and make the cache layer trivially serializable.
- **Two competing entry points** for bulk (service fluent API vs `applyMany` vs macros) with drifted signatures — the macro layer already broke (C4) because the same signature is maintained in four places.
- Namespace oddities: `Concretes\HasWorkflow` is a *trait* in a folder named for concrete classes; README says `Traits\HasWorkflow`. Move it to `Traits/` with a class alias for BC.

---

## Laravel Best Practices Review

| Area | Verdict |
|---|---|
| Package discovery, config merge/publish, translations namespace | ✅ Correct (`AboutCommand` integration is a nice touch) |
| Migration publishing | ⚠️ Publish-only; consider `loadMigrationsFrom` + publishable override |
| Facades | ❌ Alias to nonexistent class (C1) |
| Commands | ❌ Half the advertised commands unregistered |
| Events | ❌ None — the biggest missing Laravel-native extension point |
| Casts | ✅ Clever use of `CastsAttributes` for hydration; ⚠️ hand-rolled JSON accessor instead of `'array'` cast |
| Macros | ⚠️ Global builder macros for per-model behavior; broken `$options` |
| Container | ⚠️ `App::make($class, ['model' => $this])` works, but nothing is bound/singleton'd; no manager service behind the (missing) facade |
| Testing (Testbench/Pest) | ❌ Present but broken; `workbench/` unused |
| `env()` outside config | ✅ Only in config files — correct |
| Root aliases (`\Str`, `\DB`) | ❌ Anti-pattern in packages |

---

## Public API Review

**Good:** `Transition::make('process', From, To)->guard(...)->action(...)` reads beautifully; magic
transition properties are discoverable and terse; the dynamic relation/scope naming feels like
first-party Laravel; the fluent bulk service is nicely designed.

**Friction:**
- Typo'd transition → `null` (M1) is the worst DX moment.
- `apply()` returning the workflow (not a result) hides what happened; a `TransitionResult` or at least the fresh `Status` would be more informative.
- Guards can't explain themselves (message/code discarded).
- The `HasWorkflow` trait's documented import path is wrong in the README — the first integration step fails.
- **No `canApply('process')` / `availableTransitions()` API** — every UI building on this needs "which transitions are possible right now?", and today the only way is try/catch around `apply()`. The most valuable missing feature for 1.0.
- `jumpTo` semantics (skips guards/actions) undocumented.

---

## Testing Review

- **Current state: 6/7 failing, effectively zero coverage.** Nothing tests the engine — no test touches `apply()`, guards, actions, jumps, groups, scopes, relations, casts, bulk, or caching. `tests/Feature/Workflow/` is empty.
- Fixtures reference the author's host app (`Flowra\Flows\MainWorkflow\MainWorkflow`), so tests can't pass outside that machine's context.

**Minimum viable suite for 1.0:**
- [ ] Workbench `OrderWorkflow` fixture (workflow + states enum + groups + a test model)
- [ ] Happy-path transition (status row, registry row, hydrated state)
- [ ] Wrong-from-state rejection · unknown transition rejection
- [ ] Guard deny: bool `false`, `GuardDecision::deny`, closure (would have caught C5)
- [ ] Action execution order + after-commit semantics
- [ ] Jump + rehydration (would have caught H2)
- [ ] Group query expansion both directions (would have caught C3)
- [ ] Each macro incl. arguments (would have caught C4)
- [ ] Bulk `continueOnError` both ways · chunking
- [ ] Cache on/off parity tests
- [ ] Command generation snapshots (make-workflow/guard/action, export, import)
- [ ] CI: PHP 8.3 + 8.4 × Laravel 12 + 13 matrix (would have caught C2)
- [ ] Pint + Larastan in CI

---

## Documentation Review

- README is well-structured but **drifted**: wrong trait import path (`Flowra\Traits\HasWorkflow` doesn't exist), advertises 3 commands that aren't registered and 1 that doesn't exist, documents group-querying behavior that is currently broken (C3).
- Missing: LICENSE file, CHANGELOG (wire release-please to generate it), CONTRIBUTING, upgrade guide, `jumpTo`/bulk/caching sections, guard/action reference (GuardDecision documented nowhere — fitting, since it's ignored), troubleshooting (cache table requirement).
- Docblocks in `Support/` and `Console/` are good; engine traits thinner; several docblocks wrong (`@return string` on array-returning method at `HasWorkflowScopes.php:253`, `string<UnitEnum>` pseudo-type in HasStates).

---

## Composer Review

- ✅ PSR-4, sensible keywords, `illuminate/support ^12||^13`, Testbench `^10||^11` aligned, Pest 4, `prefer-stable`.
- ❌ Facade alias to missing class (C1). ❌ `php: ^8.3` contradicted by 8.4 syntax (C2).
- ⚠️ `branch-alias` says `dev-main: 0.x-dev` but development happens on `dev` — add `dev-dev` alias or drop the block.
- ⚠️ Depend on what you use: the package also uses `illuminate/database`, `illuminate/console`, `illuminate/cache`, `illuminate/filesystem` — list them explicitly.
- ⚠️ Add `"scripts": {"lint": "pint", "analyse": "phpstan"}` once tooling lands.
- ❌ Dist bloat: `package.xml`, `phpunit.xml`, `workbench/`, `src/bulk-transition-plan.md` all ship (H10).

---

## Static Analysis Findings

PHPStan level max / Larastan would flag today (all verified by reading):

1. `$options` undefined — `HasWorkflowScopes.php:205,223` (level 0!).
2. Calling `appliedWorkflows()`, `getMorphClass()`, `getKey()`, `->exists` on empty `HasWorkflowContract` — unknown-method errors throughout `CanApplyTransitions`, `BaseWorkflow`.
3. `$this->model?->exists` — nullsafe on non-nullable readonly property (`CanApplyTransitions.php:74`).
4. `empty($this->workflow)` on possibly-uninitialized readonly property (`Transition.php:86`).
5. `tryFrom(null)` nullability (`HasStates.php:60`); `::tryFrom`/`->value` on `UnitEnum` (not guaranteed `BackedEnum`).
6. `HasStateGroups::buildStateGroupCache` calls `static::cacheStates()` — defined in a *different* trait, invisible without `@mixin`/interface.
7. Invalid PHPDoc types: `string<UnitEnum>`, `@return string` for array returns, malformed `@param $guards` (`Transition.php:17`).
8. `BaseEnum`: missing return types, `==` loose comparisons, `\Str`/`\Exception` unresolved without app aliases.
9. `Registry::comment` set-accessor `json_encode` can return `false` → `string|false` mismatch.
10. Console: `$this->option('force')` `bool|string|array|null` passed to `bool` params without cast (`ImportWorkflowDiagram.php:69,78`).
11. `strtr()` receiving a `Stringable` value in replacements (`MakeWorkflow.php:45`).
12. `Transition::appliedOnModel` return type says `Model` but `$this->workflow->model` is `HasWorkflowContract`.

Pint violations include: double space in `class GuardDeniedException  extends`, `creatWorkflowDirectory` typo, inconsistent brace/blank-line style, `# comment` style in PHP code.

**Recommendation:** add `larastan/larastan` at level 6 first (fix the genuine bugs it finds), then ratchet. Add `laravel/pint`.

---

## Production Readiness Checklist

| Area | Status | Notes |
|---|---|---|
| Core transition lifecycle (single model) | ✅ | Works; needs locking (H1) |
| Guards fail-closed | ❌ | C5 |
| Group-state querying | ❌ | C3 |
| Bulk via service | ⚠️ | Works; mis-binding H6, partial-commit semantics undocumented |
| Bulk via macros | ❌ | C4 |
| Facade | ❌ | C1 |
| Declared PHP compatibility | ❌ | C2 |
| Concurrency safety | ❌ | H1 |
| Caching subsystem | ⚠️ | Works happy-path; invalidation unregistered (C7), forgetAll broken (H9) |
| Migrations | ⚠️ | Solid schema; `applied_by` type (H7), down() defaults (H8) |
| Error handling | ⚠️ | Good translated messages for transitions; guards/jumps hard-coded; no base exception |
| Events/extensibility | ❌ | No events, no model overrides |
| Tests | ❌ | 6/7 failing, engine untested |
| CI | ❌ | Release automation only, no test/lint/analysis gates |
| Documentation | ⚠️ | Good README, but drifted and incomplete |
| Licensing | ❌ | No LICENSE file |
| Dist hygiene | ❌ | package.xml + internal docs shipped |
| Composer metadata | ⚠️ | Facade alias, under-declared deps |
| Static analysis / code style | ❌ | No tooling configured |
| Security posture | ⚠️ | No injection vectors; guard semantics are the risk |

---

## Prioritized Refactoring Plan

### Phase 1 — Stop the bleeding (before any next tag) · ~2-3 days

- [ ] 1. Remove/implement facade alias (**C1**) — *Low*
- [ ] 2. Fix 8.4-only syntax; pin CI to 8.3+8.4 (**C2**) — *Low*
- [ ] 3. Fix `expandStateForWorkflow` parent branch + regression test (**C3**) — *Low*
- [ ] 4. Fix `$options` macros + test (**C4**) — *Low*
- [ ] 5. Fail-closed guard evaluation honoring `GuardDecision` (**C5**) — *Low/Med*
- [ ] 6. Delete `package.xml`, add LICENSE, fix export-ignore (**H10**) — *Low*
- [ ] 7. Register cache commands or default caching off (**C7**) — *Low*
- [ ] 8. Fix config env name (**H4**), `guard.stub` import (**H11**), migration down() (**H8**) — *Low*

### Phase 2 — Trustworthy engine · ~1-2 weeks

- [ ] 9. Rebuild test suite on workbench fixtures + GitHub Actions matrix (**C6**) — *Medium*
- [ ] 10. Transactional validation with `lockForUpdate` (**H1**) — *Medium*
- [ ] 11. `jumpTo` rehydration (**H2**), guard/validation order (**H3**) — *Low*
- [ ] 12. `comment` → `'array'` cast, `type` → enum cast (**H5**, **M17**) — *Low*
- [ ] 13. `applied_by` → string column (**H7**) — *Low* (breaking; do before 1.0)
- [ ] 14. Explicit transition binding in bulk service (**H6**) — *Medium*
- [ ] 15. Backed-enum enforcement + null-safe hydration (**H12**) — *Low*

### Phase 3 — 1.0 polish · ~2-3 weeks

- [ ] 16. `status()` uses eager-loaded relations; bulk preloads per chunk (**M9**, perf) — *Medium*
- [ ] 17. Throw on unknown transition; add `canApply()` / `availableTransitions()` (**M1**, API) — *Medium*
- [ ] 18. Events (`TransitionApplying/Applied`, `WorkflowJumped`) (**M7**) — *Medium*
- [ ] 19. Real contracts + configurable models (**M4**, **M8**) — *Medium*
- [ ] 20. Larastan L6 + Pint in CI; strict_types; delete dead code; rename `__`-methods (**M13**, **M14**, **L1**) — *Medium*
- [ ] 21. README overhaul + CHANGELOG wiring + upgrade guide — *Medium*
- [ ] 22. Store cache as plain arrays (rebuild DTOs on read) per `notes/workflow-cache-plan.md` — *High*

---

## Final Verdict

**Is this package production-ready?** No. Seven critical findings — several of which (facade fatal,
8.3 parse error, group-query correctness, guard bypass) will hit real users on their first day —
plus a red test suite mean current tags should be considered pre-alpha regardless of version number.

**Would I approve it for public release?** Not in this state. I *would* approve the design
direction: the DTO schema, cast-based hydration, and status/registry split are better thought out
than many published workflow packages. This is a fixable package, not a doomed one.

**Must fix before 1.0:** everything in Phases 1-2 — in particular C1-C7 verbatim, plus locking (H1),
the `applied_by`/comment schema decisions (breaking changes are cheap now and expensive later), and
a green CI matrix across PHP 8.3/8.4 × Laravel 12/13.

**Should improve before 2.0:** events, `availableTransitions()`/`canApply()` introspection,
configurable models, a `WorkflowDefinition` value object replacing static-property caching (which
also fixes cache serialization), true batch SQL for bulk operations, and versioned workflow
definitions (the "evolve at runtime" promise in the package description implies definition
versioning that doesn't exist yet).

**Overall assessment:** a promising ~4K-LOC engine with a genuinely nice developer-facing API,
currently undermined by an absent verification loop. The highest-leverage single change is not any
code fix — it's a CI pipeline that runs a real test suite on PHP 8.3, which would have caught
roughly half of the critical findings automatically. Fix the loop, then the bugs, and this can
credibly aim for the Laravel ecosystem shelf it's targeting.
