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
     * Get template variables for FFmpeg profile rendering.
     * The 'args' field stores JSON-encoded key-value pairs for template substitution.
     * 
     * Example args field: {"video_bitrate": "3M", "audio_bitrate": "192k"}
     * 
     * @param array $additionalVars Optional additional variables to merge
     * @return array Template variables as associative array
     */
    public function getTemplateVariables(array $additionalVars = []): array
    {
        $variables = [];
        
        // Parse args as JSON if it's set
        if ($this->args) {
            $decoded = json_decode($this->args, true);
            if (is_array($decoded)) {
                $variables = $decoded;
            }
        }
        
        // Merge with additional variables (additional vars take precedence)
        return array_merge($variables, $additionalVars);
    }

    /**
     * Get a formatted profile name for API usage.
     * This should match one of the predefined profiles in the Python transcoding system.
     * Available profiles: default, hq, lowlatency, 720p, 1080p, hevc, audio
     */
    public function getProfileName(): string
    {
        return strtolower(str_replace([' ', '-'], '_', $this->name));
    }
}
