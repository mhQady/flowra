<?php

namespace Flowra\Support;

use Closure;
use Flowra\DTOs\RegistryEntry;
use Flowra\Models\Registry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Resolves the relations a registry view asked for that read the actor — any relation on the
 * registry model keyed on applied_by (Registry::isActorRelation()) — against the actor each entry
 * *renders as*, never the one its row recorded.
 *
 * Loading such a relation off the rows would undo attribution: a masked entry would carry the very
 * actor its mask hides, and a phase entry, which has no row, would carry nothing. Instead every
 * distinct rendered actor gets one unsaved registry model holding only that applied_by, and
 * Eloquent's own eager loading runs over those — so any relation kind, nesting, constraint or
 * withDefault() the host declared behaves as it would on a row, in one query per relation.
 */
final class RegistryRelationLoader
{
    /**
     * @param  array<string, array<int|string, string|Closure>>  $relations  actor relation name => its with() entries
     */
    public function __construct(
        private readonly Registry $model,
        private readonly array $relations,
    ) {
    }

    /**
     * @param  array<string, array<int|string, string|Closure>>  $relations
     */
    public static function make(array $relations): self
    {
        return new self(self::model(), $relations);
    }

    /**
     * Split with() entries into relations loaded off the rows and actor relations resolved here,
     * grouped by top-level relation name — 'actor', 'actor.roles' and ['actor' => fn] all belong
     * to 'actor'.
     *
     * @param  array<int|string, string|Closure>  $with
     * @return array{rows: array<int|string, string|Closure>, actors: array<string, array<int|string, string|Closure>>}
     *
     * @throws InvalidArgumentException for a MorphTo over applied_by, which a stand-in cannot resolve.
     */
    public static function split(array $with): array
    {
        $rows = [];
        $actors = [];

        if ($with === []) {
            return ['rows' => $rows, 'actors' => $actors];
        }

        $model = self::model();

        foreach ($with as $key => $value) {
            $name = self::relationName(is_int($key) ? (string) $value : $key);

            if (! $model->isActorRelation($name)) {
                self::push($rows, $key, $value);

                continue;
            }

            // A stand-in is an id with no morph type, so a MorphTo has nothing to resolve it by.
            if (Relation::noConstraints(fn () => $model->{$name}()) instanceof MorphTo) {
                throw new InvalidArgumentException(__('flowra::flowra.registry_actor_relation_morph_to', [
                    'relation' => $name,
                ]));
            }

            $actors[$name] ??= [];
            self::push($actors[$name], $key, $value);
        }

        return ['rows' => $rows, 'actors' => $actors];
    }

    /**
     * The same entries — children included — each carrying the actor relations resolved against
     * its rendered actor, beside whatever row relations it already holds.
     *
     * @template TEntries of Collection<int, RegistryEntry>
     *
     * @param  TEntries  $entries
     * @return TEntries
     */
    public function load(Collection $entries): Collection
    {
        if ($this->relations === []) {
            return $entries;
        }

        $actors = [];
        self::collectActors($entries, $actors);

        $resolved = [];

        foreach ($this->relations as $name => $eagerLoads) {
            $resolved[$name] = $this->resolve($name, $eagerLoads, $actors);
        }

        return $entries->map(fn (RegistryEntry $entry) => $this->attach($entry, $resolved));
    }

    /**
     * One proxy per actor the relation can resolve, loaded together, plus one holding the
     * relation's empty value — null, an empty collection, or its withDefault() — for every entry
     * with no usable actor.
     *
     * @param  array<int|string, string|Closure>  $eagerLoads
     * @param  array<string, int|string>  $actors
     * @return array{proxies: array<string, Registry>, empty: Registry}
     */
    private function resolve(string $name, array $eagerLoads, array $actors): array
    {
        $relation = Relation::noConstraints(fn () => $this->model->{$name}());
        $proxies = [];

        foreach ($actors as $key => $actor) {
            if (($id = self::keyFor($relation, $actor)) !== null) {
                $proxies[$key] = $this->proxy($id);
            }
        }

        if ($proxies !== []) {
            (new EloquentCollection(array_values($proxies)))->load($eagerLoads);
        }

        $empty = $this->proxy(null);
        $relation->initRelation([$empty], $name);

        return ['proxies' => $proxies, 'empty' => $empty];
    }

    /**
     * @param  array<string, array{proxies: array<string, Registry>, empty: Registry}>  $resolved
     */
    private function attach(RegistryEntry $entry, array $resolved): RegistryEntry
    {
        $relations = [];

        foreach ($resolved as $name => ['proxies' => $proxies, 'empty' => $empty]) {
            $proxy = $entry->appliedBy === null ? $empty : ($proxies[(string) $entry->appliedBy] ?? $empty);

            $relations[$name] = $proxy->getRelation($name);
        }

        return $entry->withRelations(
            $relations,
            $entry->children->map(fn (RegistryEntry $child) => $this->attach($child, $resolved))
        );
    }

    /**
     * The applied_by a proxy carries for this actor, or null when it cannot name a record.
     *
     * applied_by is an unsigned integer column, so a stand-in key like 'review_committee' names
     * nothing — and some databases reject it in an integer comparison outright. The one relation
     * whose far side Flowra can see is a BelongsTo onto a primary key: when that key is not an
     * integer, strings are real ids and pass through.
     */
    private static function keyFor(Relation $relation, int|string $actor): int|string|null
    {
        if (is_int($actor) || ctype_digit($actor)) {
            return (int) $actor;
        }

        if (! $relation instanceof BelongsTo) {
            return null;
        }

        $related = $relation->getRelated();

        return $relation->getOwnerKeyName() === $related->getKeyName()
            && ! in_array($related->getKeyType(), ['int', 'integer'], true)
            ? $actor
            : null;
    }

    /**
     * Every rendered actor across the entries and their children, keyed by its string form so an
     * id seen twice is resolved once.
     *
     * @param  iterable<RegistryEntry>  $entries
     * @param  array<string, int|string>  $actors
     */
    private static function collectActors(iterable $entries, array &$actors): void
    {
        foreach ($entries as $entry) {
            if ($entry->appliedBy !== null) {
                $actors[(string) $entry->appliedBy] = $entry->appliedBy;
            }

            self::collectActors($entry->children, $actors);
        }
    }

    private function proxy(int|string|null $actor): Registry
    {
        return $this->model->newInstance()->setRawAttributes(['applied_by' => $actor], true);
    }

    /** 'actor', 'actor.roles' and 'actor:id,name' all name the top-level relation 'actor'. */
    private static function relationName(string $relation): string
    {
        return trim(explode(':', explode('.', $relation, 2)[0], 2)[0]);
    }

    /**
     * Keep a with() entry the way Eloquent reads it: a listed name, or [name => constraint].
     *
     * @param  array<int|string, string|Closure>  $bucket
     */
    private static function push(array &$bucket, int|string $key, string|Closure $value): void
    {
        if (is_int($key)) {
            $bucket[] = $value;
        } else {
            $bucket[$key] = $value;
        }
    }

    private static function model(): Registry
    {
        $model = WorkflowModels::registry();

        return new $model();
    }
}
