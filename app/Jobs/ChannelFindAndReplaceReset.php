<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Database\Eloquent\Collection;

class ChannelFindAndReplaceReset implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $user_id, // The ID of the user who owns the playlist
        public string $column,
        public ?Collection $channels = null,
        public ?bool $all_playlists = false,
        public ?int $playlist_id = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Clock the time
        $start = now();

        // Get the channels
        if (!$this->channels) {
            $channels = Channel::query()
                ->when(!$this->all_playlists && $this->playlist_id, fn($query) => $query->where('playlist_id', $this->playlist_id))
                ->cursor(); // Use cursor for memory efficiency when processing large datasets
        } else {
            $channels = $this->channels; // Use the provided collection of channels
        }

        // Loop over the channels and perform the find and replace operation
        foreach ($channels as $channel) {
            $channel->{$this->column . '_custom'} = null; // Reset the custom column to null
            $channel->save(); // Save the changes to the database
        }

        // Notify the user we're done!
        $completedIn = $start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);
        $user = User::find($this->user_id);

        Notification::make()
            ->success()
            ->title('Find & Replace reset')
            ->body("Channel find & replace reset has completed successfully.")
            ->broadcast($user);
        Notification::make()
            ->success()
            ->title('Find & Replace reset completed')
            ->body("Channel find & replace reset has completed successfully. Reset completed in {$completedInRounded} seconds")
            ->sendToDatabase($user);
    }
}
