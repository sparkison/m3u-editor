<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Database\Eloquent\Collection;

class ChannelFindAndReplace implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $user_id, // The ID of the user who owns the playlist
        public bool $use_regex,
        public string $column,
        public string $find_replace,
        public string $replace_with,
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
        $updated = 0; // Counter for updated channels
        foreach ($channels as $channel) {
            // Get the target column names
            $customColumn = "{$this->column}_custom"; // The custom column to check for a custom value
            $providerValue = $channel->{$this->column}; // Get the current value of the column
            $customValue = $channel->{$customColumn};

            // Get the value we're modifying and what we're replacing it with
            $valueToModify = $customValue ?? $providerValue; // Use the custom value if it exists, otherwise use the original column value
            $find = $this->find_replace;
            $replace = $this->replace_with;

            // Check if the value to modify is empty, or doesn't contain the find string
            if (empty($valueToModify)) {
                // If the value is empty, skip to the next channel
                continue;
            }

            // Determine the value to replace
            if ($this->use_regex) {
                // Escape existing delimiters in user input
                $delimiter = '/';
                $pattern = str_replace($delimiter, '\\' . $delimiter, $find);
                $finalPattern = $delimiter . $pattern . $delimiter . 'ui'; // 'ui' flags for case insensitive and unicode matching

                // Check if the find string is in the value to modify
                if (!preg_match($finalPattern, $valueToModify)) {
                    // If the regex pattern does not match the value to modify, skip to the next channel
                    continue;
                }

                // Perform a regex replacement
                $newValue = preg_replace($finalPattern, $replace, $valueToModify); // Use simple string replacement
            } else {
                // Check if the find string is in the value to modify
                if (!stristr($valueToModify, $find)) {
                    // If the find string is not in the value to modify, skip to the next channel
                    continue;
                }

                // Perform a case-insensitive replacement
                $newValue = str_ireplace(
                    $find,
                    $replace,
                    $valueToModify
                );
            }
            if ($newValue) {
                // Increment the updated counter if the value has changed
                ++$updated;

                // If the new value is not empty, update the channel's custom column
                $channel->{$customColumn} = $newValue;
                $channel->save(); // Save the changes to the database
            }
        }

        // Notify the user we're done!
        $completedIn = $start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);
        $user = User::find($this->user_id);

        // Send notification
        Notification::make()
            ->success()
            ->title('Find & Replace completed')
            ->body("Channel find & replace has completed successfully.")
            ->broadcast($user);
        Notification::make()
            ->success()
            ->title('Find & Replace completed')
            ->body("Channel find & replace has completed successfully. Import completed in {$completedInRounded} seconds and updated {$updated} channels.")
            ->sendToDatabase($user);
    }
}
