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

        return Cache::store(config('flowra.cache_driver'))->get(self::k($workflow, $key));
    }

    /**
     * Cache the computed value only when the key is missing, without reading existing payloads.
     */
    public static function rememberIfMissing(string $workflow, string $key, \Closure $callback): void
    {
        if (!config('flowra.cache_workflows')) {
            return;
        }

        $store = Cache::store(config('flowra.cache_driver'));
        $cacheKey = self::k($workflow, $key);

        try {
            if ($store->has($cacheKey)) {
                return;
            }
        } catch (\Throwable $e) {
            // Clear potentially broken entry and proceed to rebuild it
            $store->forget($cacheKey);
        }

        $value = $callback();

        try {
            $store->forever($cacheKey, $value);
        } catch (\Throwable $e) {
            // If writing fails, nothing else to do
        }
    }

    public static function remember(string $workflow, string $key, \Closure $callback): mixed
    {
//        if (!config('flowra.cache_workflows')) {
//            return $callback();
//        }

        $store = Cache::store(config('flowra.cache_driver'));
        $cacheKey = self::k($workflow, $key);

        // Guard against stale payloads (e.g. enum renames) causing unserialize errors
        try {
            if ($store->has($cacheKey)) {
                return $store->get($cacheKey);
            }
        } catch (\Throwable $e) {
            // If reading failed, remove the broken entry and rebuild it
            $store->forget($cacheKey);
        }

        $value = $callback();

        try {
            $store->forever($cacheKey, $value);
        } catch (\Throwable $e) {
            // If writing fails, just return the fresh value without caching
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
        Cache::store(config('flowra.cache_driver'))->forget(self::k($workflow, 'states'));
    }

    public static function forgetAll(): array
    {
        if (!config('flowra.cache_workflows')) {
            return [];
        }

        // This implementation assumes Redis or Database cache.
        // File / Memcached drivers cannot safely list keys.
        $store = config('flowra.cache_driver');
        $workflows = [];

        // Redis driver
        if ($store === 'redis') {
            $cursor = 0;

            do {
                [$cursor, $keys] = Redis::scan(
                    $cursor,
                    'MATCH',
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
            } while ($cursor != 0);
        }

        // Database cache driver
        if ($store === 'database') {
            $keys = \DB::table('cache')
                ->where('key', 'like', 'flowra:workflow:%')
                ->pluck('key');

            foreach ($keys as $key) {
                $parts = explode(':', $key);
                $workflow = $parts[2] ?? null;

                if ($workflow) {
                    $workflows[$workflow] = true;
                    Cache::store($store)->forget($key);
                }
            }
        }

        return array_keys($workflows);
    }

    private static function k(string $workflow, string $key): string
    {
        return "flowra:workflow:{$workflow}:{$key}";
    }
}
