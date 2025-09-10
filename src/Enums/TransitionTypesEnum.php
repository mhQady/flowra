<?php

namespace Flowra\Enums;

enum TransitionTypesEnum: int
{
    use BaseEnum;

    case TRANSITION = 1;
    case RESET = 2;
}