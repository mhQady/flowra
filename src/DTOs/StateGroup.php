<?php

namespace Flowra\DTOs;

use UnitEnum;

final class StateGroup
{
    private UnitEnum|string $state;

    /**
     * @var array<int, UnitEnum|string>
     */
    private array $children = [];

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

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'children' => $this->children,
        ];
    }
}
