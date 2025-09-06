<?php

namespace Flowra\Traits;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Str;

trait   LoadWorkflowDynamically
{
    /** @var array<class-string, object> */
    protected array $workflowInstances = [];

    /** @return array<class-string> */
    private function __appliedWorkflows(): array
    {
        return property_exists($this, 'workflows') ? $this->workflows : [];
    }

    /** Reverse lookup: from property name to class, or null if not a workflow prop */
    private function __workflowClassForProperty(string $prop): ?string
    {
        return array_find(
            $this->__appliedWorkflows(),
            fn($class) => Str::of(class_basename($class))->camel()->toString() === Str::of($prop)->camel()->toString()
        );
    }

    /** Lazily make (and cache) an instance of the workflow class.
     * @throws BindingResolutionException
     */
    private function __makeWorkflow(string $class): object
    {
        return $this->workflowInstances[$class] ??= app()->make($class, ['model' => $this]);
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

        if ($class = $this->__workflowClassForProperty($key)) {
            return $this->__makeWorkflow($class);
        }

        return $value;
    }
    
    public function __isset($key): bool
    {
        if ($class = $this->__workflowClassForProperty($key)) {
            return true;
        }
        return parent::__isset($key);
    }
}