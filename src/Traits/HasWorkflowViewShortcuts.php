<?php

namespace Flowra\Traits;

use Illuminate\Support\Str;

/**
 * Model-level shortcuts to a workflow's read views, named after the workflow alias — the same
 * naming HasWorkflowRelations uses for `{alias}Status` / `{alias}Registry`:
 *
 *   $order->orderWorkflowRegistryView('customer')  ===  $order->orderWorkflow->registryView('customer')
 *   $order->orderWorkflowStatusView('customer')    ===  $order->orderWorkflow->statusView('customer')
 *
 * Anything else falls through to Eloquent's own __call.
 */
trait HasWorkflowViewShortcuts
{
    private const VIEW_SHORTCUTS = [
        'RegistryView' => 'registryView',
        'StatusView' => 'statusView',
    ];

    public function __call($method, $parameters)
    {
        foreach (self::VIEW_SHORTCUTS as $suffix => $viewMethod) {
            if (!str_ends_with($method, $suffix)) {
                continue;
            }

            $alias = substr($method, 0, -strlen($suffix));

            if (in_array($alias, static::workflowAliases(), true)) {
                return $this->{$alias}->{$viewMethod}(...$parameters);
            }
        }

        return parent::__call($method, $parameters);
    }

    /**
     * camelCase basenames of the applied workflows, e.g. ['orderWorkflow'].
     *
     * @return string[]
     */
    private static function workflowAliases(): array
    {
        return array_map(
            fn (string $class) => Str::camel(class_basename($class)),
            static::appliedWorkflows()
        );
    }
}
