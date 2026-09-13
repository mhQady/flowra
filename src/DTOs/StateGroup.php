<?php

namespace Flowra\DTOs;

/*
 * Phase under the name it had before "state group" became "phase".
 *
 * Autoloading Flowra\DTOs\StateGroup lands here and registers it as an alias of Phase, so
 * StateGroup::make() returns a Phase and `instanceof` holds both ways. Delete this file at the
 * next breaking release — see Traits\Workflow\HasStateGroupAliases for the rest of the layer.
 */
class_alias(Phase::class, StateGroup::class);

if (false) {
    /**
     * Never declared — describes the alias above to IDEs and classmap scanners.
     *
     * @deprecated Use Phase.
     * @mixin Phase
     */
    final class StateGroup
    {
    }
}
