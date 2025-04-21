<?php

namespace App\Models;

use App\Pivots\PostProcessPivot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostProcess extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'enabled' => 'boolean',
        'user_id' => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processes(): HasMany
    {
        return $this->hasMany(PostProcessPivot::class, 'post_process_id')
            ->where('authenticatable_type', '!=', null) // Ensure it's a morph relation
            ->whereHas('model');
    }
}
