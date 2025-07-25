<?php

namespace App\Models;

use App\Enums\Status;
use App\Services\EpgCacheService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Cache;
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
        'status' => Status::class,
        'processing' => 'boolean',
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

    public function epgMaps(): HasMany
    {
        return $this->hasMany(EpgMap::class);
    }

    public function postProcesses(): MorphToMany
    {
        return $this->morphToMany(PostProcess::class, 'processable');
    }

    public function isCached(): bool
    {
        // Use the EpgCacheService to check if the cache is valid
        // Store value in cache for performance
        // This will prevent multiple calls to the service for the same EPG
        // and improve performance, especially for large EPG datasets.
        // This is a simple check to see if the cache is valid.
        // If the cache is valid, it will return true, otherwise false.
        $cacheKey = "epg_cache_valid_{$this->uuid}";
        $valid = Cache::remember($cacheKey, 60 * 15, function () {
            $cacheService = new EpgCacheService();
            return $cacheService->isCacheValid($this);
        });
        return $valid;
    }
}
