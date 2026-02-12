<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Job to scan VOD channels and episodes for available audio languages.
 * Uses ffprobe to extract audio stream metadata without encoding/decoding.
 */
class ScanAudioLanguages implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 60 * 60; // 1 hour max for batch processing

    protected int $scannedCount = 0;

    protected int $errorCount = 0;

    protected int $skippedCount = 0;

    /**
     * Create a new job instance.
     *
     * @param  Collection|array|null  $vodChannelIds  VOD channel IDs to process
     * @param  Collection|array|null  $episodeIds  Episode IDs to process
     * @param  int|null  $playlistId  Playlist ID to filter by
     * @param  bool  $allPlaylists  Process all playlists
     * @param  bool  $vodOnly  Only scan VOD channels
     * @param  bool  $seriesOnly  Only scan episodes
     * @param  bool  $overwriteExisting  Whether to overwrite existing scan results
     * @param  User|null  $user  The user to notify upon completion
     */
    public function __construct(
        public Collection|array|null $vodChannelIds = null,
        public Collection|array|null $episodeIds = null,
        public ?int $playlistId = null,
        public bool $allPlaylists = false,
        public bool $vodOnly = true,
        public bool $seriesOnly = false,
        public bool $overwriteExisting = false,
        public ?User $user = null,
    ) {
        // Convert Collections to arrays
        if ($this->vodChannelIds instanceof Collection) {
            $this->vodChannelIds = $this->vodChannelIds->toArray();
        }
        if ($this->episodeIds instanceof Collection) {
            $this->episodeIds = $this->episodeIds->toArray();
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $settings = app(GeneralSettings::class);
        $ffprobe = $settings->ffprobe_path ?? 'ffprobe';

        // Check if ffprobe is configured
        if (empty($ffprobe)) {
            Log::warning('ScanAudioLanguages: ffprobe path not configured');
            $this->notifyUser('Audio Language Scan Failed', 'ffprobe is not configured. Please set the ffprobe path in Settings.', 'danger');

            return;
        }

        // Scan VOD channels
        if ($this->vodOnly || (! $this->vodOnly && ! $this->seriesOnly)) {
            $this->scanVodChannels($ffprobe);
        }

        // Scan Episodes (series)
        if ($this->seriesOnly || (! $this->vodOnly && ! $this->seriesOnly)) {
            $this->scanEpisodes($ffprobe);
        }

        $this->sendCompletionNotification();
    }

    /**
     * Scan VOD channels for audio languages.
     */
    protected function scanVodChannels(string $ffprobe): void
    {
        $query = Channel::where('is_vod', true)
            ->where('enabled', true);

        // Filter by specific IDs if provided
        if (! empty($this->vodChannelIds)) {
            $query->whereIn('id', $this->vodChannelIds);
        } elseif ($this->playlistId) {
            $query->where('playlist_id', $this->playlistId);
        }

        // Filter by user for non-admins
        if ($this->user && ! $this->user->isAdmin()) {
            $query->where('user_id', $this->user->id);
        }

        // Skip already scanned unless overwrite is enabled
        if (! $this->overwriteExisting) {
            $query->whereNull('audio_scanned_at');
        }

        // Use cursor for memory-efficient iteration
        foreach ($query->cursor() as $channel) {
            $this->scanItem($channel, $ffprobe);
        }
    }

    /**
     * Scan episodes for audio languages.
     */
    protected function scanEpisodes(string $ffprobe): void
    {
        $query = Episode::query();

        // Filter by specific IDs if provided
        if (! empty($this->episodeIds)) {
            $query->whereIn('id', $this->episodeIds);
        } elseif ($this->playlistId) {
            $query->where('playlist_id', $this->playlistId);
        }

        // Filter by user for non-admins
        if ($this->user && ! $this->user->isAdmin()) {
            $query->where('user_id', $this->user->id);
        }

        // Skip already scanned unless overwrite is enabled
        if (! $this->overwriteExisting) {
            $query->whereNull('audio_scanned_at');
        }

        // Use cursor for memory-efficient iteration
        foreach ($query->cursor() as $episode) {
            $this->scanItem($episode, $ffprobe);
        }
    }

    /**
     * Scan a single item (channel or episode) for audio languages.
     */
    protected function scanItem($item, string $ffprobe): void
    {
        try {
            if (empty($item->url)) {
                $this->skippedCount++;

                return;
            }

            $process = SymfonyProcess::fromShellCommandline(
                "{$ffprobe} -v quiet -print_format json -show_streams -select_streams a ".escapeshellarg($item->url)
            );
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->errorCount++;
                Log::debug("ScanAudioLanguages: ffprobe failed for item {$item->id}", [
                    'error' => $process->getErrorOutput(),
                ]);

                return;
            }

            $json = json_decode($process->getOutput(), true);
            $languages = [];

            foreach ($json['streams'] ?? [] as $stream) {
                $lang = $stream['tags']['language'] ?? null;
                if ($lang && ! in_array($lang, $languages)) {
                    $languages[] = $lang;
                }
            }

            $item->update([
                'audio_languages' => ! empty($languages) ? $languages : null,
                'audio_scanned_at' => now(),
            ]);

            $this->scannedCount++;

            Log::debug("ScanAudioLanguages: Scanned item {$item->id}", [
                'languages' => $languages,
            ]);
        } catch (\Exception $e) {
            $this->errorCount++;
            Log::error("ScanAudioLanguages: Error scanning item {$item->id}: {$e->getMessage()}");
        }
    }

    /**
     * Send completion notification to user.
     */
    protected function sendCompletionNotification(): void
    {
        $total = $this->scannedCount + $this->errorCount + $this->skippedCount;

        $body = sprintf(
            'Scanned: %d | Errors: %d | Skipped: %d',
            $this->scannedCount,
            $this->errorCount,
            $this->skippedCount
        );

        $title = "Audio Language Scan Complete ({$total} items)";

        $this->notifyUser($title, $body, $this->errorCount > 0 ? 'warning' : 'success');
    }

    /**
     * Notify the user.
     */
    protected function notifyUser(string $title, string $body, string $type = 'success'): void
    {
        if (! $this->user) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        match ($type) {
            'success' => $notification->success(),
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            default => $notification->info(),
        };

        $notification
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ScanAudioLanguages job failed', [
            'error' => $exception->getMessage(),
            'playlist_id' => $this->playlistId,
            'vod_only' => $this->vodOnly,
            'series_only' => $this->seriesOnly,
        ]);

        $this->notifyUser(
            'Audio Language Scan Failed',
            'An error occurred while scanning audio languages: '.$exception->getMessage(),
            'danger'
        );
    }
}
