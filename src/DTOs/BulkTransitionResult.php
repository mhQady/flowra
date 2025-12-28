<?php

namespace Flowra\DTOs;

class BulkTransitionResult
{
    /**
     * @param  array<int, array{target: mixed, status: \Flowra\Models\Status|null}>  $successes
     * @param  array<int, array{target: mixed, exception: \Throwable}>  $failures
     */
    public function __construct(
        public readonly array $successes = [],
        public readonly array $failures = [],
    ) {
    }

    public function hasFailures(): bool
    {
        return !empty($this->failures);
    }

    public function successfulCount(): int
    {
        return count($this->successes);
    }

    public function failedCount(): int
    {
        return count($this->failures);
    }
}
