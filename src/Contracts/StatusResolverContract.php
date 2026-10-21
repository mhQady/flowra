<?php

namespace Flowra\Contracts;

use Flowra\Models\Status;
use UnitEnum;

/**
 * A status-view escape hatch: computes the displayed status outright, before any rule runs.
 *
 * Return the key to show — a state case, a state value, a phase key or a synthetic key — or
 * null to hand the decision on to the next resolver and then to the view's rules.
 */
interface StatusResolverContract
{
    /**
     * The viewer is whatever the host application passed to `->for()` — it may be null
     * (queue / CLI context) and Flowra never assumes it is an authenticatable.
     */
    public function resolve(Status $status, HasWorkflowContract $owner, mixed $viewer = null): UnitEnum|string|null;
}
