<?php

namespace Tests\Fixtures\Models;

use Flowra\Models\Status as FlowraStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A host application's status model: the Flowra one plus the relations the host declares.
 */
class Status extends FlowraStatus
{
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
