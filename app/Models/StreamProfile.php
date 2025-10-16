<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StreamProfile extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        // ...
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    public function customPlaylists(): HasMany
    {
        return $this->hasMany(CustomPlaylist::class);
    }

    public function mergedPlaylists(): HasMany
    {
        return $this->hasMany(MergedPlaylist::class);
    }

    public function playlistAliases(): HasMany
    {
        return $this->hasMany(PlaylistAlias::class);
    }

    /**
     * Get the FFmpeg args as an array, replacing placeholders with actual values
     */
    public function getArgsArray(?array $parameters = []): array
    {
        $args = $this->args ?? '';

        // Replace placeholders with actual values
        foreach ($parameters as $key => $value) {
            $args = str_replace("{{$key}}", $value, $args);
        }

        // Remove any unreplaced placeholders with default values
        $args = preg_replace('/\{(\w+)\|([^}]+)\}/', '$2', $args);

        // Remove any remaining unreplaced placeholders
        $args = preg_replace('/\{[^}]+\}/', '', $args);

        // Split into array and filter empty values
        return array_filter(explode(' ', trim($args)));
    }

    /**
     * Get a formatted profile name for API usage
     */
    public function getProfileName(): string
    {
        return strtolower(str_replace([' ', '-'], '_', $this->name));
    }
}
