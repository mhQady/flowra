<?php

namespace Flowra\DTOs;

use Carbon\CarbonInterface;
use Flowra\Enums\TransitionTypesEnum;
use Flowra\Models\Registry;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JsonSerializable;
use UnitEnum;

/**
 * One entry in a rendered registry view.
 *
 * An entry is either a leaf (one registry row, `collapsed = false`) or a parent
 * standing in for a consecutive run of rows sharing a phase key (`collapsed = true`),
 * in which case `children` holds the untouched leaves. Nothing is ever discarded:
 * entries are a read-time projection over the registry, never a replacement for it.
 *
 * `key` is what the entry *is*: the state a leaf landed in, the phase a parent stands for.
 * The move that produced a leaf is kept beside it in `transition` — null on a parent, which
 * stands for several. So a timeline reads as the statuses a model went through, while the
 * transitions that drove it are one field (or one `children`) away.
 *
 * `appliedBy` is the actor the entry *renders as*, which a view or the configured system user
 * may have decided (see Support\RegistryAttribution). `recordedBy` is normally the actor the
 * row itself stored, so the audit truth survives the projection — unless the view *masked*
 * this target, in which case `redacted` is true and `recordedBy` is deliberately null. The
 * untouched trail is then only reachable through `registry()`.
 */
final class RegistryEntry implements Arrayable, JsonSerializable
{
    /**
     * @param  Collection<int, RegistryEntry>  $children
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $transition,
        public readonly ?string $phase,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly array $comment,
        public readonly int|string|null $appliedBy,
        public readonly int $type,
        public readonly ?CarbonInterface $startedAt,
        public readonly ?CarbonInterface $endedAt,
        public readonly Collection $children,
        public readonly bool $collapsed,
        public readonly ?string $registryId = null,
        public readonly int|string|null $recordedBy = null,
        public readonly bool $attributed = false,
        public readonly bool $redacted = false,
        private readonly ?string $phaseLabelKey = null,
        private readonly ?string $statesEnum = null,
    ) {
    }

    /**
     * Build a leaf entry from a single registry row, keyed by the state it landed in.
     *
     * A row with no landing state falls back to its transition key — the column is not
     * nullable, so this is a guard rather than a path the read layer takes.
     */
    public static function fromRow(
        Registry $row,
        ?string $phase = null,
        ?string $statesEnum = null,
        ?RegistryActor $actor = null,
        ?string $phaseLabel = null,
    ): self {
        $timestamp = $row->created_at;

        $actor ??= RegistryActor::recorded($row->applied_by);

        return new self(
            // A leaf IS the status it reached: the transition that got it there is kept
            // beside it, not used as the entry's identity.
            key: $row->to === null ? (string) $row->transition : (string) $row->to,
            transition: (string) $row->transition,
            phase: $phase,
            from: $row->from === null ? null : (string) $row->from,
            to: $row->to === null ? null : (string) $row->to,
            comment: self::normalizeComment($row->comment),
            appliedBy: $actor->id,
            type: (int) $row->type,
            startedAt: $timestamp,
            endedAt: $timestamp,
            children: new Collection(),
            collapsed: false,
            registryId: $row->getKey() === null ? null : (string) $row->getKey(),
            recordedBy: $actor->recorded,
            attributed: $actor->attributed,
            redacted: $actor->redacted,
            phaseLabelKey: $phaseLabel,
            statesEnum: $statesEnum,
        );
    }

    /**
     * Build a parent entry standing in for a consecutive run of leaves — one phase.
     *
     * Keyed by the phase, and it carries no `transition` of its own: it stands for every
     * move inside the run, all of them still on `children`.
     *
     * from/to span the run (first row's from, last row's to), the timestamps keep both
     * ends, and comments are merged in order — safe because conditions filter rows *before*
     * collapsing, so a parent only ever merges rows the viewer was already allowed to see.
     *
     * The actor defaults to whoever closed the phase; a view, a mask or the system-user
     * fallback can put a single applier there instead (Support\RegistryAttribution).
     *
     * The entry's type is PHASE — a run is its own kind of thing, not a copy of whichever
     * child happened to close it. Its children keep the types their rows recorded.
     *
     * @param  array<int, RegistryEntry>  $children
     */
    public static function fromRun(
        array $children,
        string $phase,
        ?string $label = null,
        ?string $statesEnum = null,
        ?RegistryActor $actor = null,
    ): self {
        $first = $children[0];
        $last = $children[count($children) - 1];

        $actor ??= new RegistryActor($last->appliedBy, $last->recordedBy);

        $comment = [];

        foreach ($children as $child) {
            foreach ($child->comment as $line) {
                $comment[] = $line;
            }
        }

        return new self(
            key: $phase,
            transition: null,
            phase: $phase,
            from: $first->from,
            to: $last->to,
            comment: $comment,
            appliedBy: $actor->id,
            type: TransitionTypesEnum::PHASE->value,
            startedAt: $first->startedAt,
            endedAt: $last->endedAt,
            children: new Collection($children),
            collapsed: true,
            recordedBy: $actor->recorded,
            attributed: $actor->attributed,
            redacted: $actor->redacted,
            phaseLabelKey: $label,
            statesEnum: $statesEnum,
        );
    }

    /**
     * Human label for the entry — the state it stands at, not the move that got it there.
     *
     * A leaf is named after the state it landed in (`to`), so a timeline reads as the
     * statuses the model went through. A collapsed entry stands in for a whole state
     * group, so it is named after the group instead of any single state inside it.
     *
     * A collapsed entry keeps its own key and label and resolves through phaseLabel(). A
     * leaf looks only at the state it landed in: flowra::flowra.states.{to}, else a
     * humanized state value. flowra::flowra.transitions.{key} is the fallback for the one
     * case a state cannot answer — a row that recorded no landing state at all.
     */
    public function label(): string
    {
        if ($this->collapsed) {
            return $this->phaseLabel() ?? Str::headline($this->key);
        }

        return $this->stateLabel($this->to)
            ?? $this->translate("flowra::flowra.transitions.{$this->transition}")
            ?? Str::headline($this->key);
    }

    /**
     * Human name of the state the entry moved out of — null when it started from nothing.
     *
     * States resolve under flowra::flowra.states.{value}, falling back to a humanized
     * value. A collapsed entry spans a run, so this names the state the run began at.
     */
    public function fromLabel(): ?string
    {
        return $this->stateLabel($this->from);
    }

    /**
     * Human name of the state the entry landed in — the status a leaf is named after, and
     * for a collapsed entry the state its run ended at (not the group; that is label()).
     */
    public function toLabel(): ?string
    {
        return $this->stateLabel($this->to);
    }

    /**
     * Human name of the state group the entry sits in, or null when its landing state
     * belongs to no group.
     *
     * A leaf carries this too, so a row of a phase the view left expanded can still say
     * which step it belonged to. Resolution order:
     *   1. the label declared on the StateGroup, used as a translation key and falling
     *      back to itself when it is a literal;
     *   2. flowra::flowra.phases.{phase};
     *   3. a humanized phase key.
     */
    public function phaseLabel(): ?string
    {
        if ($this->phase === null) {
            return null;
        }

        if ($this->phaseLabelKey !== null) {
            $translated = __($this->phaseLabelKey);

            return is_string($translated) ? $translated : $this->phaseLabelKey;
        }

        return $this->translate("flowra::flowra.phases.{$this->phase}")
            ?? Str::headline($this->phase);
    }

    /**
     * Human name for what kind of thing the entry is — a schema transition, a forced change
     * or a phase.
     *
     * The payload carries the enum's case *value* (see toArray()); translations key on the
     * case *name* instead, because 'flowra.types.transition' reads where '1' does not.
     * Falls back to a humanized case name, and is null when the stored type matches no case.
     */
    public function typeLabel(): ?string
    {
        $type = $this->type();

        if ($type === null) {
            return null;
        }

        // Enum case names are SCREAMING_SNAKE already, so lower() is the snake form —
        // Str::snake() would split every letter of an all-caps name.
        $key = Str::lower($type->name);

        return $this->translate("flowra::flowra.types.{$key}") ?? Str::headline($key);
    }

    public function isCollapsed(): bool
    {
        return $this->collapsed;
    }

    /**
     * Whether the rendered actor was put there by a declaration (a view, the builder) or by
     * the configured system user, rather than read off the rows.
     */
    public function isAttributed(): bool
    {
        return $this->attributed;
    }

    /**
     * Whether the view masked this entry, dropping the actor the row recorded.
     */
    public function isRedacted(): bool
    {
        return $this->redacted;
    }

    /**
     * Whether the entry came from jumpTo() rather than a schema transition. Jumps are
     * never collapsed, so this is only ever true on a leaf — a collapsed entry carries the
     * PHASE type, which is not a forced change.
     */
    public function isJump(): bool
    {
        return $this->type === TransitionTypesEnum::RESET->value;
    }

    /**
     * Every distinct actor behind the entry, in order. A leaf yields at most one.
     *
     * An entry attributed to a single applier yields only that applier: an entry the view
     * presents as one actor must not leak the actors it stands in for through a second
     * accessor. The untouched trail is still one `children` (or `registry()`) away.
     *
     * @return array<int, int|string>
     */
    public function participants(): array
    {
        if ($this->attributed) {
            return $this->appliedBy === null ? [] : [$this->appliedBy];
        }

        if ($this->children->isEmpty()) {
            return $this->appliedBy === null ? [] : [$this->appliedBy];
        }

        $participants = [];

        foreach ($this->children as $child) {
            foreach ($child->participants() as $participant) {
                if (! in_array($participant, $participants, true)) {
                    $participants[] = $participant;
                }
            }
        }

        return $participants;
    }

    public function fromState(): ?UnitEnum
    {
        return $this->resolveState($this->from);
    }

    public function toState(): ?UnitEnum
    {
        return $this->resolveState($this->to);
    }

    public function type(): ?TransitionTypesEnum
    {
        return TransitionTypesEnum::tryFrom($this->type);
    }

    /**
     * The payload carries no `collapsed` flag: `type` already answers it — the two factories
     * keep them in lockstep, so a PHASE type is exactly a collapsed entry. isCollapsed()
     * stays for callers holding the DTO itself.
     *
     * `children` appears only on a phase, which is the only entry that has any. A leaf omits
     * the key rather than repeating an empty array down every level of the tree.
     *
     * Of the actors, only the rendered one travels. recordedBy — the audit truth — stays on
     * the DTO and in registry().
     */
    public function toArray(): array
    {
        $entry = [
            // What the entry is: a leaf's landing state ('docs_ok'), or a phase's group key
            // ('under_review'). Not unique across a timeline — two moves can land on one state.
            'key' => $this->key,
            // Human name for `key`: the landing state's name on a leaf, the group's on a phase.
            'label' => $this->label(),
            // What kind of entry this is.
            'type' => [
                // TransitionTypesEnum value: 1 transition, 2 reset (a jumpTo), 3 phase.
                'key' => $this->type,
                // Its name, from flowra::flowra.types.{case}.
                'label' => $this->typeLabel(),
            ],

            // The transition key that wrote the row. Null on a phase, which stands for
            // several — each child carries its own.
            'transition' => $this->transition,
            // The state group the landing state sits in: {key, label, started_at, ended_at}.
            // The two timings are filled only on a phase entry. Null when the state is
            // ungrouped, and always on a jump.
            'phase' => $this->phaseValue(),

            // The state moved out of: {key, label}. On a phase, where the run began.
            // Null when there was no prior state.
            'from' => $this->stateValue($this->from),
            // The state landed in: {key, label}. On a phase, where the run ended.
            'to' => $this->stateValue($this->to),
            
            // Comment lines recorded with the row, always an array. A phase merges its
            // children's, in order.
            'comment' => $this->comment,
            // The actor the entry renders as: the row's own, or a stand-in a view, mask or
            // the system user put there. On a phase, whoever closed the run by default.
            'applied_by' => $this->appliedBy,
            // When it happened, ISO-8601. A leaf: the row's created_at. A phase: when the run
            // began — its first row's created_at, the same instant as phase.started_at.
            'applied_at' => $this->startedAt?->toIso8601String(),
            // True when applied_by did not come off the rows: a declared applier, a mask, or
            // the system user standing in for an unsigned row.
            'attributed' => $this->attributed,
            // True when a mask claimed the entry: someone acted, and this audience is not
            // told who. Always implies attributed.
            'redacted' => $this->redacted,
        ];

        if ($this->collapsed) {
            // Phase only: the leaves the phase stands for, each in this same shape.
            $entry['children'] = $this->children
                ->map(static fn (self $child) => $child->toArray())
                ->all();
        }

        return $entry;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The phase as it travels in the payload — everything the entry knows about the group
     * it sits in, in one value.
     *
     * Null when the landing state belongs to no group (a jump included, which never carries
     * a phase), so a client tests the field rather than reaching into it.
     *
     * The timings answer "how long was this phase", which only an entry that *stands for*
     * the phase can answer. A leaf is one row inside the phase, not the phase, so it reports
     * null there rather than passing its own moment off as the step's duration — its own
     * span is on the entry's started_at / ended_at.
     *
     * @return array{key: string, label: string, started_at: ?string, ended_at: ?string, seconds: ?int}|null
     */
    private function phaseValue(): ?array
    {
        if ($this->phase === null) {
            return null;
        }

        $spansThePhase = $this->collapsed;

        return [
            'key' => $this->phase,
            'label' => (string) $this->phaseLabel(),
            'started_at' => $spansThePhase ? $this->startedAt?->toIso8601String() : null,
            'ended_at' => $spansThePhase ? $this->endedAt?->toIso8601String() : null,
        ];
    }

    /**
     * A state as it travels in the payload: its raw value and its name, together.
     *
     * Null — not an array of nulls — when there is no state, so a client can test the field
     * itself rather than reaching into it.
     *
     * @return array{key: string, label: string}|null
     */
    private function stateValue(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return ['key' => $value, 'label' => (string) $this->stateLabel($value)];
    }

    /**
     * Human name for a state value: a declared translation, else a humanized value.
     */
    private function stateLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->translate("flowra::flowra.states.{$value}") ?? Str::headline($value);
    }

    /**
     * A declared translation, or null when the key resolves to itself — i.e. nothing was
     * declared and the caller should fall back.
     */
    private function translate(string $key): ?string
    {
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : null;
    }

    private function resolveState(?string $value): ?UnitEnum
    {
        if ($value === null || $this->statesEnum === null || ! enum_exists($this->statesEnum)) {
            return null;
        }

        return method_exists($this->statesEnum, 'tryFrom')
            ? $this->statesEnum::tryFrom($value)
            : null;
    }

    private static function normalizeComment(mixed $comment): array
    {
        if ($comment === null || $comment === '') {
            return [];
        }

        return is_array($comment) ? array_values($comment) : [$comment];
    }
}
