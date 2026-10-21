<?php

namespace Flowra\Contracts;

use Flowra\Models\Status;

/**
 * A status-view condition: decides whether a rule applies to this status, for this viewer.
 *
 * Resolution mirrors guards, actions and registry conditions: closures, instances and
 * class-strings are all accepted wherever a condition is expected.
 */
interface StatusConditionContract
{
    /**
     * Only a truthy return lets the rule apply; anything falsy (including null) skips it.
     *
     * The viewer is whatever the host application passed to `->for()` — it may be null
     * (queue / CLI context) and Flowra never assumes it is an authenticatable.
     */
    public function allows(Status $status, HasWorkflowContract $owner, mixed $viewer = null): bool;
}
