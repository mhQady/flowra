<?php

namespace Tests\Fixtures\Models;

use Flowra\Models\Registry as BaseRegistry;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A host's registry model: one relation off the row, and three reading applied_by.
 */
class Registry extends BaseRegistry
{
    public function files(): HasMany
    {
        return $this->hasMany(Attachment::class, 'registry_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function actorNotes(): HasMany
    {
        return $this->hasMany(Note::class, 'user_id', 'applied_by');
    }

    public function actorMorph(): MorphTo
    {
        return $this->morphTo('actorMorph', 'applied_by_type', 'applied_by');
    }
}
