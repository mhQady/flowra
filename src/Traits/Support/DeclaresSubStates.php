<?php

namespace Flowra\Traits\Support;

use Flowra\DTOs\RegistryView;
use InvalidArgumentException;
use UnitEnum;

/**
 * The sub-state declarations shared by StatusView and StatusViewBuilder: which phases, when a
 * status is presented as the phase, carry the real state inside it alongside.
 */
trait DeclaresSubStates
{
    private bool $subStatesEverywhere = false;

    /** Phase names as declared (a phase key or a member state). @var array<int, string> */
    private array $subStatePhases = [];

    /**
     * When the status is shown as one of these phases — or, with no phases named, as any phase —
     * carry the real state inside it as the entry's subState.
     *
     * This reveals the real sub-state on purpose; a hide() rule still wins over it.
     */
    public function withSubState(UnitEnum|string ...$phases): static
    {
        if ($phases === []) {
            $this->subStatesEverywhere = true;

            return $this;
        }

        array_push($this->subStatePhases, ...array_map(RegistryView::phaseKey(...), $phases));

        return $this;
    }

    public function subStatesEverywhere(): bool
    {
        return $this->subStatesEverywhere;
    }

    /** @return array<int, string> */
    public function subStatePhases(): array
    {
        return $this->subStatePhases;
    }

    /**
     * Read a config `sub_state` value: true for every phase, or a list of phases.
     */
    private function subStatesFromConfig(mixed $definition): void
    {
        if ($definition === null || $definition === false) {
            return;
        }

        if ($definition === true) {
            $this->withSubState();

            return;
        }

        if (! is_array($definition) && ! is_string($definition)) {
            throw new InvalidArgumentException('sub_state must be true or a list of phases.');
        }

        $this->withSubState(...array_map('strval', (array) $definition));
    }
}
