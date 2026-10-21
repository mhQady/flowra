<?php

namespace Flowra\Support;

use Closure;
use Flowra\Concretes\BaseWorkflow;
use Flowra\Contracts\StatusConditionContract;
use Flowra\Contracts\StatusResolverContract;
use Flowra\DTOs\RegistryView;
use Flowra\DTOs\StatusEntry;
use Flowra\DTOs\StatusRule;
use Flowra\DTOs\StatusView;
use Flowra\Enums\PhaseDisplayEnum;
use Flowra\Models\Status;
use Flowra\Traits\Support\DeclaresPhaseDisplay;
use Flowra\Traits\Support\DeclaresSubStates;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use UnitEnum;

/**
 * A deferred read of one model's current status through one status view.
 *
 * The status row is the one the workflow already hydrated (HasStates::hydrateStates()), so a
 * read costs no query of its own — rendering a status view per row of a list costs nothing
 * beyond what status() costs today.
 *
 * Per-read rules are checked before the view's own, and resolvers before any rule. The first
 * that decides wins; when none does, the real status is shown unchanged.
 *
 * Details (actor, comment, transition, from, relations) travel only on opt-in — see entry().
 */
final class StatusViewBuilder implements Arrayable, JsonSerializable
{
    use DeclaresPhaseDisplay, DeclaresSubStates;

    private mixed $viewer = null;

    /** Rules added for this read only; checked before the view's. @var array<int, StatusRule> */
    private array $rules = [];

    /** @var array<int, Closure|StatusResolverContract|class-string> */
    private array $resolvers = [];

    private ?string $labelPrefix;

    private ?string $fallback;

    /** Relations loaded on the status row; seeded from the view. @var array<int|string, string|Closure> */
    private array $with;

    public function __construct(
        private readonly BaseWorkflow $workflow,
        private readonly StatusView $view,
    ) {
        $this->labelPrefix = $view->labelPrefix();
        $this->fallback = $view->fallbackKey();
        $this->with = $view->eagerLoads();
    }

    /**
     * The audience this read is for. Passed to every condition and resolver as-is; may be null
     * and is never assumed to be an authenticatable.
     */
    public function for(mixed $viewer): self
    {
        $this->viewer = $viewer;

        return $this;
    }

    /** Add rules for this read, checked before the view's own. */
    public function rule(StatusRule ...$rules): self
    {
        array_push($this->rules, ...$rules);

        return $this;
    }

    public function show(UnitEnum|string ...$states): self
    {
        return $this->rule(StatusRule::show(...$states));
    }

    public function hide(UnitEnum|string ...$states): self
    {
        return $this->rule(StatusRule::hide(...$states));
    }

    /** The displayed key for the per-read rule added last. */
    public function as(UnitEnum|string $key): self
    {
        $this->lastRule('as')->as($key);

        return $this;
    }

    /** The label for the per-read rule added last. */
    public function label(string $label): self
    {
        $this->lastRule('label')->label($label);

        return $this;
    }

    /** Conditions for the per-read rule added last. */
    public function when(Closure|StatusConditionContract|string ...$conditions): self
    {
        $this->lastRule('when')->when(...$conditions);

        return $this;
    }

    /** Keep the comment and actor on the per-read rule added last. */
    public function keepDetails(): self
    {
        $this->lastRule('keepDetails')->keepDetails();

        return $this;
    }

    /** Add resolvers for this read, checked before the view's own. */
    public function using(Closure|StatusResolverContract|string ...$resolvers): self
    {
        array_push($this->resolvers, ...$resolvers);

        return $this;
    }

    public function labels(string $prefix): self
    {
        $this->labelPrefix = rtrim($prefix, '.');

        return $this;
    }

    /**
     * Load these relations on the status row for this read, on top of the view's — same rules as
     * StatusView::with(): only on a status that keeps its details, through loadMissing(), and a
     * LogicException on get() when neither the view nor any rule opts in to details.
     */
    public function with(string|array ...$relations): self
    {
        $this->with = RegistryView::mergeRelations($this->with, $relations);

        return $this;
    }

    public function fallback(UnitEnum|string|null $key): self
    {
        $this->fallback = $key === null ? null : RegistryView::phaseKey($key);

        return $this;
    }

    public function viewName(): string
    {
        return $this->view->name;
    }

    /**
     * The status this audience is shown, or null when the model has no status yet and the view
     * declares no fallback.
     *
     * @throws LogicException when relations are asked for but nothing opts in to details.
     */
    public function get(): ?StatusEntry
    {
        $this->assertRelationsOptedIn();

        $status = $this->workflow->currentStatus;

        if ($status === null || $status->to === null) {
            return $this->fallback === null ? null : $this->entry($this->fallback, null, null, null);
        }

        $real = (string) $status->to;

        foreach ([...$this->resolvers, ...$this->view->resolvers()] as $resolver) {
            $key = $this->resolve($resolver, $status);

            if ($key !== null) {
                return $this->entry(RegistryView::phaseKey($key), $status, $real, null);
            }
        }

        $phaseMap = $this->workflow->phaseMap();

        foreach ([...$this->rules, ...$this->view->rules()] as $rule) {
            if ($rule->claims($real, $phaseMap) && $this->passes($rule, $status)) {
                if ($rule->hides() && $rule->displayedKey() === null) {
                    // Showing the real key under a "hidden" flag would hide nothing.
                    throw new LogicException('A hide() status rule needs as(): the key shown in place of the hidden state.');
                }

                return $this->entry($rule->displayedKey() ?? $real, $status, $real, $rule);
            }
        }

        return $this->entry($real, $status, $real, null, remapped: false);
    }

    public function toArray(): ?array
    {
        return $this->get()?->toArray();
    }

    public function jsonSerialize(): ?array
    {
        return $this->toArray();
    }

    /**
     * Render the displayed key as an entry.
     *
     * Details — appliedBy, comment, transition, from and the with() relations — are kept only on
     * opt-in, and the opt-in depends on what is shown:
     *   - the real status: the view's withDetails() (the built-in `default` view has it);
     *   - a remapped status (rule, resolver, or phase-only re-keying): the matching rule's
     *     keepDetails() only. The view's opt-in does not reach it, because a remapped status
     *     stands in for a step the viewer must not see. A resolver has no rule, so its status
     *     never keeps details;
     *   - a hidden status, or a fallback with no status row: never.
     */
    private function entry(string $key, ?Status $status, ?string $real, ?StatusRule $rule, bool $remapped = true): StatusEntry
    {
        $statesEnum = $this->workflow->statesEnum();
        $phaseMap = $this->workflow->phaseMap();
        $phase = $phaseMap[$key] ?? null;
        $display = $this->phaseDisplayResolver($phaseMap)->for($phase['key'] ?? null);
        $state = $statesEnum::tryFrom($key) === null ? null : $key;
        $labelKey = $rule?->labelKey();

        if ($display === PhaseDisplayEnum::PHASE_ONLY && $phase !== null) {
            // The phase in place of the state: the child must not travel, not even as `state`.
            if ($phase['key'] !== $key) {
                $key = $phase['key'];
                $remapped = true;
                // A rule's label names the key the rule chose, which is no longer shown.
                $labelKey = null;
            }

            $state = null;
        }

        $redacted = $rule?->hides() ?? false;
        $keepDetails = $status !== null
            && ($remapped ? ($rule?->keepsDetails() ?? false) : $this->view->keepsDetails());
        $subState = $redacted ? null : $this->subStateFor($key, $real, $phase, $phaseMap);

        return new StatusEntry(
            key: $key,
            state: $state,
            phase: $phase['key'] ?? null,
            appliedAt: $status?->updated_at ?? $status?->created_at,
            appliedBy: $keepDetails ? $status->applied_by : null,
            comment: $keepDetails ? self::comment($status) : [],
            // A fallback stands in for a status the model does not have yet; nothing was remapped.
            remapped: $status !== null && $remapped,
            redacted: $redacted,
            recordedState: $redacted ? null : $real,
            labelKey: $labelKey,
            labelPrefix: $this->labelPrefix,
            phaseLabelKey: $phase['label'] ?? null,
            statesEnum: $statesEnum,
            display: $phase === null ? PhaseDisplayEnum::STATE_ONLY : $display,
            subState: $subState,
            subStateRequested: $this->subStatesDeclared(),
            transition: $keepDetails ? $status->transition : null,
            from: $keepDetails && $status->from !== null ? (string) $status->from : null,
            relations: $keepDetails ? $this->relations($status) : [],
        );
    }

    /**
     * The relations this read asked for, loaded on the status row — loadMissing(), so whatever the
     * caller already eager-loaded (`{alias}Status.relation`) costs no query.
     *
     * Only the top-level relations asked for are handed over; nested ones ride on them.
     *
     * @return array<string, mixed>
     */
    private function relations(Status $status): array
    {
        if ($this->with === []) {
            return [];
        }

        $status->loadMissing($this->with);

        $names = [];

        foreach ($this->with as $key => $value) {
            $names[] = explode('.', is_int($key) ? (string) $value : $key)[0];
        }

        return array_intersect_key($status->getRelations(), array_flip($names));
    }

    /**
     * The real state to carry beside a status shown as a phase, or null.
     *
     * Only when sub-states were asked for this phase, the status is presented *as* the phase,
     * and the real state sits inside that same phase without being the phase itself — so a rule
     * mapping an unrelated state onto the phase never reveals it.
     *
     * @param  array{key: string, label: ?string}|null  $phase  the phase of the displayed key
     */
    private function subStateFor(string $key, ?string $real, ?array $phase, array $phaseMap): ?string
    {
        if ($real === null || $phase === null || $phase['key'] !== $key || $real === $key) {
            return null;
        }

        if (($phaseMap[$real]['key'] ?? null) !== $key) {
            return null;
        }

        if ($this->view->subStatesEverywhere() || $this->subStatesEverywhere()) {
            return $real;
        }

        foreach ([...$this->view->subStatePhases(), ...$this->subStatePhases()] as $name) {
            if (($phaseMap[$name]['key'] ?? $name) === $key) {
                return $real;
            }
        }

        return null;
    }

    private function subStatesDeclared(): bool
    {
        return $this->view->subStatesEverywhere() || $this->subStatesEverywhere()
            || $this->view->subStatePhases() !== [] || $this->subStatePhases() !== [];
    }

    /**
     * The view's declarations under this read's — see PhaseDisplayResolver for the full ladder.
     */
    private function phaseDisplayResolver(array $phaseMap): PhaseDisplayResolver
    {
        return new PhaseDisplayResolver(
            $phaseMap,
            $this->view->phaseDisplayWide(),
            $this->view->phaseDisplayPhases(),
            $this->phaseDisplayWide(),
            $this->phaseDisplayPhases(),
        );
    }

    /**
     * Every condition of the rule must be truthy, the same "all must pass" rule as registry
     * conditions and guards.
     */
    private function passes(StatusRule $rule, Status $status): bool
    {
        $owner = $this->workflow->model;

        foreach ($rule->conditions() as $condition) {
            $condition = is_string($condition) ? app($condition) : $condition;

            $result = match (true) {
                $condition instanceof StatusConditionContract => $condition->allows($status, $owner, $this->viewer),
                $condition instanceof Closure => $condition($status, $owner, $this->viewer),
                default => throw new InvalidArgumentException(sprintf(
                    'Status view condition [%s] must be a Closure or implement %s.',
                    get_debug_type($condition),
                    StatusConditionContract::class
                )),
            };

            if (! $result) {
                return false;
            }
        }

        return true;
    }

    private function resolve(Closure|StatusResolverContract|string $resolver, Status $status): UnitEnum|string|null
    {
        $resolver = is_string($resolver) ? app($resolver) : $resolver;
        $owner = $this->workflow->model;

        return match (true) {
            $resolver instanceof StatusResolverContract => $resolver->resolve($status, $owner, $this->viewer),
            $resolver instanceof Closure => $resolver($status, $owner, $this->viewer),
            default => throw new InvalidArgumentException(sprintf(
                'Status view resolver [%s] must be a Closure or implement %s.',
                get_debug_type($resolver),
                StatusResolverContract::class
            )),
        };
    }

    /**
     * The status row's comment lines, always an array. Read through the raw value first: the
     * model's JSON accessor throws on a null column.
     */
    private static function comment(Status $status): array
    {
        $raw = $status->getAttributes()['comment'] ?? null;

        if ($raw === null || $raw === '') {
            return [];
        }

        $comment = $status->comment;

        if ($comment === null || $comment === '') {
            return [];
        }

        return is_array($comment) ? array_values($comment) : [$comment];
    }

    /**
     * Relations travel with the details, so asking for them without any opt-in would load rows no
     * entry may carry. Refused outright, rather than implying the opt-in and exposing the actor.
     */
    private function assertRelationsOptedIn(): void
    {
        if ($this->with === [] || $this->view->keepsDetails()) {
            return;
        }

        foreach ([...$this->rules, ...$this->view->rules()] as $rule) {
            if ($rule->keepsDetails()) {
                return;
            }
        }

        throw new LogicException(sprintf(
            'Status view [%s] loads relations with with() but sends no details; call withDetails() on the view, '
            .'or keepDetails() on the rule whose status may carry them.',
            $this->view->name
        ));
    }

    private function lastRule(string $method): StatusRule
    {
        $rule = $this->rules[count($this->rules) - 1] ?? null;

        if ($rule === null) {
            throw new LogicException(
                "StatusViewBuilder::{$method}() applies to the per-read rule added last; call show(), hide() or rule() first."
            );
        }

        return $rule;
    }
}
