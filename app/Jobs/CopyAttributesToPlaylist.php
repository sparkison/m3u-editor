<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Group;
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
            $results = $this->copyChannelAttributes();
        } catch (\Exception $e) {
            // Log the error
            Log::error('Error copying attributes to playlist: ' . $e->getMessage());

            // Notify the user of the failure
            Notification::make()
                ->danger()
                ->title('Error copying playlist settings')
                ->body("There was an error copying the \"{$sourcePlaylist->name}\" settings to \"{$playlist->name}\". Please try again.")
                ->broadcast($sourcePlaylist->user)
                ->sendToDatabase($sourcePlaylist->user);

            return;
        }

        // If here, success! Notify the user
        Notification::make()
            ->success()
            ->title('Playlist settings copied')
            ->body("\"{$sourcePlaylist->name}\" settings have been copied successfully. {$results} channels updated on target \"{$playlist->name}\".")
            ->broadcast($sourcePlaylist->user)
            ->sendToDatabase($sourcePlaylist->user);
    }

    /**
     * Copy channel attributes from source playlist to target playlist
     */
    private function copyChannelAttributes(): int
    {
        $sourcePlaylist = $this->source;
        $targetPlaylist = Playlist::find($this->targetId);

        // Get the attribute mapping for copying
        $attributeMapping = $this->getAttributeMapping();

        // Get all source channels that we want to copy from
        // Include both base fields and custom fields so we can prefer custom when available
        $sourceFieldsToSelect = [
            'id',
            'source_id',
            'name',
            'name_custom',
            'title',
            'title_custom',
            'stream_id',
            'stream_id_custom',
            'logo_internal',
            'enabled',
            'group',
            'channel',
            'shift',
            'station_id',
            'url'
        ];

        // Add any additional fields from attribute mapping
        foreach ($attributeMapping as $sourceField => $targetFieldOrFields) {
            if (is_array($targetFieldOrFields)) {
                // The value is an array of fields to try, add all of them
                $sourceFieldsToSelect = array_merge($sourceFieldsToSelect, $targetFieldOrFields);
            } else {
                // Single source field
                $sourceFieldsToSelect[] = $sourceField;
            }
        }
        $sourceFieldsToSelect = array_unique($sourceFieldsToSelect);

        // Build a lookup array from source channels using cursor for memory efficiency
        $sourceChannels = collect();
        foreach ($sourcePlaylist->channels()->select($sourceFieldsToSelect)->cursor() as $sourceChannel) {
            $sourceChannels->put($sourceChannel->source_id, $sourceChannel);
        }
        if ($sourceChannels->isEmpty()) {
            return 0; // Nothing to copy
        }

        $totalUpdated = 0;
        $batchSize = 1000;

        // Preload existing groups for the target playlist into a case-insensitive map
        $groupNameToId = [];
        foreach ($targetPlaylist->groups()->get(['id', 'name']) as $g) {
            $groupNameToId[strtolower($g->name ?? '')] = $g->id;
        }

        // Process target channels in chunks for better performance
        $fieldsToSelect = [
            'id',
            'source_id',
            'name',
            'title',
            'logo',
            'name_custom',
            'title_custom',
            'stream_id_custom',
            'url_custom',
            'enabled',
            'group',
            'group_internal',
            'group_id',
            'playlist_id',
            'user_id',
            'channel',
            'shift',
            'station_id',
        ] + array_values($attributeMapping);
        $fieldsToSelect = array_unique($fieldsToSelect);

        $targetPlaylist->channels()
            ->select($fieldsToSelect)
            ->chunkById($batchSize, function ($targetChannels) use ($sourceChannels, $attributeMapping, &$totalUpdated, &$groupNameToId, $targetPlaylist) {
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

                    foreach ($attributeMapping as $sourceField => $targetFieldOrFields) {
                        // Handle case where targetFieldOrFields is an array [target_custom, fallback]
                        if (is_array($targetFieldOrFields)) {
                            // This means we should try custom field first, then fallback to base field
                            // The target is always the first element (the custom field)
                            $targetField = $targetFieldOrFields[0];
                            $sourceValue = null;
                            foreach ($targetFieldOrFields as $field) {
                                $sourceValue = $sourceChannel->{$field};
                                if ($sourceValue !== null) {
                                    break; // Use first non-null value
                                }
                            }
                        } else {
                            // Simple mapping: source field -> target field
                            $targetField = $targetFieldOrFields;
                            $sourceValue = $sourceChannel->{$sourceField};
                        }

                        $targetValue = $targetChannel->{$targetField};

                        // Only update if we have a value to copy and either overwrite is enabled
                        // or the target field is empty
                        if ($sourceValue === null || (! $this->overwrite && $targetValue !== null)) {
                            continue;
                        }

                        // Special handling for group: translate group name into group_id on target
                        if ($targetField === 'group') {
                            $desiredName = trim((string) $sourceValue);
                            if ($desiredName === '') {
                                continue;
                            }

                            $lower = strtolower($desiredName);

                            // Use existing mapping from outer scope if available
                            if (array_key_exists($lower, $groupNameToId)) {
                                $groupId = $groupNameToId[$lower];
                            } else {
                                // Create the group for the target playlist and cache the id
                                $customGroup = Group::query()->create([
                                    'name' => $desiredName,
                                    'playlist_id' => $targetPlaylist->id,
                                    'user_id' => $targetPlaylist->user_id ?? null,
                                    'sort_order' => 0,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                $groupId = $customGroup->id;
                                $groupNameToId[$lower] = $groupId;
                            }

                            // Set both the textual group column and the foreign key
                            $updateData['group'] = $desiredName;
                            $updateData['group_id'] = $groupId;

                            continue;
                        }

                        // Default copy behavior
                        $updateData[$targetField] = $sourceValue;
                    }

                    if (! empty($updateData)) {
                        $updateData['updated_at'] = now();
                        $updates[$targetChannel->id] = $updateData;
                    }
                }

                // groupNameToId is updated by-reference and persists across chunks

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

        return $totalUpdated;
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
                'logo_internal' => 'logo',  // Special case: logo_internal (source) -> logo (custom override)
                'name' => ['name_custom', 'name'], // Prefer custom, fallback to base
                'title' => ['title_custom', 'title'], // Prefer custom, fallback to base
                'stream_id' => ['stream_id_custom', 'stream_id'], // Prefer custom, fallback to base
                'station_id' => 'station_id',
                'enabled' => 'enabled',
                'group' => 'group',
                'shift' => 'shift',
                'channel' => 'channel',
                'sort' => 'sort',
            ];
        }

        // Map selected attributes to their custom field equivalents
        foreach ($this->channelAttributes as $attribute) {
            switch ($attribute) {
                // Handle special cases first
                case 'logo':
                    $mapping['logo_internal'] = 'logo'; // Special case: source logo_internal -> custom logo
                    break;

                // Then custom field mappings
                case 'name':
                    $mapping['name'] = ['name_custom', 'name']; // Prefer custom, fallback to base
                    break;
                case 'title':
                    $mapping['title'] = ['title_custom', 'title']; // Prefer custom, fallback to base
                    break;
                case 'stream_id':
                    $mapping['stream_id'] = ['stream_id_custom', 'stream_id']; // Prefer custom, fallback to base
                    break;

                // And finally, direct mappings without custom fields
                case 'enabled':
                case 'station_id':
                case 'group':
                case 'shift':
                case 'channel':
                case 'sort':
                    $mapping[$attribute] = $attribute;
                    break;
            }
        }

        return $mapping;
    }
}
