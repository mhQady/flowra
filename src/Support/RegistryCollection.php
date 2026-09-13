<?php

namespace Flowra\Support;

use Flowra\DTOs\RegistryEntry;
use Illuminate\Support\Collection;

/**
 * @extends Collection<int, RegistryEntry>
 */
class RegistryCollection extends Collection
{
    /**
     * Flatten collapsed entries back into the underlying rows, in timeline order.
     * Useful when a caller wants the collapsed shape for display but the full detail
     * for an export.
     */
    public function leaves(): static
    {
        $leaves = [];

        foreach ($this as $entry) {
            if ($entry->children->isEmpty()) {
                $leaves[] = $entry;

                continue;
            }

            foreach ($entry->children as $child) {
                $leaves[] = $child;
            }
        }

        return new static($leaves);
    }

    /**
     * Only the entries that stand in for a phase.
     */
    public function collapsed(): static
    {
        return $this->filter(static fn (RegistryEntry $entry) => $entry->isCollapsed())->values();
    }
}
