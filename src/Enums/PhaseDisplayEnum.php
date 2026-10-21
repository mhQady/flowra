<?php

namespace Flowra\Enums;

use InvalidArgumentException;

/**
 * How an entry whose state sits inside a phase is presented — in status views and registry views.
 *
 * STATE_ONLY — the state, as always (the default; output identical to before phase display).
 * PHASE_ONLY — the phase in place of the state: key and label are the phase's.
 * WITH_PHASE — the state together with its phase: key unchanged, plus a combined display label.
 *
 * An entry outside any phase is presented as STATE_ONLY whatever the mode.
 */
enum PhaseDisplayEnum: string
{
    use BaseEnum;

    case STATE_ONLY = 'state';
    case PHASE_ONLY = 'phase';
    case WITH_PHASE = 'with_phase';

    public static function resolve(self|string $mode): self
    {
        if ($mode instanceof self) {
            return $mode;
        }

        return self::tryFrom($mode) ?? throw new InvalidArgumentException(
            "Unknown phase display mode [{$mode}]; expected one of: ".implode(', ', self::values())
        );
    }
}
