<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Filament\Notifications\Notification;

class MergeChannels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $user;
    public $playlistId;

    /**
     * Create a new job instance.
     */
    public function __construct(public Collection $channels, $user, $playlistId = null)
    {
        $this->user = $user;
        $this->playlistId = $playlistId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processed = 0;
        $failoversToUpsert = [];

        // Phase 1: Reconnaissance - Find all stream_ids with more than one channel
        $mergeableStreamIds = Channel::query()
            ->selectRaw('LOWER(COALESCE(stream_id_custom, stream_id)) as effective_stream_id')
            ->whereIn('id', $this->channels->pluck('id'))
            ->where(function ($query) {
                $query->whereNotNull('stream_id')
                      ->orWhereNotNull('stream_id_custom');
            })
            ->groupBy('effective_stream_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('effective_stream_id');

        // Phase 2 & 3: Batch Processing and Fetch & Merge
        $mergeableStreamIds->chunk(200)->each(function ($streamIdChunk) use (&$failoversToUpsert) {
            $channelsToProcess = Channel::query()
                ->whereIn(\DB::raw('LOWER(COALESCE(stream_id_custom, stream_id))'), $streamIdChunk)
                ->whereIn('id', $this->channels->pluck('id')) // Ensure we only process channels from the original selection
                ->get();

            $groupedByStreamId = $channelsToProcess->groupBy(function ($channel) {
                return strtolower($channel->stream_id_custom ?: $channel->stream_id);
            });

            foreach ($groupedByStreamId as $group) {
                if ($group->count() <= 1) {
                    continue;
                }

                $master = null;
                if ($this->playlistId) {
                    $preferredChannels = $group->where('playlist_id', $this->playlistId);
                    if ($preferredChannels->isNotEmpty()) {
                        $master = $preferredChannels->sortBy('id')->first();
                    }
                }

                if (!$master) {
                    $master = $group->sortBy('id')->first();
                }

                foreach ($group as $failover) {
                    if ($failover->id !== $master->id) {
                        $failoversToUpsert[] = [
                            'channel_id' => $master->id,
                            'channel_failover_id' => $failover->id,
                            'user_id' => $master->user_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }
        });

        // Phase 4: Finalization - A Single Bulk Update
        if (!empty($failoversToUpsert)) {
            foreach (array_chunk($failoversToUpsert, 500) as $chunk) {
                ChannelFailover::upsert($chunk, ['channel_id', 'channel_failover_id'], ['user_id', 'updated_at']);
            }
            $processed = count($failoversToUpsert);
        }

        $this->sendCompletionNotification($processed);
    }

    protected function sendCompletionNotification($processed)
    {
        $body = $processed > 0 ? "Merged {$processed} channels successfully." : 'No channels were merged.';

        Notification::make()
            ->title('Merge complete')
            ->body($body)
            ->success()
            ->sendToDatabase($this->user);
    }
}
