<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CopyAttributesToPlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $source,
        public int $targetId,
        public array $channelAttributes,
        public bool $allAttributes = false,
        public bool $overwrite = false,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $sourcePlaylist = $this->source;
        $playlist = Playlist::find($this->targetId);

        // Make sure we still have both playlists
        if (! ($sourcePlaylist && $playlist)) {
            return;
        }

        try {
            $this->copyChannelAttributes();
        } catch (\Exception $e) {
            // Log the error
            Log::error('Error copying attributes to playlist: ' . $e->getMessage());

            // Notify the user of the failure
            Notification::make()
                ->danger()
                ->title('Error copying playlist settings')
                ->body('There was an error copying the playlist settings. Please try again.')
                ->broadcast($sourcePlaylist->user)
                ->sendToDatabase($sourcePlaylist->user);

            return;
        }

        // If here, success! Notify the user
        Notification::make()
            ->success()
            ->title('Playlist settings copied')
            ->body('Playlist settings have been copied successfully.')
            ->broadcast($sourcePlaylist->user)
            ->sendToDatabase($sourcePlaylist->user);
    }

    /**
     * Copy channel attributes from source playlist to target playlist
     */
    private function copyChannelAttributes(): void
    {
        $sourcePlaylist = $this->source;
        $targetPlaylist = Playlist::find($this->targetId);

        // Get the attribute mapping for copying
        $attributeMapping = $this->getAttributeMapping();

        // Get all source channels that we want to copy from
        $sourceFieldsToSelect = ['id', 'source_id', 'name', 'title', 'stream_id', 'logo_internal', 'enabled', 'group', 'channel', 'shift', 'station_id', 'url'] + array_keys($attributeMapping);
        $sourceFieldsToSelect = array_unique($sourceFieldsToSelect);

        // Build a lookup array from source channels using cursor for memory efficiency
        $sourceChannels = collect();
        foreach ($sourcePlaylist->channels()->select($sourceFieldsToSelect)->cursor() as $sourceChannel) {
            $sourceChannels->put($sourceChannel->source_id, $sourceChannel);
        }
        if ($sourceChannels->isEmpty()) {
            return;
        }

        $totalUpdated = 0;
        $batchSize = 1000;

        // Process target channels in chunks for better performance
        $fieldsToSelect = ['id', 'source_id', 'name', 'title', 'logo', 'name_custom', 'title_custom', 'stream_id_custom', 'url_custom', 'enabled', 'group', 'channel', 'shift', 'station_id'] + array_values($attributeMapping);
        $fieldsToSelect = array_unique($fieldsToSelect);

        $targetPlaylist->channels()
            ->select($fieldsToSelect)
            ->chunkById($batchSize, function ($targetChannels) use ($sourceChannels, $attributeMapping, &$totalUpdated) {
                $updates = [];

                foreach ($targetChannels as $targetChannel) {
                    // Try to find matching source channel by source_id first, then by name+title
                    $sourceChannel = $sourceChannels->get($targetChannel->source_id);

                    if (! $sourceChannel) {
                        // Try to match by name and title if source_id match fails
                        $sourceChannel = $sourceChannels->first(function ($channel) use ($targetChannel) {
                            return $channel->name === $targetChannel->name &&
                                $channel->title === $targetChannel->title;
                        });
                    }

                    if (! $sourceChannel) {
                        continue; // No matching channel found
                    }

                    $updateData = [];

                    foreach ($attributeMapping as $sourceField => $targetField) {
                        $sourceValue = $sourceChannel->{$sourceField};
                        $targetValue = $targetChannel->{$targetField};

                        // Only update if we have a value to copy and either overwrite is enabled
                        // or the target field is empty
                        if ($sourceValue !== null && ($this->overwrite || $targetValue === null)) {
                            $updateData[$targetField] = $sourceValue;
                        }
                    }

                    if (! empty($updateData)) {
                        $updateData['updated_at'] = now();
                        $updates[$targetChannel->id] = $updateData;
                    }
                }

                // Batch update all channels in this chunk
                if (! empty($updates)) {
                    foreach ($updates as $channelId => $updateData) {
                        DB::table('channels')
                            ->where('id', $channelId)
                            ->update($updateData);
                        $totalUpdated++;
                    }
                }
            });

        Log::info("CopyAttributesToPlaylist: Updated {$totalUpdated} channels from playlist {$sourcePlaylist->id} to playlist {$targetPlaylist->id}");
    }

    /**
     * Get the mapping of source fields to target custom fields
     */
    private function getAttributeMapping(): array
    {
        $mapping = [];

        // If copying all attributes, include all supported attributes
        if ($this->allAttributes) {
            return [
                'enabled' => 'enabled',
                'name' => 'name_custom',
                'title' => 'title_custom',
                'logo_internal' => 'logo',  // Special case: logo_internal (source) -> logo (custom override)
                'stream_id' => 'stream_id_custom',
                'station_id' => 'station_id', // Not a provider value, so we can copy directly
                'group' => 'group',
                'shift' => 'shift',
                'channel' => 'channel',
                'url' => 'url_custom', // If we want to support URL copying as well?
            ];
        }

        // Map selected attributes to their custom field equivalents
        foreach ($this->channelAttributes as $attribute) {
            switch ($attribute) {
                case 'enabled':
                    $mapping['enabled'] = 'enabled';
                    break;
                case 'name':
                    $mapping['name'] = 'name_custom';
                    break;
                case 'title':
                    $mapping['title'] = 'title_custom';
                    break;
                case 'logo':
                    $mapping['logo_internal'] = 'logo'; // Special case: source logo_internal -> custom logo
                    break;
                case 'stream_id':
                    $mapping['stream_id'] = 'stream_id_custom';
                    break;
                case 'station_id':
                    $mapping['station_id'] = 'station_id';
                    break;
                case 'group':
                    $mapping['group'] = 'group';
                    break;
                case 'shift':
                    $mapping['shift'] = 'shift';
                    break;
                case 'channel':
                    $mapping['channel'] = 'channel';
                    break;
            }
        }

        return $mapping;
    }
}
