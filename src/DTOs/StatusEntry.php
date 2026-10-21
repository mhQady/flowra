<?php

namespace Flowra\DTOs;

use Carbon\CarbonInterface;
use Flowra\Enums\PhaseDisplayEnum;
use Flowra\Support\Labels;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use UnitEnum;

/**
 * The current status as one audience is shown it — the rendered result of a status view.
 *
 * `key` is what the audience sees: the real state, or whatever a rule or resolver put in its
 * place (another state, a phase key, a synthetic key like 'in_progress'). `state` and `phase`
 * describe that displayed key separately — the state it is (null for a synthetic key) and the
 * phase it sits in or is — so a client can tell a step from a sub-step.
 *
 * `recordedState` is the real state, kept for server code holding the DTO. It is null once a
 * hide rule redacts the entry, and it is never serialized: a client of a remapped status must
 * not be able to read the real one back. Server code that needs the truth reads
 * $workflow->currentState, as it always has.
 *
 * The details — `appliedBy`, `comment`, `transition`, `from` and `relations` — are opt-in. The
 * real status carries them only when its view asked (StatusView::withDetails(); the built-in
 * `default` view does). A remapped entry carries them only when its rule asked
 * (StatusRule::keepDetails()), whatever the view says. A redacted entry never carries them.
 * Without the opt-in they are null, [], null, null and [].
 *
 * `relations` holds what a view's with() loaded on the status row, only where the details were
 * kept.
 *
 * `transition` and `from` are the status row's own, for server code that decides per move (who may
 * see an actor or a comment). They travel with the details: null wherever comment and actor were
 * dropped, and never serialized.
 *
 * `subState` is the one deliberate exception to hiding the real state: a view that asked for it
 * (StatusView::withSubState()) carries the real state inside the phase shown as `key` — never
 * on a redacted entry, and never a state from outside that phase.
 */
final class StatusEntry implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $state,
        public readonly ?string $phase,
        public readonly ?CarbonInterface $appliedAt,
        public readonly int|string|null $appliedBy,
        public readonly array $comment,
        public readonly bool $remapped,
        public readonly bool $redacted,
        public readonly ?string $recordedState,
        private readonly ?string $labelKey = null,
        private readonly ?string $labelPrefix = null,
        private readonly ?string $phaseLabelKey = null,
        private readonly ?string $statesEnum = null,
        private readonly PhaseDisplayEnum $display = PhaseDisplayEnum::STATE_ONLY,
        public readonly ?string $subState = null,
        private readonly bool $subStateRequested = false,
        public readonly ?string $transition = null,
        public readonly ?string $from = null,
        public readonly array $relations = [],
    ) {
    }

    /**
     * Human name of the real sub-state carried with a phase (StatusView::withSubState()), or null
     * when none is carried. Resolved like label() for a state, without the rule's label — that
     * names the displayed status, not the sub-state.
     */
    public function subStateLabel(): ?string
    {
        return $this->stateLabel($this->subState);
    }

    /**
     * Human name of a state value under this view: {labels prefix}.{value} when translated, else
     * flowra::flowra.states.{value}, else a humanized value.
     */
    private function stateLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($this->labelPrefix !== null && ($translated = Labels::translate("{$this->labelPrefix}.{$value}")) !== null) {
            return $translated;
        }

        return Labels::state($value);
    }

    /**
     * How the entry is presented relative to its phase. PHASE_ONLY has already been applied to
     * `key` (it is the phase) and `state` (null); WITH_PHASE adds displayLabel().
     */
    public function display(): PhaseDisplayEnum
    {
        return $this->display;
    }

    /**
     * The label to put in front of a viewer: label(), or — under WITH_PHASE — the phase and the
     * state together, from the flowra::flowra.with_phase pattern.
     */
    public function displayLabel(): string
    {
        if ($this->display === PhaseDisplayEnum::WITH_PHASE && $this->phase !== null && $this->phase !== $this->key) {
            return Labels::combined((string) $this->phaseLabel(), $this->label());
        }

        return $this->label();
    }

    /**
     * Human name for the displayed key. Resolution order:
     *   1. the matching rule's label(), as a translation key or a literal;
     *   2. {view label prefix}.{key}, when the view declared labels() and the key is translated;
     *   3. a phase key that is no state of its own: the phase label (Labels::phase());
     *   4. flowra::flowra.states.{key}, else a humanized key.
     */
    public function label(): string
    {
        if ($this->labelKey !== null) {
            return Labels::declared($this->labelKey);
        }

        if ($this->labelPrefix !== null && ($translated = Labels::translate("{$this->labelPrefix}.{$this->key}")) !== null) {
            return $translated;
        }

        if ($this->state === null && $this->phase === $this->key) {
            return Labels::phase($this->key, $this->phaseLabelKey);
        }

        return (string) Labels::state($this->key);
    }

    /**
     * Human name of the phase the displayed key sits in, or null when it sits in none — same
     * resolution as RegistryEntry::phaseLabel().
     */
    public function phaseLabel(): ?string
    {
        return $this->phase === null ? null : Labels::phase($this->phase, $this->phaseLabelKey);
    }

    /** The displayed key as a states-enum case, or null when it is no state. */
    public function stateEnum(): ?UnitEnum
    {
        if ($this->state === null || $this->statesEnum === null || ! method_exists($this->statesEnum, 'tryFrom')) {
            return null;
        }

        return $this->statesEnum::tryFrom($this->state);
    }

    /** Whether a rule or resolver changed what is shown. */
    public function isRemapped(): bool
    {
        return $this->remapped;
    }

    /** Whether a hide rule claimed the entry, dropping the real state, comment and actor. */
    public function isRedacted(): bool
    {
        return $this->redacted;
    }

    /**
     * The real state never travels — see the class docblock.
     */
    public function toArray(): array
    {
        $entry = [
            // What this audience is shown: the real state, or what a rule put in its place.
            'key' => $this->key,
            'label' => $this->label(),
            // The displayed key as a state: {key, label}. Null for a synthetic key.
            'state' => $this->state === null
                ? null
                : ['key' => $this->state, 'label' => (string) $this->stateLabel($this->state)],
            // The phase the displayed key sits in (or is): {key, label}. Null outside any phase.
            'phase' => $this->phase === null
                ? null
                : ['key' => $this->phase, 'label' => (string) $this->phaseLabel()],
            // When the model reached its current status.
            'applied_at' => $this->appliedAt,
            // Null unless the details were opted in: withDetails() on the real status, the rule's
            // keepDetails() on a remapped one. Never on a redacted entry.
            'applied_by' => $this->appliedBy,
            'comment' => $this->comment,
            // True when a rule or resolver changed what is shown.
            'remapped' => $this->remapped,
            // True when a hide rule claimed the entry. Always implies remapped.
            'redacted' => $this->redacted,
        ];

        if ($this->relations !== []) {
            // Only in a read with with(), and only on a status that kept its details (opt-in).
            $entry['relations'] = array_map(
                static fn (mixed $related) => $related instanceof Arrayable ? $related->toArray() : $related,
                $this->relations
            );
        }

        if ($this->subStateRequested) {
            // Only in a view or read that asked for sub-states: the real state inside the phase
            // shown as `key`, {key, label}, or null when there is none to carry.
            $entry['sub_state'] = $this->subState === null
                ? null
                : ['key' => $this->subState, 'label' => (string) $this->subStateLabel()];
        }

        if ($this->display !== PhaseDisplayEnum::STATE_ONLY) {
            // Only under a phase display mode, so STATE_ONLY output is unchanged.
            $entry['display'] = $this->display->value;
            $entry['display_label'] = $this->displayLabel();
        }

        return $entry;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
