<?php

namespace Flowra\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

class Registry extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        $this->table = config('flowra.tables.registry', 'statuses_registry');
        parent::__construct($attributes);
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether a relation on this model reads the actor a row recorded — any relation keyed on
     * applied_by on this side.
     *
     * A registry view resolves these against the actor an entry *renders as* rather than loading
     * them off the row, so a mask or a declared applier cannot be undone through a relation (see
     * Support\RegistryRelationLoader). Override to widen the check to a relation that reads
     * applied_by in a way its key names do not show.
     */
    public function isActorRelation(string $relation): bool
    {
        if (! $this->isRelation($relation)) {
            return false;
        }

        $query = Relation::noConstraints(fn () => $this->{$relation}());

        return match (true) {
            $query instanceof BelongsTo => $query->getForeignKeyName() === 'applied_by',
            $query instanceof HasOneOrMany,
            $query instanceof HasOneOrManyThrough => $query->getLocalKeyName() === 'applied_by',
            $query instanceof BelongsToMany => $query->getParentKeyName() === 'applied_by',
            default => false,
        };
    }

    protected function comment(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => json_decode($value, true),
            set: fn ($value) => json_encode($value)
        );
    }
}
