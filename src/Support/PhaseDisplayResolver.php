<?php

namespace Flowra\Support;

use Flowra\Enums\PhaseDisplayEnum;

/**
 * Decides how an entry inside a phase is presented, stacking every declaration in one place.
 *
 * Precedence, lowest to highest:
 *   1. Phase::display() on the states enum;
 *   2. the view, for every phase;
 *   3. the view, for this phase;
 *   4. the read, for every phase;
 *   5. the read, for this phase;
 *   6. otherwise STATE_ONLY.
 *
 * Per-phase names are resolved through the phase map first, so a phase named by any of its
 * member states lands on the phase — the same as collapse() and maskPhase().
 */
final class PhaseDisplayResolver
{
    /** Ordered highest first; each a [phase key => mode] map or a wide mode. @var array<int, PhaseDisplayEnum|array<string, PhaseDisplayEnum>|null> */
    private array $layers;

    /**
     * @param  array<string, array{key: string, label: ?string, display?: ?string}>  $phaseMap
     * @param  array<string, PhaseDisplayEnum>  $viewPhases
     * @param  array<string, PhaseDisplayEnum>  $readPhases
     */
    public function __construct(
        private readonly array $phaseMap,
        ?PhaseDisplayEnum $viewWide = null,
        array $viewPhases = [],
        ?PhaseDisplayEnum $readWide = null,
        array $readPhases = [],
    ) {
        $this->layers = [
            $this->resolveNames($readPhases),
            $readWide,
            $this->resolveNames($viewPhases),
            $viewWide,
        ];
    }

    /**
     * The mode for an entry sitting in this phase. An entry in no phase is always STATE_ONLY.
     */
    public function for(?string $phase): PhaseDisplayEnum
    {
        if ($phase === null) {
            return PhaseDisplayEnum::STATE_ONLY;
        }

        foreach ($this->layers as $layer) {
            if ($layer instanceof PhaseDisplayEnum) {
                return $layer;
            }

            if (is_array($layer) && isset($layer[$phase])) {
                return $layer[$phase];
            }
        }

        $declared = $this->phaseMap[$phase]['display'] ?? null;

        return $declared === null
            ? PhaseDisplayEnum::STATE_ONLY
            : (PhaseDisplayEnum::tryFrom($declared) ?? PhaseDisplayEnum::STATE_ONLY);
    }

    /** True when nothing is declared anywhere — every entry is presented as STATE_ONLY. */
    public function isInert(): bool
    {
        foreach ($this->layers as $layer) {
            if ($layer instanceof PhaseDisplayEnum || (is_array($layer) && $layer !== [])) {
                return false;
            }
        }

        foreach ($this->phaseMap as $phase) {
            if (($phase['display'] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, PhaseDisplayEnum>  $modes
     * @return array<string, PhaseDisplayEnum>
     */
    private function resolveNames(array $modes): array
    {
        $resolved = [];

        foreach ($modes as $name => $mode) {
            $resolved[$this->phaseMap[$name]['key'] ?? $name] = $mode;
        }

        return $resolved;
    }
}
