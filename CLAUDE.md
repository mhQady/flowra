# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Flowra (`mhqady/flowra`) is a Laravel package — a database-driven workflow / state machine engine. It targets PHP 8.3+ and Laravel 12/13. There is no application here; the package is developed and tested standalone via Orchestra Testbench.

## Role

You are a senior Laravel package maintainer, open-source reviewer, security engineer, and software architect.

## Commands

```bash
composer install

# Run all tests (Pest)
composer test                # alias for vendor/bin/pest --colors=always
vendor/bin/pest

# Run a single test file
vendor/bin/pest tests/Feature/Console/ExportWorkflowDiagramTest.php

# Run tests matching a name
vendor/bin/pest --filter="exports mermaid"
```

There is no linter or static analysis tool configured.

Tests run against in-memory SQLite configured in `tests/TestCase.php`, which boots `FlowraServiceProvider` and loads the package migrations from `src/database/migrations/`. `tests/Pest.php` binds `Tests\TestCase` to everything under `tests/Feature`.

Releases are automated with release-please (`.github/workflows/release-please.yml`) on pushes to `main`, so commit messages should follow Conventional Commits (`feat:`, `fix:`, ...). Day-to-day work happens on the `dev` branch.

## Architecture

### Two sides of the package

1. **Model side** — a host app's Eloquent model uses the `Flowra\Concretes\HasWorkflow` trait (note: it lives in `Concretes`, not `Traits`, despite what the README shows), implements `HasWorkflowContract`, and lists workflow classes in `protected static array $workflows`. The trait composes:
   - `WorkflowAware` — registers a `WorkflowCast` attribute cast per workflow, so `$order->orderWorkflow` (camelCase of the class basename) returns a hydrated workflow instance bound to that model.
   - `HasWorkflowRelations` — uses `resolveRelationUsing()` to register `{alias}Status` (morphOne) and `{alias}Registry` (morphMany) relations per workflow, plus generic `statuses()` / `registry()`.
   - `HasWorkflowScopes` — query scopes (`whereCurrentStatus`, etc.) plus per-workflow builder macros like `whereOrderWorkflowCurrentStatus(...)` that accept enum cases, values, or state-group names.

2. **Workflow side** — workflow classes extend `Flowra\Concretes\BaseWorkflow` and define `transitionsSchema(): array` returning `Transition::make(key, from, to)->guard(...)->action(...)` DTOs. **States are resolved by naming convention**: for `OrderWorkflow`, `HasStates::resolveStatesEnum()` requires an enum named `OrderWorkflowStates` in the same namespace. The states enum uses the `Flowra\Enums\BaseEnum` trait and may define a static `groups(): array` of `StateGroup` DTOs.

### Custom boot/initialize system

`BaseWorkflow` mimics Eloquent's trait bootstrapping via `Traits/Support/Bootable`: static `boot{TraitName}()` runs once per concrete class, `initialize{TraitName}()` runs per instance. When adding a trait to `BaseWorkflow`, hook into this convention rather than the constructor.

### Definition caching (two layers)

Workflow definitions (transitions, states, state groups) are memoized in static properties per workflow class, and optionally persisted forever through `Support/WorkflowCache` into a Laravel cache store (keys `flowra:workflow:{class}:{key}`). Controlled by `flowra.cache_workflows` / `flowra.cache_driver` config. Transitions handed to callers are always **clones** of the cached ones (`HasTransitions::cloneTransitions()`) so per-use state like `appliedBy`/`comments` doesn't leak between uses.

### Transition lifecycle

`$model->orderWorkflow->process` resolves a `Transition` via `__get` (key names are normalized to snake_case) and binds the workflow to it. `->apply()` then runs `CanApplyTransitions::apply()`:

1. Evaluate guards (`CanEvaluateGuards`) — throws `GuardDeniedException` on denial.
2. Validate structure — model exists, workflow is registered on the model, transition key is defined, and the model's current state matches the transition's `from` (a model with no status yet is treated as being at `from`).
3. Inside a `DB::transaction`: upsert the `Status` row (current state, one per owner+workflow) and append an immutable `Registry` row (history).
4. Rehydrate `currentStatus` / `currentState`, then run actions (`CanExecuteActions`) **after** persistence.

`jumpTo()` bypasses the schema for forced state changes (recorded with a different `TransitionTypesEnum` type). `BaseWorkflow::applyMany()` delegates bulk operations to `Services/BulkTransitionService`, which returns a `BulkTransitionResult`.

Guards and actions each accept closures, class-strings, or instances (`GuardContract` / `ActionContract`).

### Persistence

Two tables (names configurable in `src/config/flowra.php`): `statuses` (current state per owner+workflow) and `statuses_registry` (append-only audit log). Both are polymorphic on `owner` and store the workflow FQCN in a `workflow` column. Migration lives at `src/database/migrations/create_flowra_tables.php`.

### Console commands & supporting pieces

- Commands live in `src/Console` with signatures `flowra:make-workflow`, `flowra:make-guard`, `flowra:make-action`, `flowra:export-workflow`, `flowra:import-workflow`, `flowra:cache:warm`, `flowra:cache:clear`. **Only the make-\* and export commands are currently registered** in `FlowraServiceProvider`; import and cache commands are commented out there (the README lists them all, plus a `flowra:list-workflow` that doesn't exist yet).
- Generators use stubs from `src/stubs` (publishable to `stubs/flowra`).
- Mermaid/PlantUML diagram logic is in `Support/WorkflowDiagramExporter` and `Support/WorkflowDiagramImporter`.
- User-facing exception messages come from `lang/en/flowra.php` via the `flowra::` translation namespace — add new messages there rather than hardcoding strings.

### Design notes

`notes/workflow-cache-plan.md` and `src/bulk-transition-plan.md` are planning documents for the caching and bulk-transition features; useful background when modifying those areas.
