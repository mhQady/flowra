<?php

namespace Flowra\Traits;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Str;

trait LoadFlowDynamically
{
    /** @var array<class-string, object> */
    protected array $flowInstances = [];

    /** @return array<class-string> */
    private function __appliedFlows(): array
    {
        return property_exists($this, 'flows') ? $this->flows : [];
    }

    /** Reverse lookup: from property name to class, or null if not a flow prop */
    private function __flowClassForProperty(string $prop): ?string
    {
        return array_find(
            $this->__appliedFlows(),
            fn($class) => Str::of(class_basename($class))->camel()->toString() === Str::of($prop)->camel()->toString()
        );
    }

    /** Lazily make (and cache) an instance of the flow class.
     * @throws BindingResolutionException
     */
    private function __makeFlow(string $class): object
    {
        return $this->flowInstances[$class] ??= app()->make($class, ['model' => $this]);
    }

    /**
     * Hook into attribute access.
     * If Laravel can't find a real attribute/relation/mutator,
     * expose flows as virtual attributes.
     * @throws BindingResolutionException
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if (
            array_key_exists($key, $this->getAttributes()) ||
            $this->hasGetMutator($key) ||
            $this->hasAttributeGetMutator($key ?? '') ||
            $this->isClassCastable($key) ||
            $this->hasCast($key) ||
            $this->relationLoaded($key) ||
            method_exists($this, $key)
        ) {
            return $value;
        }

        if ($class = $this->__flowClassForProperty($key)) {
            return $this->__makeFlow($class);
        }

        return $value;
    }

    /** Support isset($context->farzApplicationMainFlow) */
    public function __isset($key): bool
    {
        if ($class = $this->__flowClassForProperty($key)) {
            return true;
        }
        return parent::__isset($key);
    }
}