<?php


namespace Flowra\DTOs;

class GuardDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $message = null,
        public ?string $code = null,
    ) {}

    public static function allow(): self { return new self(true); }
    public static function deny(?string $message = null, ?string $code = null): self {
        return new self(false, $message, $code);
    }
}