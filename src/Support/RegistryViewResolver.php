<?php

namespace Flowra\Support;

use Flowra\DTOs\RegistryView;
use Flowra\Exceptions\RegistryViewNotFoundException;
use RuntimeException;

/**
 * Resolves the named registry views available to a workflow.
 *
 * Sources, in increasing precedence:
 *   1. the built-in `default` view (detailed, unconditional — today's raw registry);
 *   2. config('flowra.registry_views.views'), shared by every workflow;
 *   3. the workflow's own static registryViews(), which wins on name clashes.
 *
 * Views are memoized per workflow class for the lifetime of the process only. They are
 * deliberately NOT pushed through Support\WorkflowCache: a view may hold closures and
 * bound objects, which serialize() refuses, so persisting them would fail on every
 * request and be silently swallowed. There is nothing expensive to persist either —
 * building a view set is an array walk over config and one static method call.
 */
final class RegistryViewResolver
{
    public const DEFAULT_VIEW = 'default';

    /** @var array<class-string, array<string, RegistryView>> */
    private static array $memo = [];

    /**
     * @param  class-string  $workflow
     * @return array<string, RegistryView>
     */
    public static function for(string $workflow): array
    {
        return self::$memo[$workflow] ??= self::compile($workflow);
    }

    /**
     * @param  class-string  $workflow
     *
     * @throws RegistryViewNotFoundException when the name is not registered anywhere.
     */
    public static function get(string $workflow, ?string $name = null): RegistryView
    {
        $name ??= self::defaultName();
        $views = self::for($workflow);

        if (! isset($views[$name])) {
            throw new RegistryViewNotFoundException(__('flowra::flowra.registry_view_not_defined', [
                'view' => $name,
                'workflow' => $workflow,
            ]));
        }

        return $views[$name];
    }

    public static function defaultName(): string
    {
        $name = config('flowra.registry_views.default_view');

        return is_string($name) && $name !== '' ? $name : self::DEFAULT_VIEW;
    }

    /**
     * Drop the memoized views. Called on service provider boot so a changed config (or
     * a fresh test application) never reads a previous application's definitions.
     */
    public static function flush(?string $workflow = null): void
    {
        if ($workflow === null) {
            self::$memo = [];

            return;
        }

        unset(self::$memo[$workflow]);
    }

    /**
     * @param  class-string  $workflow
     * @return array<string, RegistryView>
     */
    private static function compile(string $workflow): array
    {
        $views = [self::DEFAULT_VIEW => RegistryView::make(self::DEFAULT_VIEW)->detailed()];

        foreach ((array) config('flowra.registry_views.views', []) as $name => $definition) {
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('Registry views in config must be keyed by view name.');
            }

            if (! is_array($definition) && ! $definition instanceof RegistryView) {
                throw new RuntimeException(
                    "Registry view [{$name}] in config must be an array or a ".RegistryView::class.' instance.'
                );
            }

            $views[$name] = RegistryView::fromArray($name, $definition);
        }

        foreach (self::workflowViews($workflow) as $view) {
            $views[$view->name] = $view;
        }

        return $views;
    }

    /**
     * @param  class-string  $workflow
     * @return array<int, RegistryView>
     */
    private static function workflowViews(string $workflow): array
    {
        if (! method_exists($workflow, 'registryViews')) {
            return [];
        }

        $views = $workflow::registryViews();

        foreach ($views as $view) {
            if (! $view instanceof RegistryView) {
                throw new RuntimeException(
                    $workflow.'::registryViews() must return an array of '.RegistryView::class.' objects.'
                );
            }
        }

        return array_values($views);
    }
}
