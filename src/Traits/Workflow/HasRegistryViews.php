<?php

namespace Flowra\Traits\Workflow;

use Flowra\Support\RegistryBuilder;
use Flowra\Support\RegistryViewResolver;

/**
 * The read-side presentation layer over the registry.
 *
 * Nothing here writes: registryView() is a projection over the rows that apply() and
 * jumpTo() already recorded in the same table. registry() stays the complete,
 * unfiltered source of truth.
 *
 * Phases come from the phases() declared on the states enum — a row belongs to a phase when
 * the state it landed in (`to`) belongs to that phase. Whether a given phase actually
 * collapses is decided per view, not per phase.
 */
trait HasRegistryViews
{
    /**
     * Read this model's registry through the given (or default) view.
     *
     * @throws \Flowra\Exceptions\RegistryViewNotFoundException on an unknown view name.
     */
    public function registryView(?string $view = null): RegistryBuilder
    {
        return new RegistryBuilder($this, RegistryViewResolver::get(static::class, $view));
    }

    /**
     * Map of state value => the phase that state sits in.
     *
     * Derived from the already-cached phases, so it costs no WorkflowCache key of its own —
     * see HasPhases::statePhases().
     *
     * @return array<string, array{key: string, label: ?string}>
     */
    public function phaseMap(): array
    {
        return static::statePhases();
    }

    /**
     * The phase the model is in right now, straight off its current state — no registry
     * read, and still correct after a jumpTo().
     *
     * @return array{key: string, label: ?string}|null
     */
    public function currentPhase(): ?array
    {
        return static::phaseForState($this->currentState);
    }
}
