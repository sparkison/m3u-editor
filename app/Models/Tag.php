<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Tags\Tag as SpatieTag;

class Tag extends SpatieTag
{
    /**
     * Get all channels associated with this tag (group).
     */
    public function channels(): BelongsToMany
    {
        return $this->morphedByMany(Channel::class, 'taggable', 'taggables');
    }
}
