<?php

namespace Flowra\DTOs;

use BackedEnum;
use Closure;
use Flowra\Contracts\RegistryFilterContract;
use Flowra\Contracts\RegistryScopeContract;
use Flowra\Enums\RegistryActorEnum;
use Flowra\Enums\RegistryShapeEnum;
use InvalidArgumentException;
use UnitEnum;

/**
 * A named audience for the registry: a shape plus a set of conditions.
 *
 * Views are declared globally in config('flowra.registry_views.views') and/or per workflow
 * through a static registryViews() method, where the workflow wins on name clashes.
 */
final class RegistryView
{
    /**
     * @var array<int, Closure|RegistryFilterContract|RegistryScopeContract|class-string>
     */
    private array $conditions = [];

    private RegistryShapeEnum $shape = RegistryShapeEnum::DETAILED;

    /** Phases this view collapses; null means every phase. @var array<int, string>|null */
    private ?array $collapseOnly = null;

    /** Phases this view leaves expanded. @var array<int, string> */
    private array $collapseExcept = [];

    /** Who this view's entries are attributed to; null leaves it to the rows. */
    private int|string|Closure|RegistryActorEnum|null $appliedBy = null;

    /** Stand-in actors for masked phases, keyed by phase key. @var array<string, int|string|Closure> */
    private array $maskedPhases = [];

    /** Stand-in actors for masked transitions, keyed by transition key. @var array<string, int|string|Closure> */
    private array $maskedTransitions = [];

    private function __construct(public readonly string $name)
    {
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    /**
     * Build a view from a config array: ['shape' => 'collapsed', 'conditions' => [...]].
     *
     * Config-declared conditions must be class-strings — `php artisan config:cache`
     * refuses to serialize closures. Use registryViews() on the workflow for those.
     */
    public static function fromArray(string $name, array|self $definition): self
    {
        if ($definition instanceof self) {
            if ($definition->name !== $name) {
                throw new InvalidArgumentException(
                    "Registry view [{$definition->name}] is registered under the key [{$name}]; "
                    .'the key and the view name must match.'
                );
            }

            return $definition;
        }

        $view = new self($name);

        if (isset($definition['shape'])) {
            $view->shape($definition['shape']);
        }

        if (($collapse = $definition['collapse'] ?? null) !== null) {
            $view->collapse(...(array) $collapse);
        }

        if (($expand = $definition['expand'] ?? $definition['dont_collapse'] ?? null) !== null) {
            $view->dontCollapse(...(array) $expand);
        }

        $actor = $definition['applied_by'] ?? $definition['appliedBy'] ?? null;

        if ($actor !== null) {
            // A config file survives `config:cache`, so only scalars and strategy names
            // reach here; closure attribution belongs on the workflow class.
            $view->appliedBy(
                is_string($actor) && ($strategy = RegistryActorEnum::tryFrom($actor)) !== null
                    ? $strategy
                    : $actor
            );
        }

        $mask = $definition['mask'] ?? [];

        foreach ((array) ($mask['phases'] ?? []) as $phase => $actor) {
            $view->maskPhase((string) $phase, $actor);
        }

        foreach ((array) ($mask['transitions'] ?? []) as $transition => $actor) {
            $view->maskTransition((string) $transition, $actor);
        }

        foreach ((array) ($definition['conditions'] ?? []) as $condition) {
            $view->when($condition);
        }

        return $view;
    }

    public function detailed(): self
    {
        return $this->shape(RegistryShapeEnum::DETAILED);
    }

    /**
     * Collapse every phase, discarding any narrower selection made before it.
     */
    public function collapsed(): self
    {
        $this->collapseOnly = null;
        $this->collapseExcept = [];

        return $this->shape(RegistryShapeEnum::COLLAPSED);
    }

    /**
     * Collapse only these phases; every other row stays a leaf.
     *
     * Whether a phase collapses is a property of the audience, not of the phase — so one
     * view may collapse 'under_review' and expand 'fulfilment' while another does the
     * opposite. Implies the collapsed shape.
     *
     * Called more than once, the phases add up.
     */
    public function collapse(UnitEnum|string ...$phases): self
    {
        $this->collapseOnly = array_merge($this->collapseOnly ?? [], self::phaseKeys($phases));

        return $this->shape(RegistryShapeEnum::COLLAPSED);
    }

    /**
     * Collapse every phase except these. Implies the collapsed shape.
     */
    public function dontCollapse(UnitEnum|string ...$phases): self
    {
        array_push($this->collapseExcept, ...self::phaseKeys($phases));

        return $this->shape(RegistryShapeEnum::COLLAPSED);
    }

    /** @return array<int, string>|null */
    public function collapsedPhases(): ?array
    {
        return $this->collapseOnly;
    }

    /** @return array<int, string> */
    public function expandedPhases(): array
    {
        return $this->collapseExcept;
    }

    /**
     * Normalize a phase reference to the key the phase map is indexed by.
     *
     * A phase is named either by its own key or by any state enum case, so a caller may
     * say `collapse('under_review')` or `collapse(S::IN_REVIEW)`. A member state is mapped
     * onto its phase at read time, where the workflow's phase map is available.
     */
    public static function phaseKey(UnitEnum|string $phase): string
    {
        return self::normalizeKey($phase);
    }

    /**
     * Normalize a phase or transition reference to the string the read layer matches on.
     */
    private static function normalizeKey(UnitEnum|string $key): string
    {
        if ($key instanceof BackedEnum) {
            return (string) $key->value;
        }

        return $key instanceof UnitEnum ? $key->name : $key;
    }

    /**
     * @param  array<int, UnitEnum|string>  $phases
     * @return array<int, string>
     */
    private static function phaseKeys(array $phases): array
    {
        return array_map(self::phaseKey(...), $phases);
    }

    public function shape(RegistryShapeEnum|string $shape): self
    {
        if (is_string($shape)) {
            $resolved = RegistryShapeEnum::tryFrom($shape);

            if ($resolved === null) {
                throw new InvalidArgumentException(
                    "Unknown registry view shape [{$shape}]; expected one of: "
                    .implode(', ', RegistryShapeEnum::values())
                );
            }

            $shape = $resolved;
        }

        $this->shape = $shape;

        return $this;
    }

    /**
     * Who this view's entries render as.
     *
     * Accepts an actor id (a team account, a service user), a RegistryActorEnum strategy
     * picking one actor out of a collapsed run, or a closure
     * `fn (array $rows, HasWorkflowContract $owner, mixed $viewer): int|string|null`
     * receiving the registry rows behind the entry — one row for a leaf, the whole run for
     * a collapsed entry.
     *
     * Applies to every entry the view renders, collapsed or not, except the ones a mask
     * claims — a mask is a privacy rule and outranks this. Whatever still resolves to null
     * falls back to config('flowra.registry_views.system_user'). The rows keep their
     * recorded actor either way — see RegistryEntry::$recordedBy.
     */
    public function appliedBy(int|string|Closure|RegistryActorEnum|null $actor): self
    {
        $this->appliedBy = $actor;

        return $this;
    }

    /** Attribute a collapsed entry to the actor who opened the phase. */
    public function appliedByFirst(): self
    {
        return $this->appliedBy(RegistryActorEnum::FIRST);
    }

    /** Attribute a collapsed entry to the actor who closed the phase (the default). */
    public function appliedByLast(): self
    {
        return $this->appliedBy(RegistryActorEnum::LAST);
    }

    /**
     * Attribute a collapsed entry to the single actor behind it — and to the system user
     * when more than one took part, since then there is no honest single applier.
     */
    public function appliedBySole(): self
    {
        return $this->appliedBy(RegistryActorEnum::SOLE);
    }

    /**
     * Hide the real actor behind every entry landing in this phase, showing the given
     * stand-in instead.
     *
     * Unlike appliedBy(), this *redacts*: the entry's recordedBy is dropped, a collapsed
     * parent's children are masked too, and participants() names only the stand-in — so a
     * serialized view carries the real actor nowhere. registry() stays untouched.
     *
     * Name the phase by its key or by any state inside it. The stand-in is an actor
     * id, a translatable actor key, or a closure
     * `fn (array $rows, HasWorkflowContract $owner, mixed $viewer): int|string|null`.
     * RegistryActorEnum strategies are deliberately not accepted: a strategy picks a real
     * actor out of the rows, which is the opposite of masking one.
     *
     * A masked phase applies in every shape — a detailed read redacts its rows too — and to
     * jumps landing inside it, which are otherwise excluded from phase handling.
     */
    public function maskPhase(UnitEnum|string $phase, int|string|Closure $actor): self
    {
        $this->maskedPhases[self::normalizeKey($phase)] = $actor;

        return $this;
    }

    /**
     * Hide the real actor behind one transition, showing the given stand-in instead.
     *
     * The key is the transition key exactly as the schema declares it — the same string the
     * registry stores. Beats maskPhase() when a row matches both.
     */
    public function maskTransition(UnitEnum|string $transition, int|string|Closure $actor): self
    {
        $this->maskedTransitions[self::normalizeKey($transition)] = $actor;

        return $this;
    }

    /** @return array<string, int|string|Closure> */
    public function maskedPhases(): array
    {
        return $this->maskedPhases;
    }

    /** @return array<string, int|string|Closure> */
    public function maskedTransitions(): array
    {
        return $this->maskedTransitions;
    }

    /**
     * Attach one or many conditions, mirroring Transition::guard().
     */
    public function when(Closure|RegistryFilterContract|RegistryScopeContract|string ...$conditions): self
    {
        array_push($this->conditions, ...$conditions);

        return $this;
    }

    public function currentShape(): RegistryShapeEnum
    {
        return $this->shape;
    }

    public function isCollapsed(): bool
    {
        return $this->shape === RegistryShapeEnum::COLLAPSED;
    }

    public function appliedByResolver(): int|string|Closure|RegistryActorEnum|null
    {
        return $this->appliedBy;
    }

    /**
     * @return array<int, Closure|RegistryFilterContract|RegistryScopeContract|class-string>
     */
    public function conditions(): array
    {
        return $this->conditions;
    }
}
