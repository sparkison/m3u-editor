<?php

namespace App\Models;

use App\Enums\Status;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpgMap extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'processing' => 'boolean',
        // 'override' => 'boolean',
        'progress' => 'float',
        'user_id' => 'integer',
        'epg_id' => 'integer',
        'channel_count' => 'integer',
        'mapped_count' => 'integer',
        'settings' => 'array',
        'status' => Status::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function epg(): BelongsTo
    {
        return $this->belongsTo(Epg::class);
    }
}
