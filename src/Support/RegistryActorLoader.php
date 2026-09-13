<?php

namespace Flowra\Support;

use Closure;
use Flowra\DTOs\RegistryEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Resolves the actor every rendered registry entry is attributed to, in one query.
 *
 * It reads RegistryEntry::$appliedBy — the actor the entry renders as, after masks, appliers and
 * the system user — never the actor the row recorded. A masked entry therefore resolves to its
 * stand-in account, or to nothing when the stand-in is a key rather than an id, and the actor it
 * hides is never selected.
 */
final class RegistryActorLoader
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<int|string, string|Closure>  $with  relations loaded on the actor
     */
    public function __construct(
        private readonly string $model,
        private readonly array $with = [],
    ) {
    }

    /**
     * @param  array<int|string, string|Closure>  $with
     */
    public static function make(array $with = []): self
    {
        return new self(WorkflowModels::actor(), $with);
    }

    /**
     * The same entries — children included — each carrying the model its actor resolved to.
     *
     * @template TEntries of Collection<int, RegistryEntry>
     *
     * @param  TEntries  $entries
     * @return TEntries
     */
    public function load(Collection $entries): Collection
    {
        $integerKey = in_array((new $this->model())->getKeyType(), ['int', 'integer'], true);

        $ids = [];
        $this->collectIds($entries, $integerKey, $ids);

        $actors = $this->actors(array_values($ids));

        return $entries->map(fn (RegistryEntry $entry) => $this->attach($entry, $actors));
    }

    /**
     * @param  array<string, Model>  $actors
     */
    private function attach(RegistryEntry $entry, array $actors): RegistryEntry
    {
        return $entry->withActor(
            $entry->appliedBy === null ? null : ($actors[(string) $entry->appliedBy] ?? null),
            $entry->children->map(fn (RegistryEntry $child) => $this->attach($child, $actors))
        );
    }

    /**
     * Every rendered actor, across the entries and their children, that could be a key of the
     * actor model — keyed by its string form so an id seen twice is selected once.
     *
     * A stand-in like 'review_committee' is dropped against an integer key rather than compared
     * with it: some databases reject a non-numeric string in an integer comparison outright.
     *
     * @param  iterable<RegistryEntry>  $entries
     * @param  array<string, int|string>  $ids
     */
    private function collectIds(iterable $entries, bool $integerKey, array &$ids): void
    {
        foreach ($entries as $entry) {
            $id = $entry->appliedBy;

            if ($id !== null && (! $integerKey || is_int($id) || ctype_digit($id))) {
                $ids[(string) $id] = $integerKey ? (int) $id : $id;
            }

            $this->collectIds($entry->children, $integerKey, $ids);
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<string, Model>
     */
    private function actors(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->model::query()
            ->with($this->with)
            ->whereKey($ids)
            ->get()
            ->keyBy(static fn (Model $actor) => (string) $actor->getKey())
            ->all();
    }
}
