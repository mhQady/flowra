<?php

namespace Flowra\Support;

use Flowra\Models\Registry;
use Flowra\Models\Status;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

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

    /**
     * The model a registry entry's rendered actor resolves to: the configured actor model, else
     * the application's auth user model.
     *
     * @return class-string<Model>
     *
     * @throws RuntimeException when neither names an Eloquent model.
     */
    public static function actor(): string
    {
        $model = config('flowra.models.actor') ?? config('auth.providers.users.model');

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new RuntimeException(__('flowra::flowra.registry_actor_model_not_configured'));
        }

        return $model;
    }
}
