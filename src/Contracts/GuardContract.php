<?php

namespace Flowra\Contracts;

use Illuminate\Database\Eloquent\Model;

interface GuardContract
{
    public function allows(Model $model, string $transitionKey, array $context = []): bool;
}
