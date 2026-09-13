<?php

namespace Flowra\DTOs;

/**
 * The actor a registry entry renders as, next to the one the registry recorded.
 *
 * `id` is what a view shows. `recorded` is what the row actually stored — kept so a
 * rendered entry never loses the audit truth it was built from. `attributed` is true when
 * `id` came from a declaration (a view, the builder) or from the configured system user,
 * rather than from the rows themselves.
 *
 * `redacted` is the stronger claim: the view masked this target, so `recorded` is
 * deliberately dropped and the real actor appears nowhere in the rendered entry. It is a
 * flag of its own rather than `attributed && recorded === null`, since that expression is
 * also true for a row nobody signed that the system user stood in for.
 */
final class RegistryActor
{
    public function __construct(
        public readonly int|string|null $id,
        public readonly int|string|null $recorded = null,
        public readonly bool $attributed = false,
        public readonly bool $redacted = false,
    ) {
    }

    /**
     * The actor exactly as the registry recorded it.
     */
    public static function recorded(int|string|null $recorded): self
    {
        return new self($recorded, $recorded);
    }

    /**
     * An actor a declaration (or the system-user fallback) put there, over a recorded one
     * the entry still carries.
     */
    public static function attributed(int|string|null $id, int|string|null $recorded = null): self
    {
        return new self($id, $recorded, true);
    }

    /**
     * A masked actor: the stand-in the view declared, with the recorded one dropped.
     */
    public static function redacted(int|string|null $id): self
    {
        return new self($id, null, true, true);
    }
}
