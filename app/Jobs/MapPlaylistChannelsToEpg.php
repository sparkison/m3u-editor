<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\LazyCollection;
use Throwable;

class MapPlaylistChannelsToEpg implements ShouldQueue
{
    use Queueable;

    // Giving a timeout of 15 minutes to the Job to process the mapping
    public $timeout = 60 * 15;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $epg,
        public ?int $playlist = null,
        public ?array $channels = null,
        public ?bool $force = false,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Flag job start time
        $start = now();

        // Fetch the EPG
        $epg = Epg::find($this->epg);
        if (!$epg) {
            $error = "Unable to map to the selected EPG, it no longer exists. Please select a different EPG and try again.";
            Notification::make()
                ->danger()
                ->title("Error processing EPG channel mapping")
                ->body('Please view your notifications for details.')
                ->broadcast($epg->user);
            Notification::make()
                ->danger()
                ->title("Error processing EPG channel mapping")
                ->body($error)
                ->sendToDatabase($epg->user);
            return;
        }

        // Fetch the playlist (if set)
        $channels = [];
        $playlist = $this->playlist ? Playlist::find($this->playlist) : null;
        if ($this->channels) {
            $channels = Channel::whereIn('id', $this->channels)->cursor();
        } else {
            if ($playlist) {
                $channels = $playlist->channels()
                    ->when(!$this->force, function ($query) {
                        $query->where('epg_channel_id', null);
                    })->cursor();
            }
        }

        // Map the channels
        $jobs = [];
        LazyCollection::make(function () use ($channels, $epg) {
            $epgChannels = $epg->channels()
                ->where('channel_id', '!=', '')
                ->whereIn('channel_id', $channels->pluck('name'))
                ->select('id', 'channel_id')
                ->get();

            foreach ($channels as $channel) {
                $epgChannel = $epgChannels->where('channel_id', $channel->name)->first();
                if ($epgChannel) {
                    $channel->epg_channel_id = $epgChannel->id;
                    yield $channel->toArray();
                }
            }
        })->chunk(500)->each(function ($chunk) use (&$jobs) {
            $jobs[] = new MapEpgToChannels($chunk->toArray());
        });

        // Last job in the batch
        $jobs[] = new MapEpgToChannelsComplete($playlist, $epg, $start);

        // Dispatch the batch
        Bus::chain($jobs)
            ->onConnection('redis') // force to use redis connection
            ->onQueue('import')
            ->catch(function (Throwable $e) use ($epg, $playlist) {
                $error = "Error processing \"{$epg->name}\" mapping to \"{$playlist->name}\": {$e->getMessage()}";
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$epg->name}\" mapping to \"{$playlist->name}\"")
                    ->body('Please view your notifications for details.')
                    ->broadcast($epg->user);
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$epg->name}\" mapping to \"{$playlist->name}\"")
                    ->body($error)
                    ->sendToDatabase($epg->user);
            })->dispatch();
    }
}
