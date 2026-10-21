<?php

namespace Flowra\Support;

use Flowra\DTOs\StatusView;
use Flowra\Exceptions\StatusViewNotFoundException;
use RuntimeException;

/**
 * Resolves the named status views available to a workflow — the status-side twin of
 * RegistryViewResolver, with the same sources and the same memoization rules.
 *
 * Sources, in increasing precedence:
 *   1. the built-in `default` view (no rules — the real status, with its details);
 *   2. config('flowra.status_views.views'), shared by every workflow;
 *   3. the workflow's own static statusViews(), which wins on name clashes.
 *
 * Views are memoized per workflow class for the lifetime of the process only, never through
 * Support\WorkflowCache: a view may hold closures and bound objects, which serialize() refuses.
 */
final class StatusViewResolver
{
    public const DEFAULT_VIEW = 'default';

    /** @var array<class-string, array<string, StatusView>> */
    private static array $memo = [];

    /**
     * @param  class-string  $workflow
     * @return array<string, StatusView>
     */
    public static function for(string $workflow): array
    {
        return self::$memo[$workflow] ??= self::compile($workflow);
    }

    /**
     * @param  class-string  $workflow
     *
     * @throws StatusViewNotFoundException when the name is not registered anywhere.
     */
    public static function get(string $workflow, ?string $name = null): StatusView
    {
        $name ??= self::defaultName();
        $views = self::for($workflow);

        if (! isset($views[$name])) {
            throw new StatusViewNotFoundException(__('flowra::flowra.status_view_not_defined', [
                'view' => $name,
                'workflow' => $workflow,
            ]));
        }

        return $views[$name];
    }

    public static function defaultName(): string
    {
        $name = config('flowra.status_views.default_view');

        return is_string($name) && $name !== '' ? $name : self::DEFAULT_VIEW;
    }

    /**
     * Drop the memoized views. Called on service provider boot so a changed config (or a fresh
     * test application) never reads a previous application's definitions.
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
     * @return array<string, StatusView>
     */
    private static function compile(string $workflow): array
    {
        // The raw status, as status() reads it: details included. A config or workflow view
        // replacing `default` opts in (or not) like any other view.
        $views = [self::DEFAULT_VIEW => StatusView::make(self::DEFAULT_VIEW)->withDetails()];

        foreach ((array) config('flowra.status_views.views', []) as $name => $definition) {
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('Status views in config must be keyed by view name.');
            }

            if (! is_array($definition) && ! $definition instanceof StatusView) {
                throw new RuntimeException(
                    "Status view [{$name}] in config must be an array or a ".StatusView::class.' instance.'
                );
            }

            $views[$name] = StatusView::fromArray($name, $definition);
        }

        foreach (self::workflowViews($workflow) as $view) {
            $views[$view->name] = $view;
        }

        return $views;
    }

    /**
     * @param  class-string  $workflow
     * @return array<int, StatusView>
     */
    private static function workflowViews(string $workflow): array
    {
        if (! method_exists($workflow, 'statusViews')) {
            return [];
        }

        $views = $workflow::statusViews();

        foreach ($views as $view) {
            if (! $view instanceof StatusView) {
                throw new RuntimeException(
                    $workflow.'::statusViews() must return an array of '.StatusView::class.' objects.'
                );
            }
        }

        return array_values($views);
    }
}
