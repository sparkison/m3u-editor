<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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

        $customColumn = "{$this->column}_custom";
        $updated = 0;

        // Process channels in chunks for better performance
        if (!$this->channels) {
            // Use chunking to process large datasets efficiently
            Channel::query()
                ->when(!$this->all_playlists && $this->playlist_id, fn($query) => $query->where('playlist_id', $this->playlist_id))
                ->chunkById(1000, function ($channels) use ($customColumn, &$updated) {
                    $updated += $this->processChannelChunk($channels, $customColumn);
                });
        } else {
            // Process the provided collection in chunks
            $this->channels
                ->chunk(1000)
                ->each(function ($chunk) use ($customColumn, &$updated) {
                    $updated += $this->processChannelChunk($chunk, $customColumn);
                });
        }

        // Notify the user we're done!
        $completedIn = $start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);
        $user = User::find($this->user_id);

        // Send notification
        Notification::make()
            ->success()
            ->title('Find & Replace completed')
            ->body("Channel find & replace has completed successfully. {$updated} channels updated.")
            ->broadcast($user);
        Notification::make()
            ->success()
            ->title('Find & Replace completed')
            ->body("Channel find & replace has completed successfully. Operation completed in {$completedInRounded} seconds and updated {$updated} channels.")
            ->sendToDatabase($user);
    }

    /**
     * Process a chunk of channels and perform find/replace operations
     */
    private function processChannelChunk($channels, string $customColumn): int
    {
        $updatesMap = [];
        $find = $this->find_replace;
        $replace = $this->replace_with;

        foreach ($channels as $channel) {
            $providerValue = $channel->{$this->column};
            $customValue = $channel->{$customColumn};

            // Get the value we're modifying
            $valueToModify = $customValue ?? $providerValue;

            // Check if the value to modify is empty
            if (empty($valueToModify)) {
                continue;
            }

            $newValue = null;

            // Determine the value to replace
            if ($this->use_regex) {
                // Escape existing delimiters in user input
                $delimiter = '/';
                $pattern = str_replace($delimiter, '\\' . $delimiter, $find);
                $finalPattern = $delimiter . $pattern . $delimiter . 'ui';

                // Check if the find string is in the value to modify
                if (!preg_match($finalPattern, $valueToModify)) {
                    continue;
                }

                // Perform a regex replacement
                $newValue = preg_replace($finalPattern, $replace, $valueToModify);
            } else {
                // Check if the find string is in the value to modify
                if (!stristr($valueToModify, $find)) {
                    continue;
                }

                // Perform a case-insensitive replacement
                $newValue = str_ireplace($find, $replace, $valueToModify);
            }

            if ($newValue && $newValue !== $valueToModify) {
                $updatesMap[$channel->id] = $newValue;
            }
        }

        // Perform batch update if we have changes
        if (!empty($updatesMap)) {
            // Build the update cases for batch update
            $cases = [];
            $ids = array_keys($updatesMap);

            foreach ($updatesMap as $id => $value) {
                $cases[] = "WHEN {$id} THEN " . DB::connection()->getPdo()->quote($value);
            }

            $caseStatement = implode(' ', $cases);

            // Execute batch update using raw SQL for better performance
            DB::statement("
                UPDATE channels 
                SET {$customColumn} = CASE id {$caseStatement} END,
                    updated_at = ?
                WHERE id IN (" . implode(',', $ids) . ")
            ", [now()]);

            return count($updatesMap);
        }

        return 0;
    }
}
