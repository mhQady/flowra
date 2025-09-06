<?php

namespace Flowra\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
readonly class TransitionMeta
{
    public function __construct(public ?string $title = null)
    {
    }
}