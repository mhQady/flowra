<?php

namespace Flowra\Traits\Support;

use Flowra\DTOs\RegistryView;
use Flowra\Enums\PhaseDisplayEnum;
use InvalidArgumentException;
use UnitEnum;

/**
 * The phase-display declarations shared by views (StatusView, RegistryView) and reads
 * (StatusViewBuilder, RegistryBuilder): one mode for every phase, and/or one mode per phase.
 *
 * Declaring is all this does — Support\PhaseDisplayResolver stacks the layers and decides.
 */
trait DeclaresPhaseDisplay
{
    /** Mode for every phase; null leaves it to the layer below. */
    private ?PhaseDisplayEnum $phaseDisplayWide = null;

    /** Modes keyed by phase name as declared (a phase key or a member state). @var array<string, PhaseDisplayEnum> */
    private array $phaseDisplayPhases = [];

    /**
     * Present states inside these phases — or, with no phases named, inside every phase — in
     * this mode. Name a phase by its key or by any state inside it.
     */
    public function phaseDisplay(PhaseDisplayEnum|string $mode, UnitEnum|string ...$phases): static
    {
        $mode = PhaseDisplayEnum::resolve($mode);

        if ($phases === []) {
            $this->phaseDisplayWide = $mode;

            return $this;
        }

        foreach ($phases as $phase) {
            $this->phaseDisplayPhases[RegistryView::phaseKey($phase)] = $mode;
        }

        return $this;
    }

    /** Show the phase in place of the state. */
    public function phaseOnly(UnitEnum|string ...$phases): static
    {
        return $this->phaseDisplay(PhaseDisplayEnum::PHASE_ONLY, ...$phases);
    }

    /** Show the state together with its phase. */
    public function withPhase(UnitEnum|string ...$phases): static
    {
        return $this->phaseDisplay(PhaseDisplayEnum::WITH_PHASE, ...$phases);
    }

    /** Show the state alone — the default, useful to override a wider declaration. */
    public function stateOnly(UnitEnum|string ...$phases): static
    {
        return $this->phaseDisplay(PhaseDisplayEnum::STATE_ONLY, ...$phases);
    }

    public function phaseDisplayWide(): ?PhaseDisplayEnum
    {
        return $this->phaseDisplayWide;
    }

    /** @return array<string, PhaseDisplayEnum> */
    public function phaseDisplayPhases(): array
    {
        return $this->phaseDisplayPhases;
    }

    /**
     * Read a config `phase_display` value: a mode string for every phase, or
     * ['*' => mode, phase => mode, ...].
     */
    private function phaseDisplayFromConfig(mixed $definition): void
    {
        if ($definition === null) {
            return;
        }

        if (is_string($definition)) {
            $this->phaseDisplay($definition);

            return;
        }

        if (! is_array($definition)) {
            throw new InvalidArgumentException('phase_display must be a mode string or an array of [phase => mode].');
        }

        foreach ($definition as $phase => $mode) {
            $phase === '*' ? $this->phaseDisplay($mode) : $this->phaseDisplay($mode, (string) $phase);
        }
    }
}
