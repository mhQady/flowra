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
            forward_static_call([$class, 'boot']);
        }

        // 2) trait-level booters: boot{Trait}
        foreach (static::__classTraitsRecursive($class) as $trait) {
            $method = 'boot'.static::__shortName($trait);
            if (method_exists($class, $method)) {
                forward_static_call([$class, $method]);
            }
        }

        static::$booted[$class] = true;
    }

    /** Run on every new instance */
    protected function initializeTraits(): void
    {
        foreach (static::__classTraitsRecursive(static::class) as $trait) {
            $method = 'initialize'.static::__shortName($trait);
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
    private static function __classTraitsRecursive(string $class): array
    {
        $traits = [];
        do {
            $traits = array_merge($traits, class_uses($class));
        } while ($class = get_parent_class($class));

        // add traits used by traits
        $searched = $traits;
        while (!empty($searched)) {
            $new = class_uses(array_pop($searched));
            $traits = array_merge($traits, $new);
            $searched = array_merge($searched, $new);
        }

        return array_values(array_unique($traits));
    }

    private static function __shortName(string $fqcn): string
    {
        return str_replace('\\', '', substr($fqcn, strrpos($fqcn, '\\') + 1));
    }
}
