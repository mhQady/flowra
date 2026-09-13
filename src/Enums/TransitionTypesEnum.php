<?php

namespace Flowra\Enums;

enum TransitionTypesEnum: int
{
    use BaseEnum;

    /** A move the schema declares, applied through a transition. */
    case TRANSITION = 1;

    /** A forced state change from jumpTo(), outside the schema. */
    case RESET = 2;

    /**
     * A whole phase, stood in for by a collapsed registry entry.
     *
     * Read-time only: no registry row is ever written with this type — a run of rows is
     * given it when the read layer folds them into one entry.
     */
    case PHASE = 3;
}