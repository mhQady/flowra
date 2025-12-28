<?php

namespace Flowra\Traits\Support;

trait Bootable
{
    /** @var array<class-string, bool> */
    protected static array $booted = [];

    /** @var array<class-string, array<string, list<callable>>> */
    protected static array $listeners = [];

    /** Run once per concrete subclass */
    protected static function bootIfNotBooted(): void
    {
        $class = static::class;

        if (isset(static::$booted[$class])) {
            return;
        }

        // 1) class-level boot
        if (method_exists($class, 'boot')) {
            $class::boot();
        }

        // 2) trait-level booters: boot{Trait}
        foreach (static::classTraitsRecursive($class) as $trait) {
            $method = 'boot'.static::shortName($trait);
            if (method_exists($class, $method)) {
                $class::$method();
            }
        }

        static::$booted[$class] = true;
    }

    /** Run on every new instance */
    protected function initializeTraits(): void
    {
        foreach (static::classTraitsRecursive(static::class) as $trait) {
            $method = 'initialize'.static::shortName($trait);
            if (method_exists($this, $method)) {
                $this->{$method}();
            }
        }

        if (method_exists($this, 'initialize')) {
            $this->initialize();
        }
    }

    /** Simple per-class event bus (optional but handy) */
//    public static function on(string $event, callable $listener): void
//    {
//        $class = static::class;
//        static::$listeners[$class][$event][] = $listener;
//    }
//
//    public function fire(string $event, mixed ...$args): void
//    {
//        $class = static::class;
//        foreach (static::$listeners[$class][$event] ?? [] as $cb) {
//            $cb($this, ...$args);
//        }
//    }


    /**
     *  Returns all traits used by the class and its parent classes
     *
     * @param  string  $class
     * @return array<class-string>
     */
    private static function classTraitsRecursive(string $class): array
    {
        $traits = [];

        // Fetch traits for the class and all parents in one call.
        foreach (class_parents($class) + [$class => $class] as $cls) {
            $traits += class_uses_recursive($cls);
        }

        return array_values($traits);
    }

    private static function shortName(string $fqcn): string
    {
        return str_replace('\\', '', substr($fqcn, strrpos($fqcn, '\\') + 1));
    }
}
