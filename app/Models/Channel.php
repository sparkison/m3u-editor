<?php

namespace App\Models;

use App\Enums\ChannelLogoType;
use App\Enums\PlaylistSourceType;
use App\Facades\ProxyFacade;
use App\Http\Controllers\LogoProxyController;
use App\Services\XtreamService;
use App\Settings\GeneralSettings;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\Tags\HasTags;
use Symfony\Component\Process\Process as SymfonyProcess;
use Illuminate\Support\Str;

class Channel extends Model
{
    use HasFactory;
    use HasTags;

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
        'sync_settings' => 'array',
        'last_metadata_fetch' => 'datetime',
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

    public function getFloatingPlayerAttributes(): array
    {
        $settings = app(GeneralSettings::class);

        if ($this->is_vod) {
            $profileId = $settings->default_vod_stream_profile_id ?? null;
        } else {
            $profileId = $settings->default_stream_profile_id ?? null;
        }
        $profile = $profileId ? StreamProfile::find($profileId) : null;

        // Get the effective playlist to check proxy settings
        $playlist = $this->getEffectivePlaylist();
        $proxyEnabled = $playlist ? $playlist->enable_proxy : false;

        // Check if playlist has a stream profile assigned by checking the foreign key directly
        // This avoids N+1 queries and works even if the relationship isn't loaded
        $hasStreamProfile = false;
        if ($playlist) {
            if ($this->is_vod) {
                $hasStreamProfile = !empty($playlist->vod_stream_profile_id);
            } else {
                $hasStreamProfile = !empty($playlist->stream_profile_id);
            }
        }

        // Determine the source URL and format
        $originalUrl = $this->url_custom ?? $this->url;
        $format = pathinfo($originalUrl, PATHINFO_EXTENSION);
        if (empty($format)) {
            $format = $this->container_extension ?? 'ts';
        }

        // Normalize format for player compatibility
        if ($format === 'm3u8') {
            $format = 'hls';
        }

        // Decide whether to use direct streaming or proxy
        // Use direct streaming if:
        // 1. Proxy is disabled on the playlist AND
        // 2. No stream profile is assigned to the playlist AND
        // 3. No default profile is set in settings
        $useDirectStreaming = !$proxyEnabled && !$hasStreamProfile && !$profile;

        // Debug logging
        \Log::info('Channel::getFloatingPlayerAttributes', [
            'channel_id' => $this->id,
            'channel_name' => $this->name,
            'playlist_id' => $playlist?->id,
            'playlist_name' => $playlist?->name,
            'proxyEnabled' => $proxyEnabled,
            'hasStreamProfile' => $hasStreamProfile,
            'playlist_stream_profile_id' => $playlist?->stream_profile_id,
            'playlist_vod_stream_profile_id' => $playlist?->vod_stream_profile_id,
            'defaultProfile' => $profile?->id,
            'defaultProfileName' => $profile?->name,
            'useDirectStreaming' => $useDirectStreaming,
            'originalUrl' => $originalUrl,
        ]);

        if ($useDirectStreaming) {
            // Direct streaming from provider - use source URL
            $url = $originalUrl;
            \Log::info('Using DIRECT streaming', ['url' => $url]);
        } else {
            // Proxy through m3u-proxy for transcoding/compatibility
            // This also prevents CORS and mixed-content issues
            $url = route('m3u-proxy.channel.player', ['id' => $this->id]);
            \Log::info('Using PROXY streaming', ['url' => $url, 'reason' => [
                'proxyEnabled' => $proxyEnabled,
                'hasStreamProfile' => $hasStreamProfile,
                'hasDefaultProfile' => !is_null($profile),
            ]]);

            // If a profile is set, use its format
            if ($profile) {
                $format = $profile->format ?? $format;
            }
        }

        return [
            'id' => $this->id,
            'title' => $this->name_custom ?? $this->name,
            'url' => $url,
            'format' => $format,
            'type' => 'channel',
        ];
    }

    /**
     * Check if the channel has metadata.
     */
    public function getHasMetadataAttribute(): bool
    {
        // Check if the channel has metadata (info or movie_data)
        return ! empty($this->info) || ! empty($this->movie_data);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var string
     */
    public function getProxyUrlAttribute(): string
    {
        return ProxyFacade::getProxyUrlForChannel(
            $this->id,
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
        } catch (Exception $e) {
            Log::error("Error running ffprobe for channel \"{$this->title}\": {$e->getMessage()}");
        }

        return [];
    }

    public function fetchMetadata($xtream = null, $refresh = false)
    {
        try {
            $playlist = $this->playlist;

            // For Xtream playlists, use XtreamService
            if (!$xtream) {
                if (!$playlist->xtream && $playlist->source_type !== PlaylistSourceType::Xtream) {
                    // Not an Xtream playlist and not Emby, no metadata source available
                    return false;
                }
                $xtream = XtreamService::make($playlist);
            }

            if (! $xtream) {
                Notification::make()
                    ->danger()
                    ->title('VOD metadata sync failed')
                    ->body('Unable to connect to Xtream API provider to get VOD info, unable to fetch metadata.')
                    ->broadcast($playlist->user)
                    ->sendToDatabase($playlist->user);

                return false;
            }
            if (! $this->is_vod) {
                return false;
            }
            $movieData = $xtream->getVodInfo($this->source_id);
            $releaseDate = $movieData['info']['release_date'] ?? null;
            $releaseDateAlt = $movieData['info']['releasedate'] ?? null;
            $year = $this->year;
            if (!$releaseDate && $releaseDateAlt) {
                // Make sure base release_date is always set
                $movieData['info']['release_date'] = $releaseDateAlt;
            }
            if ($releaseDate || $releaseDateAlt) {
                // If either data is set, and year is not set, update it
                $dateToParse = $releaseDate ?? $releaseDateAlt;
                $year = null;
                try {
                    $date = new \DateTime($dateToParse);
                    $year = (int) $date->format('Y');
                } catch (\Exception $e) {
                    Log::warning("Unable to parse release date \"{$dateToParse}\" for VOD {$this->id}");
                }
            }
            $update = [
                'year' => $year,
                'info' => $movieData['info'] ?? null,
                'movie_data' => $movieData['movie_data'] ?? null,
                'last_metadata_fetch' => now(),
            ];

            $this->update($update);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to fetch metadata for VOD ' . $this->id, ['exception' => $e]);
        }

        return false;
    }

    /**
     * Get the custom group name for a specific custom playlist
     */
    public function getCustomGroupName(string $customPlaylistUuid): string
    {
        $tag = $this->tags()
            ->where('type', $customPlaylistUuid)
            ->first();

        return $tag ? $tag->getAttributeValue('name') : 'Uncategorized';
    }
}
