<?php

namespace App\Models;

use App\Enums\ChannelLogoType;
use App\Facades\ProxyFacade;
use App\Traits\PrimaryPlaylistScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process as SymfonyProcess;
use Spatie\Tags\HasTags;

class Channel extends Model
{
    use HasFactory;
    use HasTags;
    use PrimaryPlaylistScope;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'enabled' => 'boolean',
        'channel' => 'integer',
        'shift' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'group_id' => 'integer',
        'extvlcopt' => 'array',
        'kodidrop' => 'array',
        'is_custom' => 'boolean',
        'is_vod' => 'boolean',
        'info' => 'array',
        'movie_data' => 'array',
        'logo_type' => ChannelLogoType::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * Get the effective playlist (either the main playlist or custom playlist)
     * This method returns the playlist that should be used for configuration
     */
    public function getEffectivePlaylist()
    {
        return $this->playlist ?? $this->customPlaylist;
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function epgChannel(): BelongsTo
    {
        return $this->belongsTo(EpgChannel::class)
            ->withoutEagerLoads();
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    public function customPlaylists(): BelongsToMany
    {
        return $this->belongsToMany(CustomPlaylist::class, 'channel_custom_playlist');
    }

    public function failovers()
    {
        return $this->hasMany(ChannelFailover::class, 'channel_id');
    }

    public function failoverChannels(): HasManyThrough
    {
        return $this->hasManyThrough(
            Channel::class, // Deploy
            ChannelFailover::class, // Environment
            'channel_id', // Foreign key on the environments table...
            'id', // Foreign key on the deployments table...
            'id', // Local key on the projects table...
            'channel_failover_id' // Local key on the environments table...
        )->orderBy('channel_failovers.sort');
    }

    /**
     * Check if the channel has metadata.
     * 
     * @return bool
     */
    public function getHasMetadataAttribute(): bool
    {
        // Check if the channel has metadata (info or movie_data)
        return !empty($this->info) || !empty($this->movie_data);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var string
     */
    public function getProxyUrlAttribute(): string
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        return ProxyFacade::getProxyUrlForChannel(
            $this->id,
            $effectivePlaylist->proxy_options['output'] ?? 'ts'
        );
    }

    /**
     * Get the stream attributes.
     *
     * @var array
     */
    public function getStreamStatsAttribute(): array
    {
        $stats = Cache::get("channel_stream_stats_{$this->id}");
        if ($stats !== null) {
            return $stats;
        }
        try {
            $url = $this->url_custom ?? $this->url;
            $process = SymfonyProcess::fromShellCommandline(
                "ffprobe -v quiet -print_format json -show_streams {$url}"
            );
            $process->setTimeout(10);
            $output = '';
            $errors = '';
            $hasErrors = false;
            $process->run(
                function ($type, $buffer) use (&$output, &$hasErrors, &$errors) {
                    if ($type === SymfonyProcess::OUT) {
                        $output .= $buffer;
                    }
                    if ($type === SymfonyProcess::ERR) {
                        $hasErrors = true;
                        $errors .= $buffer;
                    }
                }
            );
            if ($hasErrors) {
                Log::error("Error running ffprobe for channel \"{$this->title}\": {$errors}");
                return [];
            }
            $json = json_decode($output, true);
            if (isset($json['streams']) && is_array($json['streams'])) {
                $streamStats = [];
                foreach ($json['streams'] as $stream) {
                    if (isset($stream['codec_name'])) {
                        $streamStats[]['stream'] = [
                            'codec_type' => $stream['codec_type'],
                            'codec_name' => $stream['codec_name'],
                            'codec_long_name' => $stream['codec_long_name'] ?? null,
                            'profile' => $stream['profile'] ?? null,
                            'width' => $stream['width'] ?? null,
                            'height' => $stream['height'] ?? null,
                            'bit_rate' => $stream['bit_rate'] ?? null,
                            'avg_frame_rate' => $stream['avg_frame_rate'] ?? null,
                            'display_aspect_ratio' => $stream['display_aspect_ratio'] ?? null,
                            'sample_rate' => $stream['sample_rate'] ?? null,
                            'channels' => $stream['channels'] ?? null,
                            'channel_layout' => $stream['channel_layout'] ?? null,
                        ];
                    }
                }

                // Cache the result for 5 minutes
                Cache::put("channel_stream_stats_{$this->id}", $streamStats, now()->addMinutes(5));
                return $streamStats;
            }
        } catch (\Exception $e) {
            Log::error("Error running ffprobe for channel \"{$this->title}\": {$e->getMessage()}");
        }
        return [];
    }
}
