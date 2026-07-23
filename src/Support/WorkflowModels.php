<?php

namespace Flowra\Support;

use Flowra\Models\Registry;
use Flowra\Models\Status;

class WorkflowModels
{
    /**
     * @return class-string<Status>
     */
    public static function status(): string
    {
        return config('flowra.models.status') ?? Status::class;
    }

    /**
     * @return class-string<Registry>
     */
    public static function registry(): string
    {
        return config('flowra.models.registry') ?? Registry::class;
    }
}
