<?php

namespace Flowra\Support;

use Illuminate\Support\Str;

class WorkflowPathResolver
{
    /**
     * Resolve the configured base directory for generated workflows.
     */
    public static function basePath(): string
    {
        $path = config('flowra.workflows_path', 'app/Workflows');

        if (!Str::startsWith($path, ['/', '\\']) && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
            $path = base_path($path);
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Resolve the directory where the supplied workflow should live.
     */
    public static function workflowDirectory(string $workflowShort): string
    {
        return static::basePath().DIRECTORY_SEPARATOR.$workflowShort;
    }
}
