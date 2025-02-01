<?php

namespace App\Models;

use App\Enums\EpgStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Epg extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'synced' => 'datetime',
        'user_id' => 'integer',
        'uploads' => 'array',
        'status' => EpgStatus::class,
    ];

    public function getFolderPathAttribute(): string
    {
        return "epg/{$this->uuid}";
    }

    public function getFilePathAttribute(): string
    {
        return "epg/{$this->uuid}/epg.xml";
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(EpgChannel::class);
    }
}
