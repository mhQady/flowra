<?php

namespace Flowra\Enums;

enum SubflowStatusTypeEnum: int
{
    use BaseEnum;

    case START = 1;

    case TRANSITION = 2;
    case EXIT = 3;
}