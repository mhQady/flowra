<?php

namespace Flowra\Actions;

use Closure;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use MhQady\Flowra\Actions\Contracts\TransitionAction;

class ActionRunner
{
    public function __construct(
        protected Dispatcher $bus
    ) {
    }

    /**
     * @param  array<int,Closure|string|array{0:class-string,1?:array}>  $actions
     *         Each item can be:
     *         - Closure $fn(array $context): void
     *         - 'Some\Class\SendEmail'
     *         - ['Some\Class\SendEmail', ['foo' => 'bar']] // ctor args or set-up params
     *
     * @param  array  $context  (see contract)
     */
    public function run(array $actions, array $context = []): void
    {
        $context['when'] ??= Carbon::now();

        foreach ($actions as $action) {
            // Closure
            if ($action instanceof Closure) {
                $action($context);
                continue;
            }

            // ['Class', params] or 'Class'
            [$class, $params] = is_array($action)
                ? [$action[0], (array) ($action[1] ?? [])]
                : [$action, []];

            $instance = App::make($class, $params);

            // If the class is invokable, prefer __invoke(array $context)
            if (is_callable($instance)) {
                $callable = $instance;
                // Queue if the class signals ShouldQueue
                if ($instance instanceof ShouldQueue) {
                    $this->bus->dispatch(function () use ($callable, $context) {
                        $callable($context);
                    });
                } else {
                    $callable($context);
                }
                continue;
            }

            // If it implements our contract
            if ($instance instanceof TransitionAction) {
                if ($instance instanceof ShouldQueue) {
                    // Dispatch to queue via Bus to keep things simple
                    $this->bus->dispatch(new class($instance, $context) implements ShouldQueue {
                        public function __construct(public TransitionAction $action, public array $context)
                        {
                        }

                        public function handle(): void
                        {
                            $this->action->handle($this->context);
                        }
                    });
                } else {
                    $instance->handle($context);
                }
                continue;
            }

            // Fallback: method 'handle' if present
            if (method_exists($instance, 'handle')) {
                $instance->handle($context);
                continue;
            }

            throw new \InvalidArgumentException("Unsupported action type for: ".(is_string($class) ? $class : get_debug_type($action)));
        }
    }
}
