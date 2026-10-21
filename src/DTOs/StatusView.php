<?php

namespace Flowra\DTOs;

use Closure;
use Flowra\Contracts\StatusConditionContract;
use Flowra\Contracts\StatusResolverContract;
use Flowra\Traits\Support\DeclaresPhaseDisplay;
use Flowra\Traits\Support\DeclaresSubStates;
use InvalidArgumentException;
use LogicException;
use UnitEnum;

/**
 * A named audience for the current status: an ordered set of rules deciding what that
 * audience is shown when it asks which status a model is in.
 *
 * The status-side twin of RegistryView. Views are declared globally in
 * config('flowra.status_views.views') and/or per workflow through a static statusViews()
 * method, where the workflow wins on name clashes.
 *
 * Read-only: currentState, currentStatus, status(), the query scopes and every transition keep
 * using the real state. A view only shapes what $workflow->statusView() renders.
 *
 * Rules are added with rule(), or fluently with show() / hide() followed by as(), label(),
 * when() and keepDetails() — which always apply to the rule added last:
 *
 *   StatusView::make('owner')
 *       ->show(S::UNDER_PROCESSING)->as('under_processing')
 *       ->hide(S::INTERNAL_REVIEW)->as('in_progress')->when(IsApplicant::class);
 */
final class StatusView
{
    use DeclaresPhaseDisplay, DeclaresSubStates;

    /** @var array<int, StatusRule> */
    private array $rules = [];

    /** @var array<int, Closure|StatusResolverContract|class-string> */
    private array $resolvers = [];

    /** Translation prefix the displayed key is looked up under, e.g. 'workflow.refarz'. */
    private ?string $labelPrefix = null;

    /** Key shown when the model has no status yet; null renders no status at all. */
    private ?string $fallback = null;

    /** Relations loaded on the status row, in Eloquent's with() syntax. @var array<int|string, string|Closure> */
    private array $with = [];

    private function __construct(public readonly string $name)
    {
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    /**
     * Build a view from a config array:
     *   ['labels' => 'workflow.refarz', 'fallback' => 'draft',
     *    'resolvers' => [Resolver::class], 'rules' => [['show' => [...], 'as' => '...'], ...],
     *    'phase_display' => 'phase' | ['*' => 'with_phase', 'under_review' => 'phase'],
     *    'sub_state' => true | ['prepare_application_info'],
     *    'with' => ['appliedBy.roles']]
     *
     * Config-declared conditions and resolvers must be class-strings — `php artisan
     * config:cache` refuses to serialize closures. Use statusViews() on the workflow for those.
     */
    public static function fromArray(string $name, array|self $definition): self
    {
        if ($definition instanceof self) {
            if ($definition->name !== $name) {
                throw new InvalidArgumentException(
                    "Status view [{$definition->name}] is registered under the key [{$name}]; "
                    .'the key and the view name must match.'
                );
            }

            return $definition;
        }

        $view = new self($name);

        if (isset($definition['labels'])) {
            $view->labels($definition['labels']);
        }

        if (isset($definition['fallback'])) {
            $view->fallback($definition['fallback']);
        }

        foreach ((array) ($definition['resolvers'] ?? []) as $resolver) {
            if (! is_string($resolver)) {
                throw new InvalidArgumentException(
                    "Status view [{$name}] resolvers in config must be class-strings; declare closures in statusViews()."
                );
            }

            $view->using($resolver);
        }

        foreach ((array) ($definition['rules'] ?? []) as $rule) {
            $view->rule(is_array($rule) ? StatusRule::fromArray($rule) : $rule);
        }

        $view->phaseDisplayFromConfig($definition['phase_display'] ?? null);
        $view->subStatesFromConfig($definition['sub_state'] ?? null);

        if (($with = $definition['with'] ?? null) !== null) {
            $view->with((array) $with);
        }

        return $view;
    }

    /**
     * Append rules. They are checked in declaration order and the first that claims the real
     * state — with every condition passing — decides what is shown.
     */
    public function rule(StatusRule ...$rules): self
    {
        array_push($this->rules, ...$rules);

        return $this;
    }

    /**
     * Start a rule claiming these states; finish it with as(), label(), when().
     */
    public function show(UnitEnum|string ...$states): self
    {
        return $this->rule(StatusRule::show(...$states));
    }

    /**
     * Start a redacting rule claiming these states; finish it with as(), label(), when().
     */
    public function hide(UnitEnum|string ...$states): self
    {
        return $this->rule(StatusRule::hide(...$states));
    }

    /** The displayed key for the rule added last. */
    public function as(UnitEnum|string $key): self
    {
        $this->lastRule('as')->as($key);

        return $this;
    }

    /** The label for the rule added last. */
    public function label(string $label): self
    {
        $this->lastRule('label')->label($label);

        return $this;
    }

    /** Conditions for the rule added last. */
    public function when(Closure|StatusConditionContract|string ...$conditions): self
    {
        $this->lastRule('when')->when(...$conditions);

        return $this;
    }

    /** Keep the comment and actor on the rule added last. */
    public function keepDetails(): self
    {
        $this->lastRule('keepDetails')->keepDetails();

        return $this;
    }

    /**
     * Compute the displayed status outright, before any rule runs.
     *
     * Takes a closure `fn (Status $status, HasWorkflowContract $owner, mixed $viewer): UnitEnum|string|null`,
     * a StatusResolverContract, or its class-string. The first resolver returning a key wins and
     * the entry is remapped; null falls through to the next resolver, then to the rules.
     */
    public function using(Closure|StatusResolverContract|string ...$resolvers): self
    {
        array_push($this->resolvers, ...$resolvers);

        return $this;
    }

    /**
     * Look the displayed key up under this translation prefix before the flowra lang keys —
     * so a host that already names its states under 'workflow.refarz.*' need not copy them.
     */
    public function labels(string $prefix): self
    {
        $this->labelPrefix = rtrim($prefix, '.');

        return $this;
    }

    /**
     * The key shown while the model has no status yet. Null (the default) renders none.
     */
    public function fallback(UnitEnum|string|null $key): self
    {
        $this->fallback = $key === null ? null : RegistryView::phaseKey($key);

        return $this;
    }

    /** @return array<int, StatusRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @return array<int, Closure|StatusResolverContract|class-string> */
    public function resolvers(): array
    {
        return $this->resolvers;
    }

    public function labelPrefix(): ?string
    {
        return $this->labelPrefix;
    }

    /**
     * Load these relations on the status row — any relation declared on your status model
     * (config('flowra.models.status')), in Eloquent's own with() syntax: names, dotted nesting, or
     * [name => constraint]. They land on StatusEntry::$relations.
     *
     * Relations travel with the details: they are loaded only on a status that keeps its comment
     * and actor (the real status, or a rule with keepDetails()). A remapped or hidden status stands
     * in for a step the viewer must not see, so it carries none of that step's relations.
     *
     * Loaded with loadMissing(), so a list that eager-loaded `{alias}Status.relation` costs nothing.
     * Called more than once, the relations add up.
     */
    public function with(string|array ...$relations): self
    {
        $this->with = RegistryView::mergeRelations($this->with, $relations);

        return $this;
    }

    /** @return array<int|string, string|Closure> */
    public function eagerLoads(): array
    {
        return $this->with;
    }

    public function fallbackKey(): ?string
    {
        return $this->fallback;
    }

    private function lastRule(string $method): StatusRule
    {
        $rule = $this->rules[count($this->rules) - 1] ?? null;

        if ($rule === null) {
            throw new LogicException(
                "StatusView::{$method}() applies to the rule added last; call show(), hide() or rule() first."
            );
        }

        return $rule;
    }
}
