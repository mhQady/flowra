<?php

namespace Flowra\Support;

use ArrayIterator;
use Closure;
use Countable;
use Flowra\Concretes\BaseWorkflow;
use Flowra\Contracts\RegistryFilterContract;
use Flowra\Contracts\RegistryScopeContract;
use Flowra\DTOs\RegistryEntry;
use Flowra\DTOs\RegistryView;
use Flowra\Enums\RegistryActorEnum;
use Flowra\Enums\RegistryShapeEnum;
use Flowra\Models\Registry;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use IteratorAggregate;
use JsonSerializable;
use Traversable;
use UnitEnum;

/**
 * A deferred, composable read over one model's registry rows for one workflow.
 *
 * Nothing hits the database until a terminal call (get / paginate / count / iteration),
 * so the shape, the viewer and any extra conditions all compose first. Conditions that
 * can express themselves in SQL are pushed into the query; closures are applied in PHP
 * once rows come back.
 *
 * @implements IteratorAggregate<int, RegistryEntry>
 */
final class RegistryBuilder implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    private RegistryShapeEnum $shape;

    /** Phases this read collapses; null means every phase. @var array<int, string>|null */
    private ?array $collapseOnly;

    /** Phases this read leaves expanded. @var array<int, string> */
    private array $collapseExcept;

    /** @var array<int, Closure|RegistryFilterContract|RegistryScopeContract|class-string> */
    private array $conditions;

    private mixed $viewer = null;

    /** Who the entries render as; seeded from the view and overridable per read. */
    private int|string|Closure|RegistryActorEnum|null $appliedBy;

    /** Stand-ins for masked phases, keyed as declared; resolved through the phase map at read time. */
    private array $maskedPhases;

    /** Stand-ins for masked transitions, keyed by transition key. */
    private array $maskedTransitions;

    /** Relations eager-loaded on the rows; seeded from the view. @var array<int|string, string|Closure> */
    private array $with;

    /** Relations loaded on the rendered actor; null leaves the actor unresolved. @var array<int|string, string|Closure>|null */
    private ?array $actorWith;

    /** $with split into row relations and actor relations; rebuilt when with() changes. */
    private ?array $relationSplit = null;

    private ?RegistryAttribution $attribution = null;

    private string $direction = 'asc';

    /** @var array{scopes: array<int, RegistryScopeContract>, filters: array<int, Closure|RegistryFilterContract>}|null */
    private ?array $partitioned = null;

    public function __construct(
        private readonly BaseWorkflow $workflow,
        private readonly RegistryView $view,
    ) {
        $this->shape = $view->currentShape();
        $this->conditions = $view->conditions();
        $this->appliedBy = $view->appliedByResolver();
        $this->collapseOnly = $view->collapsedPhases();
        $this->collapseExcept = $view->expandedPhases();
        $this->maskedPhases = $view->maskedPhases();
        $this->maskedTransitions = $view->maskedTransitions();
        $this->with = $view->eagerLoads();
        $this->actorWith = $view->actorEagerLoads();
    }

    public function detailed(): self
    {
        $this->shape = RegistryShapeEnum::DETAILED;

        return $this;
    }

    /**
     * Collapse every phase, discarding whatever selection the view declared.
     */
    public function collapsed(): self
    {
        $this->shape = RegistryShapeEnum::COLLAPSED;
        $this->collapseOnly = null;
        $this->collapseExcept = [];

        return $this;
    }

    /**
     * Collapse only these phases; every other row stays a leaf.
     *
     * Adds to the view's own selection. Name a phase by its key or by any state
     * inside it. Implies the collapsed shape.
     */
    public function collapse(UnitEnum|string ...$phases): self
    {
        $this->shape = RegistryShapeEnum::COLLAPSED;
        $this->collapseOnly = array_merge(
            $this->collapseOnly ?? [],
            array_map(RegistryView::phaseKey(...), $phases)
        );

        return $this;
    }

    /**
     * Collapse every phase except these. Implies the collapsed shape.
     */
    public function dontCollapse(UnitEnum|string ...$phases): self
    {
        $this->shape = RegistryShapeEnum::COLLAPSED;
        array_push($this->collapseExcept, ...array_map(RegistryView::phaseKey(...), $phases));

        return $this;
    }

    /**
     * The audience this read is for. Passed to every condition as-is; may be null and
     * is never assumed to be an authenticatable.
     */
    public function for(mixed $viewer): self
    {
        $this->viewer = $viewer;
        $this->attribution = null;

        return $this;
    }

    /**
     * Who these entries render as, overriding the view's own declaration.
     *
     * Takes an actor id, a RegistryActorEnum strategy, or a closure
     * `fn (array $rows, HasWorkflowContract $owner, mixed $viewer): int|string|null`.
     * Null hands the decision back to the rows. Whatever resolves to null lands on
     * config('flowra.registry_views.system_user'); the recorded actor is kept either way,
     * on RegistryEntry::$recordedBy.
     *
     * Entries a mask claims are not affected: a mask is a privacy rule, and one a per-read
     * override could lift would not be worth declaring.
     */
    public function appliedBy(int|string|Closure|RegistryActorEnum|null $actor): self
    {
        $this->appliedBy = $actor;
        $this->attribution = null;

        return $this;
    }

    /**
     * Mask this phase for this read, on top of whatever the view already masks.
     *
     * Same semantics as RegistryView::maskPhase(): the stand-in replaces the real actor and
     * the recorded one is dropped. Name the phase by its key or by any state in it.
     */
    public function maskPhase(UnitEnum|string $phase, int|string|Closure $actor): self
    {
        $this->maskedPhases[RegistryView::phaseKey($phase)] = $actor;
        $this->attribution = null;

        return $this;
    }

    /**
     * Mask one transition for this read, on top of whatever the view already masks.
     */
    public function maskTransition(UnitEnum|string $transition, int|string|Closure $actor): self
    {
        $this->maskedTransitions[RegistryView::phaseKey($transition)] = $actor;
        $this->attribution = null;

        return $this;
    }

    /**
     * Add ad-hoc conditions on top of the view's own.
     */
    public function when(Closure|RegistryFilterContract|RegistryScopeContract|string ...$conditions): self
    {
        array_push($this->conditions, ...$conditions);

        $this->partitioned = null;

        return $this;
    }

    /**
     * Eager-load relations on the rows, on top of whatever the view already loads.
     *
     * Same semantics as RegistryView::with(): leaves carry their row's relations, and a relation
     * keyed on applied_by resolves against the actor every entry renders as.
     */
    public function with(string|array ...$relations): self
    {
        $this->with = RegistryView::mergeRelations($this->with, $relations);
        $this->relationSplit = null;

        return $this;
    }

    /**
     * Resolve the actor every entry renders as, on top of whatever the view already loads on it.
     *
     * Same semantics as RegistryView::withActor().
     */
    public function withActor(string|array ...$relations): self
    {
        $this->actorWith = RegistryView::mergeRelations($this->actorWith ?? [], $relations);

        return $this;
    }

    /** Oldest entry first (the default). */
    public function oldest(): self
    {
        $this->direction = 'asc';

        return $this;
    }

    /** Newest entry first. Runs are still collapsed chronologically, then reversed. */
    public function latest(): self
    {
        $this->direction = 'desc';

        return $this;
    }

    public function viewName(): string
    {
        return $this->view->name;
    }

    public function shape(): RegistryShapeEnum
    {
        return $this->shape;
    }

    /**
     * Escape hatch: the underlying Eloquent query with the SQL-expressible conditions
     * applied, ordered as requested. Closure conditions are NOT applied here — they can
     * only run in PHP.
     */
    public function query(): Builder
    {
        return $this->baseQuery($this->direction);
    }

    public function get(): RegistryCollection
    {
        $rows = $this->rows();

        $entries = $this->shape === RegistryShapeEnum::COLLAPSED
            ? RegistryCollapser::collapse(
                $rows, $this->phases(), $this->statesEnum(), $this->attribution(), $this->collapsible()
            )
            : RegistryCollapser::detailed($rows, $this->phases(), $this->statesEnum(), $this->attribution());

        $entries = $this->hydrate($entries);

        return $this->direction === 'desc'
            ? $entries->reverse()->values()
            : $entries;
    }

    /**
     * Paginate the entries.
     *
     * Detailed reads with no PHP-side condition paginate in SQL, where one row is one
     * entry and LIMIT/OFFSET is honest. Collapsed reads (and detailed reads behind a
     * closure condition) cannot: a SQL LIMIT would split a run across a page boundary
     * and produce entries with the wrong from/to. Those load the owner's filtered rows
     * — bounded to one model and one workflow — collapse them, then paginate the
     * resulting entries in memory.
     */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        if ($this->paginatesInSql()) {
            $paginator = $this->query()
                ->paginate($perPage, ['*'], $pageName, $page)
                ->through(function (Registry $row) {
                    $phase = RegistryCollapser::phaseFor($row, $this->phases());

                    return RegistryEntry::fromRow(
                        $row,
                        $phase['key'] ?? null,
                        $this->statesEnum(),
                        // The mask phase, as RegistryCollapser::detailed() passes it — without it a
                        // paginated read would render the actor a maskPhase() hides.
                        $this->attribution()->row($row, RegistryCollapser::maskPhaseFor($row, $this->phases())),
                        $phase['label'] ?? null
                    );
                });

            return $paginator->setCollection($this->hydrate($paginator->getCollection()));
        }

        $entries = $this->get();

        return new LengthAwarePaginator(
            $entries->forPage($page, $perPage)->values(),
            $entries->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName]
        );
    }

    public function first(): ?RegistryEntry
    {
        return $this->get()->first();
    }

    public function count(): int
    {
        // Entries only equal rows when nothing is collapsed and nothing is filtered in PHP.
        if ($this->paginatesInSql()) {
            return $this->query()->count();
        }

        return $this->get()->count();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->get()->all());
    }

    public function toArray(): array
    {
        return $this->get()->toArray();
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Rows for this owner + workflow, always fetched ascending so runs can be detected,
     * then narrowed by any PHP-side conditions.
     *
     * @return array<int, Registry>
     */
    private function rows(): array
    {
        $filters = $this->partition()['filters'];
        $rows = $this->baseQuery('asc')->get()->all();

        if ($filters === []) {
            return $rows;
        }

        $owner = $this->workflow->model;

        return array_values(array_filter(
            $rows,
            fn (Registry $row) => RegistryConditionResolver::allows($filters, $row, $owner, $this->viewer)
        ));
    }

    private function baseQuery(string $direction): Builder
    {
        $owner = $this->workflow->model;

        $query = WorkflowModels::registry()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('workflow', $this->workflow::class)
            // Registry ids are ordered UUIDs, so this stays stable for rows written
            // within the same second.
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction);

        ['rows' => $rowRelations, 'actors' => $actorRelations] = $this->relations();

        if ($rowRelations !== []) {
            $query->with($rowRelations);
        }

        if ($actorRelations !== []) {
            // Never selected off the rows, where they would name the recorded actor — not even
            // through the model's own $with. They resolve against the rendered actor in hydrate().
            $query->without(array_keys($actorRelations));
        }

        RegistryConditionResolver::scope($this->partition()['scopes'], $query, $owner, $this->viewer);

        return $query;
    }

    /**
     * Attach what the read asked for beyond the rows — actor relations and the withActor()
     * model — to every entry and child.
     *
     * @template TEntries of Collection<int, RegistryEntry>
     *
     * @param  TEntries  $entries
     * @return TEntries
     */
    private function hydrate(Collection $entries): Collection
    {
        $actorRelations = $this->relations()['actors'];

        if ($actorRelations !== []) {
            $entries = RegistryRelationLoader::make($actorRelations)->load($entries);
        }

        if ($this->actorWith === null) {
            return $entries;
        }

        return RegistryActorLoader::make($this->actorWith)->load($entries);
    }

    /**
     * @return array{rows: array<int|string, string|Closure>, actors: array<string, array<int|string, string|Closure>>}
     */
    private function relations(): array
    {
        return $this->relationSplit ??= RegistryRelationLoader::split($this->with);
    }

    private function paginatesInSql(): bool
    {
        return $this->shape === RegistryShapeEnum::DETAILED && $this->partition()['filters'] === [];
    }

    /**
     * @return array{scopes: array<int, RegistryScopeContract>, filters: array<int, Closure|RegistryFilterContract>}
     */
    private function partition(): array
    {
        return $this->partitioned ??= RegistryConditionResolver::partition($this->conditions);
    }

    /**
     * The actor resolution for this read, rebuilt whenever the viewer or the override
     * changes.
     */
    private function attribution(): RegistryAttribution
    {
        return $this->attribution ??= RegistryAttribution::make(
            $this->workflow->model,
            $this->viewer,
            $this->appliedBy,
            $this->maskedPhaseKeys(),
            $this->maskedTransitions
        );
    }

    /**
     * Masked phases keyed by the phase key the read layer matches on.
     *
     * Declared names go through the phase map first, exactly like collapsible(), so a view
     * may mask a phase by its key or by any state inside it.
     *
     * @return array<string, int|string|Closure>
     */
    private function maskedPhaseKeys(): array
    {
        if ($this->maskedPhases === []) {
            return [];
        }

        $phases = $this->phases();
        $resolved = [];

        foreach ($this->maskedPhases as $name => $actor) {
            $resolved[$phases[$name]['key'] ?? $name] = $actor;
        }

        return $resolved;
    }

    /**
     * State value => the phase that state sits in.
     *
     * @return array<string, array{key: string, label: ?string, applied_by: mixed}>
     */
    private function phases(): array
    {
        return $this->workflow->phaseMap();
    }

    /**
     * Which phases this read collapses, or null when it collapses all of them.
     *
     * Declared names are resolved through the phase map first, so a view may name a phase
     * by its key or by any state inside it and both land on the same phase.
     *
     * @return Closure(string): bool|null
     */
    private function collapsible(): ?Closure
    {
        if ($this->collapseOnly === null && $this->collapseExcept === []) {
            return null;
        }

        $phases = $this->phases();
        $resolve = static fn (string $name): string => $phases[$name]['key'] ?? $name;

        $only = $this->collapseOnly === null ? null : array_map($resolve, $this->collapseOnly);
        $except = array_map($resolve, $this->collapseExcept);

        return static function (string $phase) use ($only, $except): bool {
            if (in_array($phase, $except, true)) {
                return false;
            }

            return $only === null || in_array($phase, $only, true);
        };
    }

    private function statesEnum(): ?string
    {
        return $this->workflow->statesEnum();
    }
}
