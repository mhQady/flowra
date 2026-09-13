<?php

namespace Flowra\Support;

use Closure;
use Flowra\Contracts\HasWorkflowContract;
use Flowra\DTOs\RegistryActor;
use Flowra\DTOs\RegistryEntry;
use Flowra\Enums\RegistryActorEnum;
use Flowra\Models\Registry;
use InvalidArgumentException;

/**
 * Decides which actor a rendered registry entry is attributed to.
 *
 * Read-time only: nothing here changes what apply() wrote. An attributed entry keeps the
 * actor the row recorded alongside the one it renders as; a *masked* one deliberately
 * drops it, which is the whole point of a mask.
 *
 * Resolution order, highest first:
 *   1. a mask — registryView()->maskTransition(...) / maskPhase(...), or the same on the
 *      view. A transition mask beats a phase mask. Masks outrank every declaration below,
 *      since a privacy rule a looser declaration could defeat is not one;
 *   2. the builder override — registryView()->appliedBy(...);
 *   3. the view declaration — RegistryView::make(...)->appliedBy(...);
 *   4. the default — a leaf keeps its own applied_by, a run takes the actor who closed it;
 *   5. the configured system user, whenever the steps above resolved to null.
 *
 * (2) and (3) are folded into one $declared value by the caller, since the builder simply
 * shadows the view; the two mask maps are folded the same way.
 */
final class RegistryAttribution
{
    /**
     * @param  int|string|Closure|RegistryActorEnum|null  $declared  builder override, else the view's declaration
     * @param  array<string, int|string|Closure>  $maskedPhases  stand-ins keyed by phase key
     * @param  array<string, int|string|Closure>  $maskedTransitions  stand-ins keyed by transition key
     * @param  int|string|null  $fallback  the system user, used when nothing else resolves
     */
    public function __construct(
        private readonly HasWorkflowContract $owner,
        private readonly mixed $viewer = null,
        private readonly int|string|Closure|RegistryActorEnum|null $declared = null,
        private readonly array $maskedPhases = [],
        private readonly array $maskedTransitions = [],
        private readonly int|string|null $fallback = null,
    ) {
    }

    /**
     * Build the attribution for one read, taking the fallback from config.
     *
     * @param  array<string, int|string|Closure>  $maskedPhases
     * @param  array<string, int|string|Closure>  $maskedTransitions
     */
    public static function make(
        HasWorkflowContract $owner,
        mixed $viewer = null,
        int|string|Closure|RegistryActorEnum|null $declared = null,
        array $maskedPhases = [],
        array $maskedTransitions = [],
    ): self {
        return new self($owner, $viewer, $declared, $maskedPhases, $maskedTransitions, self::systemUser());
    }

    /**
     * The configured stand-in actor for rows nobody signed — a queue, a console command, a
     * webhook. Null (the default) leaves those entries without an actor, exactly as before.
     */
    public static function systemUser(): int|string|null
    {
        $actor = config('flowra.registry_views.system_user');

        if ($actor === null || $actor === '' || (! is_int($actor) && ! is_string($actor))) {
            return null;
        }

        // applied_by is an unsigned integer column, so an id arriving from env() as a
        // numeric string is the same actor as the integer it spells.
        return is_string($actor) && ctype_digit($actor) ? (int) $actor : $actor;
    }

    /**
     * Whether this read masks anything at all — lets callers skip the phase lookup a mask
     * would need on a workflow that declares none.
     */
    public function masks(): bool
    {
        return $this->maskedPhases !== [] || $this->maskedTransitions !== [];
    }

    /**
     * The actor for a leaf — one registry row.
     *
     * $phase is the phase the row's landing state belongs to, passed in because masking
     * covers jumps too, which carry no phase for collapsing purposes.
     */
    public function row(Registry $row, ?string $phase = null): RegistryActor
    {
        $mask = $this->maskFor((string) $row->transition, $phase);

        if ($mask !== null) {
            return $this->redact($mask, [$row]);
        }

        $recorded = $row->applied_by;

        return $this->resolve($this->declared, [$row], [$recorded], $recorded);
    }

    /**
     * The actor for a collapsed entry — a consecutive run of rows sharing a phase.
     *
     * Strategies choose from the actors the children already render as, so a child that
     * fell back to the system user contributes that, and a child a mask claimed contributes
     * its stand-in rather than the actor it hid.
     *
     * @param  array<int, RegistryEntry>  $children
     * @param  array<int, Registry>  $rows
     */
    public function run(array $children, array $rows, ?string $phase = null): RegistryActor
    {
        $mask = $phase === null ? null : ($this->maskedPhases[$phase] ?? null);

        if ($mask !== null) {
            return $this->redact($mask, $rows);
        }

        $actors = array_map(static fn (RegistryEntry $child) => $child->appliedBy, $children);

        $last = $children === [] ? null : $children[count($children) - 1];

        return $this->resolve($this->declared, $rows, $actors, $last?->recordedBy);
    }

    /**
     * The stand-in claiming this row, if any. A transition mask is the more specific of the
     * two and wins.
     */
    private function maskFor(string $transition, ?string $phase): int|string|Closure|null
    {
        return $this->maskedTransitions[$transition]
            ?? ($phase === null ? null : ($this->maskedPhases[$phase] ?? null));
    }

    /**
     * Render as the stand-in and drop the recorded actor.
     *
     * @param  array<int, Registry>  $rows
     */
    private function redact(int|string|Closure $mask, array $rows): RegistryActor
    {
        $id = $mask instanceof Closure
            ? self::validate($mask($rows, $this->owner, $this->viewer))
            : $mask;

        return RegistryActor::redacted($id ?? $this->fallback);
    }

    /**
     * @param  array<int, Registry>  $rows
     * @param  array<int, int|string|null>  $actors
     */
    private function resolve(
        int|string|Closure|RegistryActorEnum|null $source,
        array $rows,
        array $actors,
        int|string|null $recorded,
    ): RegistryActor {
        $declared = false;

        if ($source instanceof Closure) {
            $id = self::validate($source($rows, $this->owner, $this->viewer));
            $declared = true;
        } elseif ($source instanceof RegistryActorEnum) {
            $id = self::strategy($source, $actors);
        } elseif ($source !== null) {
            $id = $source;
            $declared = true;
        } else {
            $id = self::strategy(RegistryActorEnum::LAST, $actors);
        }

        if ($id === null && $this->fallback !== null) {
            return RegistryActor::attributed($this->fallback, $recorded);
        }

        return $declared
            ? RegistryActor::attributed($id, $recorded)
            : new RegistryActor($id, $recorded);
    }

    /**
     * @param  array<int, int|string|null>  $actors
     */
    private static function strategy(RegistryActorEnum $strategy, array $actors): int|string|null
    {
        $actors = array_values($actors);

        return match ($strategy) {
            RegistryActorEnum::FIRST => $actors[0] ?? null,
            RegistryActorEnum::LAST => $actors === [] ? null : $actors[count($actors) - 1],
            RegistryActorEnum::SOLE => self::sole($actors),
        };
    }

    /**
     * The one actor behind the run, or null when several took part — an entry several
     * people pushed through has no honest single applier.
     *
     * @param  array<int, int|string|null>  $actors
     */
    private static function sole(array $actors): int|string|null
    {
        $distinct = [];

        foreach ($actors as $actor) {
            if ($actor !== null && ! in_array($actor, $distinct, true)) {
                $distinct[] = $actor;
            }
        }

        return count($distinct) === 1 ? $distinct[0] : null;
    }

    private static function validate(mixed $actor): int|string|null
    {
        if ($actor === null || is_int($actor) || is_string($actor)) {
            return $actor;
        }

        throw new InvalidArgumentException(sprintf(
            'A registry attribution closure must return an actor id (int|string) or null, got [%s].',
            get_debug_type($actor)
        ));
    }
}
