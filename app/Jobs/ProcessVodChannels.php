<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Playlist;
use App\Services\XtreamService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProcessVodChannels implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Giving a timeout of 5 minutes for dispatching chunks
    public $timeout = 60 * 5;

    // Chunk size for processing VOD channels
    public const CHUNK_SIZE = 100;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?Playlist $playlist = null,
        public ?Channel $channel = null,
        public ?bool $force = false,
        public ?bool $updateProgress = true
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(XtreamService $xtream): void
    {
        $playlist = $this->playlist;
        if ($playlist === null) {
            $playlist = $this->channel?->playlist;
        }
        if ($playlist === null) {
            Log::error('Unable to process VOD channels: Playlist is null');
            return;
        }

        // If processing a single channel, handle it directly
        if ($this->channel) {
            $this->processSingleChannel($xtream, $playlist);
            return;
        }

        // For bulk processing, use chunked approach
        $this->processInChunks($playlist);
    }

    /**
     * Process a single VOD channel directly.
     */
    private function processSingleChannel(XtreamService $xtream, Playlist $playlist): void
    {
        $xtream = $xtream->init(
            playlist: $playlist,
            retryLimit: 5
        );
        if (!$xtream) {
            Log::error('Xtream service initialization failed for playlist ID ' . $playlist->id);
            return;
        }

        try {
            $this->channel->fetchMetadata($xtream);
            Log::info('Completed processing VOD data for channel ID ' . $this->channel->id);
            Notification::make()
                ->title('VOD Channel Processed')
                ->body('Successfully processed VOD data for channel: ' . $this->channel->name)
                ->success()
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        } catch (\Exception $e) {
            Log::error('Failed to process VOD data for channel ID ' . $this->channel->id . ': ' . $e->getMessage());
            Notification::make()
                ->title('VOD Processing Error')
                ->body('Failed to process VOD data for channel: ' . $this->channel->name . '. Error: ' . $e->getMessage())
                ->danger()
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        }
    }

    /**
     * Process VOD channels in chunks using a job chain.
     */
    private function processInChunks(Playlist $playlist): void
    {
        // Get all VOD channel IDs that need processing
        $query = $playlist->channels()
            ->where([
                ['is_vod', true],
                ['enabled', true],
                ['source_id', '!=', null],
            ])
            ->when(!$this->force, function ($query) {
                return $query->where(function ($query) {
                    $query->whereNull('info')
                        ->orWhereNull('movie_data');
                });
            });

        $channelIds = $query->pluck('id')->toArray();
        $totalChannels = count($channelIds);

        if ($totalChannels === 0) {
            Log::info('No VOD channels to process for playlist ID ' . $playlist->id);
            Notification::make()
                ->info()
                ->title('No VOD Channels to Process')
                ->body('All VOD channels in playlist "' . $playlist->name . '" already have metadata.')
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
            return;
        }

        // Update playlist status
        $playlist->update([
            'vod_progress' => 0,
        ]);

        // Split into chunks
        $chunks = array_chunk($channelIds, self::CHUNK_SIZE);
        $totalChunks = count($chunks);

        Log::info('Dispatching VOD processing chunks', [
            'playlist_id' => $playlist->id,
            'total_channels' => $totalChannels,
            'chunk_size' => self::CHUNK_SIZE,
            'total_chunks' => $totalChunks,
        ]);

        // Build the job chain
        $jobs = [];
        foreach ($chunks as $index => $chunkIds) {
            $jobs[] = new ProcessVodChannelsChunk(
                channelIds: $chunkIds,
                playlistId: $playlist->id,
                chunkIndex: $index,
                totalChunks: $totalChunks,
                force: $this->force ?? false,
            );
        }

        // Add the completion job
        $jobs[] = new ProcessVodChannelsComplete(
            playlistId: $playlist->id,
            totalChannels: $totalChannels,
        );

        // Dispatch the chain
        Bus::chain($jobs)
            ->onConnection('redis')
            ->onQueue('import')
            ->catch(function (\Throwable $e) use ($playlist) {
                Log::error('VOD processing chain failed', [
                    'playlist_id' => $playlist->id,
                    'error' => $e->getMessage(),
                ]);
                // Only update VOD progress, don't change playlist status
                $playlist->update([
                    'vod_progress' => 0,
                ]);
                Notification::make()
                    ->danger()
                    ->title('VOD Processing Failed')
                    ->body('Failed to process VOD channels: ' . $e->getMessage())
                    ->broadcast($playlist->user)
                    ->sendToDatabase($playlist->user);
            })
            ->dispatch();

        Notification::make()
            ->info()
            ->title('VOD Processing Started')
            ->body("Processing {$totalChannels} VOD channels in {$totalChunks} chunks for playlist \"{$playlist->name}\".")
            ->broadcast($playlist->user)
            ->sendToDatabase($playlist->user);
    }
}
