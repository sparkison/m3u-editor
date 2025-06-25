<?php

namespace App\Jobs;

use App\Models\Playlist;
use App\Services\XtreamService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessVodChannels implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public ?bool $force = false,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(XtreamService $xtream): void
    {
        $xtream = $xtream->init(
            playlist: $this->playlist,
            retryLimit: 5
        );

        if (!$xtream) {
            Log::error('Xtream service initialization failed for playlist ID ' . $this->playlist->id);
            return;
        }

        // Update the playlist status to processing
        $this->playlist->update([
            'processing' => true,
            'status' => 'processing',
            'errors' => null,
        ]);

        $query = $this->playlist->channels()
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
        $total = $query->count();

        foreach ($query->cursor() as $index => $channel) {
            try {
                $movieData = $xtream->getVodInfo($channel->source_id);
                if ($movieData) {
                    $channel->update([
                        'info' => $movieData['info'] ?? null,
                        'movie_data' => $movieData['movie_data'] ?? null,
                    ]);
                    Log::info('Processed VOD data for channel ID ' . $channel->id);
                } else {
                    Log::warning('No VOD data found for channel ID ' . $channel->id);
                }
            } catch (\Exception $e) {
                // Log the error and continue processing other channels
                Log::error('Failed to process VOD data for channel ID ' . $channel->id . ': ' . $e->getMessage());
                Notification::make()
                    ->title('VOD Processing Error')
                    ->body('Failed to process VOD data for channel: ' . $channel->name . '. Error: ' . $e->getMessage())
                    ->danger()
                    ->broadcast($this->playlist->user)
                    ->sendToDatabase($this->playlist->user);

                // Update the playlist with the error
                $this->playlist->update([
                    'processing' => false,
                    'status' => 'failed',
                    'errors' => 'Failed to process VOD data for channel ID ' . $channel->id . ': ' . $e->getMessage(),
                ]);
                return; // Exit the job if an error occurs
            }
            sleep(1); // Throttle processing to avoid overwhelming the Xtream API
            if ($index % 10 === 0) {
                // Update progress every 10 channels processed
                $progress = min(99, ($index / $total) * 100);
                $this->playlist->update(['progress' => $progress]);
            }
        }

        // Update the playlist status after processing
        $this->playlist->update([
            'processing' => false,
            'status' => 'completed',
            'errors' => null,
        ]);

        Log::info('Completed processing VOD channels for playlist ID ' . $this->playlist->id);
        Notification::make()
            ->title('VOD Channels Processed')
            ->body('Successfully processed VOD channels for playlist: ' . $this->playlist->name)
            ->success()
            ->broadcast($this->playlist->user)
            ->sendToDatabase($this->playlist->user);
    }
}
