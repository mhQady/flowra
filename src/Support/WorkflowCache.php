<?php

namespace Flowra\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class WorkflowCache
{
    public static function get(string $workflow, string $key): mixed
    {
        if (!config('flowra.cache_workflows')) {
            return null;
        }

        $store = Cache::store(config('flowra.cache_driver'));
        $cacheKey = self::k($workflow, $key);

        try {
            return $store->get($cacheKey);
        } catch (\Throwable $e) {
            // Drop corrupted entries and continue without cached value
            $store->forget($cacheKey);
        }

        return null;
    }

    public static function remember(string $workflow, string $key, \Closure $callback): mixed
    {
        // Always compute the value so callers can hydrate their properties even if caching is disabled.
        $cacheEnabled = config('flowra.cache_workflows');

        $cacheKey = self::k($workflow, $key);
        $store = $cacheEnabled ? Cache::store(config('flowra.cache_driver')) : null;

        if ($cacheEnabled && $store) {
            // Guard against stale payloads (e.g. enum renames) causing unserialize errors
            try {
                if ($store->has($cacheKey)) {
                    return $store->get($cacheKey);
                }
            } catch (\Throwable $e) {
                // If reading failed, remove the broken entry and rebuild it
                $store->forget($cacheKey);
            }
        }

        // Guard against stale payloads (e.g. enum renames) causing unserialize errors
        $value = $callback();

        if ($cacheEnabled && $store) {
            try {
                $store->forever($cacheKey, $value);
            } catch (\Throwable $e) {
                // If writing fails, just return the fresh value without caching
            }
        }

        return $value;
    }

    public static function put(string $workflow, string $key, mixed $value): void
    {
        if (!config('flowra.cache_workflows')) {
            return;
        }

        Cache::store(config('flowra.cache_driver'))->forever(self::k($workflow, $key), $value);
    }

    public static function forget(string $workflow): void
    {
        if (!config('flowra.cache_workflows')) {
            return;
        }

        Cache::store(config('flowra.cache_driver'))->forget(self::k($workflow, 'transitions'));
        Cache::store(config('flowra.cache_driver'))->forget(self::k($workflow, 'statesEnum'));
        Cache::store(config('flowra.cache_driver'))->forget(self::k($workflow, 'states'));
        Cache::store(config('flowra.cache_driver'))->forget(self::k($workflow, 'stateGroups'));
        Cache::store(config('flowra.cache_driver'))->forget(self::k($workflow, 'stateGroupParents'));
    }

    public static function forgetAll(): array
    {
        if (!config('flowra.cache_workflows')) {
            return [];
        }

        $store = config('flowra.cache_driver');
        $workflows = [];

        // Redis driver
        if ($store === 'redis') {
            static::clearRedisCache($workflows);
        }

        // Database cache driver
        if ($store === 'database') {
            static::clearDatabaseCache($workflows, $store);
        }

        return array_keys($workflows);
    }

    private static function k(string $workflow, string $key): string
    {
        return "flowra:workflow:{$workflow}:{$key}";
    }

    private static function clearRedisCache(array &$workflows): void
    {
        $cursor = 0;

        do {

            [$cursor, $keys] = Redis::scan($cursor, 'MATCH',
                'flowra:workflow:*:*',
                'COUNT',
                100
            );

            foreach ($keys as $key) {
                // Extract workflow name from: flowra:workflow:{workflow}:{item}
                $parts = explode(':', $key);
                $workflow = $parts[2] ?? null;

                if ($workflow) {
                    $workflows[$workflow] = true;
                    Redis::del($key);
                }
            }
        } while ($cursor !== 0);
    }

    private static function clearDatabaseCache(array &$workflows, string $store): void
    {
        \DB::table('cache')->where('key', 'like', '%flowra:workflow:%')
            ->delete();
    }
}
