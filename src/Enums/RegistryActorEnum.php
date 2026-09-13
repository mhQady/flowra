<?php

namespace Flowra\Enums;

/**
 * How a rendered registry entry picks its actor out of the rows behind it.
 *
 * LAST  — the actor who closed the run (the default, and the only behaviour before
 *         attribution existed).
 * FIRST — the actor who opened it.
 * SOLE  — the single actor behind the run, or none when more than one took part. A run
 *         that several people pushed through has no honest single applier, so this
 *         resolves to null and falls through to the configured system user.
 *
 * A strategy only has something to choose from on a collapsed entry; on a leaf every
 * strategy resolves to the row's own applied_by.
 */
enum RegistryActorEnum: string
{
    use BaseEnum;

    case FIRST = 'first';
    case LAST = 'last';
    case SOLE = 'sole';
}
