<?php

namespace Flowra\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * A registry-view condition that can express itself in SQL.
 *
 * Implement this whenever the condition can be pushed into the query, so rows the
 * viewer may not see are never loaded. Conditions that cannot be expressed in SQL
 * should implement RegistryFilterContract (or simply be a closure) instead; a class
 * may implement both, in which case the scope narrows and the filter refines.
 */
interface RegistryScopeContract
{
    /**
     * Constrain the registry query for the given owner and viewer.
     *
     * The viewer is whatever the host application passed to `->for()` — it may be
     * null (queue / CLI context) and Flowra never assumes it is an authenticatable.
     */
    public function apply(Builder $query, HasWorkflowContract $owner, mixed $viewer = null): void;
}
