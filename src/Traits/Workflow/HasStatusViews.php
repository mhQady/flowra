<?php

namespace Flowra\Traits\Workflow;

use Flowra\Support\StatusViewBuilder;
use Flowra\Support\StatusViewResolver;

/**
 * The read-side presentation layer over the current status — the status-side twin of
 * HasRegistryViews.
 *
 * Nothing here writes, and nothing here changes currentState / currentStatus / status(): a
 * status view only decides what one audience is shown when it asks which status the model is in.
 */
trait HasStatusViews
{
    /**
     * Read this model's current status through the given (or default) view.
     *
     * @throws \Flowra\Exceptions\StatusViewNotFoundException on an unknown view name.
     */
    public function statusView(?string $view = null): StatusViewBuilder
    {
        return new StatusViewBuilder($this, StatusViewResolver::get(static::class, $view));
    }
}
