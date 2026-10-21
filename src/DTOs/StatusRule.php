<?php

namespace Flowra\DTOs;

use Closure;
use Flowra\Contracts\StatusConditionContract;
use InvalidArgumentException;
use UnitEnum;

/**
 * One rule of a status view: which real states it claims, what to show in their place, and
 * under which conditions.
 *
 * A rule never changes what is stored. It only decides what one audience is *shown* when it
 * asks for the current status — see StatusView.
 *
 * States are named by enum case, by value, or by phase key. A name that is a phase key claims
 * every state inside that phase (resolved through the workflow's phase map at read time, the
 * same way RegistryView::collapse() and maskPhase() resolve); any other name claims only that
 * state.
 */
final class StatusRule
{
    /** Claimed state values and phase keys. @var array<int, string> */
    private array $states;

    /** Displayed key; null keeps the real state. A hide rule must set one — it is checked at read time. */
    private ?string $as = null;

    /** Translation key (or literal) for the displayed status. */
    private ?string $label = null;

    /** @var array<int, Closure|StatusConditionContract|class-string> */
    private array $conditions = [];

    private bool $keepDetails = false;

    /**
     * @param  array<int, UnitEnum|string>  $states
     */
    private function __construct(array $states, private readonly bool $any, private readonly bool $hides)
    {
        $this->states = array_values(array_map(RegistryView::phaseKey(...), $states));
    }

    /**
     * Claim these states — by case, value or phase key — and show something else in their place.
     */
    public static function show(UnitEnum|string ...$states): self
    {
        return new self(self::requireStates($states, 'show'), false, false);
    }

    /**
     * Claim these states and *redact* the entry: the real state, its comment and its actor are
     * dropped from the rendered status itself, not only from its serialized form.
     */
    public static function hide(UnitEnum|string ...$states): self
    {
        return new self(self::requireStates($states, 'hide'), false, true);
    }

    /**
     * Claim every state — "this audience always sees one status", or a catch-all after the
     * narrower rules.
     */
    public static function any(): self
    {
        return new self([], true, false);
    }

    /**
     * Build a rule from a config array:
     *   ['show' => [...]] | ['hide' => [...]] | ['any' => true], plus optional
     *   'as', 'label', 'conditions' (class-strings only) and 'keep_details'.
     *
     * Config survives `php artisan config:cache`, which refuses closures, so a closure condition
     * here is rejected outright rather than failing later. Declare those on the workflow class.
     */
    public static function fromArray(array $definition): self
    {
        $rule = match (true) {
            isset($definition['hide']) => self::hide(...(array) $definition['hide']),
            isset($definition['show']) => self::show(...(array) $definition['show']),
            ! empty($definition['any']) => self::any(),
            default => throw new InvalidArgumentException(
                'A status view rule in config needs a [show], [hide] or [any] key.'
            ),
        };

        if (isset($definition['as'])) {
            $rule->as($definition['as']);
        }

        if (isset($definition['label'])) {
            $rule->label($definition['label']);
        }

        foreach ((array) ($definition['conditions'] ?? []) as $condition) {
            if (! is_string($condition)) {
                throw new InvalidArgumentException(
                    'Status view conditions in config must be class-strings; declare closures in statusViews().'
                );
            }

            $rule->when($condition);
        }

        if (! empty($definition['keep_details'] ?? $definition['keepDetails'] ?? false)) {
            $rule->keepDetails();
        }

        return $rule;
    }

    /**
     * The key shown in place of the claimed state: a real state, a phase key, or a synthetic
     * key such as 'in_progress' that no transition lands on.
     */
    public function as(UnitEnum|string $key): self
    {
        $this->as = RegistryView::phaseKey($key);

        return $this;
    }

    /**
     * Label for the displayed status, resolved as a translation key and falling back to itself
     * when it is a literal. Wins over the view's label prefix and the flowra lang keys.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Only apply this rule when every condition is truthy. Called more than once, the conditions
     * add up.
     */
    public function when(Closure|StatusConditionContract|string ...$conditions): self
    {
        array_push($this->conditions, ...$conditions);

        return $this;
    }

    /**
     * Keep the status row's comment and actor on a remapped entry.
     *
     * A remapped entry drops both by default: a comment written at an internal step tends to
     * describe that step, which is exactly what the remap hides. A hide rule ignores this —
     * redaction cannot be lifted.
     */
    public function keepDetails(): self
    {
        $this->keepDetails = true;

        return $this;
    }

    /**
     * Whether this rule claims the given real state.
     *
     * @param  array<string, array{key: string, label: ?string}>  $phaseMap  state value => its phase
     */
    public function claims(string $state, array $phaseMap): bool
    {
        if ($this->any || in_array($state, $this->states, true)) {
            return true;
        }

        $phase = $phaseMap[$state]['key'] ?? null;

        return $phase !== null && in_array($phase, $this->states, true);
    }

    public function displayedKey(): ?string
    {
        return $this->as;
    }

    public function labelKey(): ?string
    {
        return $this->label;
    }

    /** @return array<int, Closure|StatusConditionContract|class-string> */
    public function conditions(): array
    {
        return $this->conditions;
    }

    public function hides(): bool
    {
        return $this->hides;
    }

    public function keepsDetails(): bool
    {
        return $this->keepDetails && ! $this->hides;
    }

    /**
     * @param  array<int, UnitEnum|string>  $states
     * @return array<int, UnitEnum|string>
     */
    private static function requireStates(array $states, string $method): array
    {
        if ($states === []) {
            throw new InvalidArgumentException(
                "StatusRule::{$method}() needs at least one state or phase; use StatusRule::any() to claim every state."
            );
        }

        return $states;
    }
}
