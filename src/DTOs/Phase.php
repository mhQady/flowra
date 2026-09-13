<?php

namespace Flowra\DTOs;

use UnitEnum;

/**
 * A named set of states — one logical step of the workflow.
 *
 * A phase serves two purposes at once:
 *
 *   - the query scopes expand it, so you can ask for a coarse step and match every fine
 *     state inside it;
 *   - the registry read layer collapses it — the logical step a collapsed view shows in
 *     place of the individual rows that landed inside it.
 *
 * The phase is keyed by its `state`, which may be a real enum case (a parent state that
 * contains sub-states, the shape `flowra:import-workflow` generates) or a plain string
 * (a synthetic step name like 'under_review' that no transition ever lands on).
 *
 * A phase never decides *whether* it collapses — that is a per-view choice, so the same
 * phase can be collapsed in one registry view and expanded in another. See
 * RegistryView::collapse() / dontCollapse().
 *
 * Formerly StateGroup, which remains as a deprecated alias.
 */
final class Phase
{
    private UnitEnum|string $state;

    /**
     * @var array<int, UnitEnum|string>
     */
    private array $children = [];

    /** Optional translation key (or literal label) for a collapsed entry of this phase. */
    private ?string $label = null;

    private function __construct(UnitEnum|string $state)
    {
        $this->state = $state;
    }

    public static function make(UnitEnum|string $state): self
    {
        return new self($state);
    }

    public function child(UnitEnum|string $state): self
    {
        $this->children[] = $state;

        return $this;
    }

    public function children(UnitEnum|string ...$states): self
    {
        array_push($this->children, ...$states);

        return $this;
    }

    /**
     * Label for a collapsed entry of this phase.
     *
     * Resolved at read time as a translation key, falling back to itself when it is a
     * literal. Leave it out to use flowra::flowra.phases.{key}, then a humanized key.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'children' => $this->children,
            'label' => $this->label,
        ];
    }
}
