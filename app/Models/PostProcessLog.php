<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostProcessLog extends Model
{
    public function process(): BelongsTo
    {
        return $this->belongsTo(PostProcess::class);
    }
}
