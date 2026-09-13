<?php

namespace Flowra\Contracts;

use Flowra\Models\Registry;

/**
 * A registry-view condition evaluated in PHP, once per fetched registry row.
 *
 * Resolution mirrors guards and actions: closures, instances and class-strings are
 * all accepted wherever a condition is expected.
 */
interface RegistryFilterContract
{
    /**
     * Decide whether the row is visible to the viewer. Only a truthy return keeps
     * the row; anything falsy (including null) removes it.
     *
     * The viewer is whatever the host application passed to `->for()` — it may be
     * null (queue / CLI context) and Flowra never assumes it is an authenticatable.
     */
    public function allows(Registry $row, HasWorkflowContract $owner, mixed $viewer = null): bool;
}
