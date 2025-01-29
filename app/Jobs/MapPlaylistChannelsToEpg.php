<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MapPlaylistChannelsToEpg implements ShouldQueue
{
    use Queueable;

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
        if ($this->channels) {
            $channels = Channel::whereIn('id', $this->channels)->get();
        } else {
            $playlist = $this->playlist ? Playlist::find($this->playlist) : null;
            if ($playlist) {
                $channels = $playlist->channels()
                    ->when(!$this->force, function ($query) {
                        $query->where('epg_channel_id', null);
                    })->get();
            }
        }

        // Map the channels
        foreach ($channels as $channel) {
            $epgChannel = $epg->channels()->where('channel_id', $channel->name)->select('id')->first();
            if ($epgChannel) {
                $channel->epg_channel_id = $epgChannel->id;
                $channel->save();
            }
        }

        // Calculate the time taken to complete the import
        $completedIn = $start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);

        // Notify the user
        $title = "Completed processing EPG channel mapping";
        $body = "Channel mapping complete for EPG \"{$epg->name}\". Mapping took {$completedInRounded} seconds.";
        Notification::make()
            ->success()
            ->title($title)->body($body)
            ->broadcast($epg->user)
            ->sendToDatabase($epg->user);
    }
}
