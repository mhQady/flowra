<?php

namespace Flowra\Support;

use Closure;
use Flowra\DTOs\RegistryEntry;
use Flowra\Enums\TransitionTypesEnum;
use Flowra\Models\Registry;

/**
 * Turns registry rows into registry-view entries.
 *
 * A row belongs to a phase when the state it landed in (`to`) belongs to that phase.
 * Transitions declare nothing — move a state into a phase and every row that ever landed
 * there is part of it, including rows written before the phase existed.
 *
 * Collapsing rules (all deliberate, see the README):
 *   - only CONSECUTIVE rows of the same phase merge; a phase re-entered later in the
 *     timeline is a separate episode and gets its own entry;
 *   - a run of one row is still wrapped, so a phase's label never depends on how many
 *     internal transitions happened to be recorded;
 *   - jumpTo() rows are never collapsed and break any run they land in;
 *   - a row landing in a state that belongs to no phase carries no phase and passes
 *     through as a leaf;
 *   - a phase the view chose not to collapse also passes through as leaves, while its
 *     rows keep their phase key.
 *
 * Rows must arrive in ascending chronological order.
 *
 * The optional RegistryAttribution decides which actor each entry renders as; without one,
 * a leaf keeps its own applied_by and a run takes the actor who left the phase.
 *
 * Masking asks a different question of the same phase map. `phaseFor()` withholds a phase
 * from a jumpTo() row so a forced change can never hide inside a run — but a jump landing in
 * a masked phase must still be masked, or the mask leaks exactly the actor it exists to
 * hide. So attribution gets `maskPhaseFor()`, which resolves the landing state's phase for
 * every row including jumps, while collapsing keeps using `phaseFor()`.
 */
final class RegistryCollapser
{
    /**
     * @param  iterable<Registry>  $rows
     * @param  array<string, array{key: string, label: ?string}>  $phases
     */
    public static function detailed(
        iterable $rows,
        array $phases = [],
        ?string $statesEnum = null,
        ?RegistryAttribution $attribution = null,
    ): RegistryCollection {
        $entries = [];

        foreach ($rows as $row) {
            $phase = self::phaseFor($row, $phases);

            $entries[] = RegistryEntry::fromRow(
                $row,
                $phase['key'] ?? null,
                $statesEnum,
                $attribution?->row($row, self::maskPhaseFor($row, $phases)),
                $phase['label'] ?? null
            );
        }

        return new RegistryCollection($entries);
    }

    /**
     * @param  iterable<Registry>  $rows
     * @param  array<string, array{key: string, label: ?string}>  $phases
     * @param  Closure(string): bool|null  $collapsible  which phases this read collapses; null collapses every phase
     */
    public static function collapse(
        iterable $rows,
        array $phases = [],
        ?string $statesEnum = null,
        ?RegistryAttribution $attribution = null,
        ?Closure $collapsible = null,
    ): RegistryCollection {
        $entries = [];
        $run = [];
        $runRows = [];
        $runPhase = null;
        $runLabel = null;

        $flush = static function () use (
            &$entries, &$run, &$runRows, &$runPhase, &$runLabel, $statesEnum, $attribution
        ): void {
            if ($run === []) {
                return;
            }

            $entries[] = RegistryEntry::fromRun(
                $run,
                $runPhase,
                $runLabel,
                $statesEnum,
                $attribution?->run($run, $runRows, $runPhase)
            );

            $run = [];
            $runRows = [];
            $runPhase = null;
            $runLabel = null;
        };

        foreach ($rows as $row) {
            $phase = self::phaseFor($row, $phases);
            $key = $phase['key'] ?? null;

            $entry = RegistryEntry::fromRow(
                $row,
                $key,
                $statesEnum,
                $attribution?->row($row, self::maskPhaseFor($row, $phases)),
                $phase['label'] ?? null
            );

            // No phase, or a phase this view leaves expanded: the row stands on its own and
            // breaks whatever run it interrupted. It keeps its phase key either way, so a
            // caller can still tell which step the row belonged to.
            if ($key === null || ($collapsible !== null && ! $collapsible($key))) {
                $flush();
                $entries[] = $entry;

                continue;
            }

            if ($runPhase !== null && $runPhase !== $key) {
                $flush();
            }

            $runPhase = $key;
            $runLabel = $phase['label'] ?? null;
            $run[] = $entry;
            $runRows[] = $row;
        }

        $flush();

        return new RegistryCollection($entries);
    }

    /**
     * The phase a row belongs to for collapsing, resolved from the state it landed in.
     *
     * @param  array<string, array{key: string, label: ?string}>  $phases
     * @return array{key: string, label: ?string}|array{}
     */
    public static function phaseFor(Registry $row, array $phases): array
    {
        // A forced state change must never hide inside a collapsed phase.
        if ((int) $row->type !== TransitionTypesEnum::TRANSITION->value) {
            return [];
        }

        return $phases[(string) $row->to] ?? [];
    }

    /**
     * The phase key a mask matches this row on — the landing state's phase, jumps included.
     *
     * A view masks a phase to hide who acted inside it. A jumpTo() row landing in that
     * phase was still someone acting inside it, so it is masked like any other; it simply
     * never collapses. See phaseFor() for the collapsing question.
     *
     * @param  array<string, array{key: string, label: ?string}>  $phases
     */
    public static function maskPhaseFor(Registry $row, array $phases): ?string
    {
        return $phases[(string) $row->to]['key'] ?? null;
    }
}
