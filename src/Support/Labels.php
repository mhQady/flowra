<?php

namespace Flowra\Support;

use Illuminate\Support\Str;

/**
 * Label resolution shared by every read layer — registry entries and status entries name
 * states and phases the same way, so one audience never sees two names for one step.
 *
 * Every lookup falls back rather than failing: a missing translation becomes a humanized key
 * ("under_review" => "Under Review"), never the raw lang key.
 */
final class Labels
{
    /**
     * A declared translation, or null when the key resolves to itself — i.e. nothing was
     * declared and the caller should fall back.
     */
    public static function translate(string $key): ?string
    {
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : null;
    }

    /**
     * A declared label, used as a translation key and falling back to itself when it is a
     * literal — the contract of Phase::label() and StatusRule::label().
     */
    public static function declared(string $label): string
    {
        $translated = __($label);

        return is_string($translated) ? $translated : $label;
    }

    /**
     * Human name for a state value: flowra::flowra.states.{value}, else a humanized value.
     */
    public static function state(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::translate("flowra::flowra.states.{$value}") ?? Str::headline($value);
    }

    /**
     * Human name for a phase. Resolution order:
     *   1. the label declared on the Phase, through declared();
     *   2. flowra::flowra.phases.{phase};
     *   3. a humanized phase key.
     */
    public static function phase(string $phase, ?string $declared = null): string
    {
        if ($declared !== null) {
            return self::declared($declared);
        }

        return self::translate("flowra::flowra.phases.{$phase}") ?? Str::headline($phase);
    }

    /**
     * A state named together with its phase — the WITH_PHASE display label.
     *
     * Built from the flowra::flowra.with_phase pattern (':phase › :state'), so a locale can
     * reorder the parts or change the separator without code.
     */
    public static function combined(string $phaseLabel, string $stateLabel): string
    {
        $replace = ['phase' => $phaseLabel, 'state' => $stateLabel];
        $combined = __('flowra::flowra.with_phase', $replace);

        return is_string($combined) && $combined !== 'flowra::flowra.with_phase'
            ? $combined
            : "{$phaseLabel} › {$stateLabel}";
    }
}
