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
        public array $channelMatchAttributes,
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
            Log::error('Error copying attributes to playlist: '.$e->getMessage());

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

        // Build the source fields to select - include both base and custom fields
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
            'url',
        ];

        // Add any additional fields from attribute mapping
        foreach ($attributeMapping as $sourceField => $targetFieldOrFields) {
            if (is_array($targetFieldOrFields)) {
                $sourceFieldsToSelect = array_merge($sourceFieldsToSelect, $targetFieldOrFields);
            } else {
                $sourceFieldsToSelect[] = $sourceField;
            }
        }

        // Add match attributes to ensure they're available for matching
        $sourceFieldsToSelect = array_merge($sourceFieldsToSelect, $this->channelMatchAttributes);
        $sourceFieldsToSelect = array_unique($sourceFieldsToSelect);

        $totalUpdated = 0;
        $batchSize = 1000;

        // Preload existing groups for the target playlist into a case-insensitive map
        $groupNameToId = [];
        foreach ($targetPlaylist->groups()->get(['id', 'name']) as $g) {
            $groupNameToId[strtolower($g->name ?? '')] = $g->id;
        }

        // Build the target fields to select
        $targetFieldsToSelect = [
            'id',
            'source_id',
            'name',
            'title',
            'logo',
            'name_custom',
            'title_custom',
            'stream_id',
            'stream_id_custom',
            'url',
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
            'logo_internal',
        ];

        // Add match attributes to ensure they're selected on target
        $targetFieldsToSelect = array_merge($targetFieldsToSelect, $this->channelMatchAttributes);
        $targetFieldsToSelect = array_unique($targetFieldsToSelect);

        // Memory-efficient streaming approach:
        // 1. Process target channels in chunks (e.g., 1000 at a time)
        // 2. For each target chunk, query ONLY the potentially matching source channels
        // 3. Match and update immediately, then release from memory
        // This ensures we never load all channels into memory at once
        $targetPlaylist->channels()
            ->select($targetFieldsToSelect)
            ->chunkById($batchSize, function ($targetChannels) use ($sourcePlaylist, $sourceFieldsToSelect, $attributeMapping, &$totalUpdated, &$groupNameToId, $targetPlaylist) {
                $updates = [];

                // Build WHERE conditions to find matching source channels for this target chunk
                // Extract unique values for each match attribute from this chunk of target channels
                $matchConditions = $this->buildMatchConditions($targetChannels, $this->channelMatchAttributes);

                if (empty($matchConditions)) {
                    return; // No valid match conditions for this chunk
                }

                // Query only the source channels that could potentially match this target chunk
                // Using whereIn on match attributes drastically reduces the result set
                $sourceChannelsQuery = $sourcePlaylist->channels()->select($sourceFieldsToSelect);

                // Apply the match conditions (all attributes must match)
                foreach ($this->channelMatchAttributes as $attribute) {
                    if (isset($matchConditions[$attribute]) && ! empty($matchConditions[$attribute])) {
                        $sourceChannelsQuery->whereIn($attribute, $matchConditions[$attribute]);
                    }
                }

                // Stream through matching source channels and build a temporary lookup for this chunk only
                // This lookup is small (only channels that match current target chunk) and is released after processing
                $sourceChannelsByMatchKey = [];
                foreach ($sourceChannelsQuery->cursor() as $sourceChannel) {
                    $matchKey = $this->buildMatchKey($sourceChannel, $this->channelMatchAttributes);
                    if ($matchKey !== null) {
                        $sourceChannelsByMatchKey[$matchKey] = $sourceChannel;
                    }
                }

                // Process each target channel in this chunk
                foreach ($targetChannels as $targetChannel) {
                    $matchKey = $this->buildMatchKey($targetChannel, $this->channelMatchAttributes);

                    if ($matchKey === null || ! isset($sourceChannelsByMatchKey[$matchKey])) {
                        continue; // No matching source channel found
                    }

                    $sourceChannel = $sourceChannelsByMatchKey[$matchKey];

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
     * Build WHERE conditions for efficiently querying matching source channels
     * Returns an array mapping each match attribute to the set of values from target channels
     *
     * @param  \Illuminate\Support\Collection  $targetChannels
     * @return array<string, array<string>> Map of attribute => unique values
     */
    private function buildMatchConditions($targetChannels, array $matchAttributes): array
    {
        $conditions = [];

        foreach ($matchAttributes as $attribute) {
            $values = [];
            foreach ($targetChannels as $channel) {
                $value = $channel->{$attribute} ?? null;
                if ($value !== null && $value !== '') {
                    // Store normalized value for matching
                    $values[] = $value;
                }
            }

            // Store unique values for this attribute
            if (! empty($values)) {
                $conditions[$attribute] = array_unique($values);
            }
        }

        return $conditions;
    }

    /**
     * Build a composite match key from the channel using the specified match attributes
     *
     * @param  Channel  $channel
     * @return string|null Returns null if any required match attribute is empty
     */
    private function buildMatchKey($channel, array $matchAttributes): ?string
    {
        $keyParts = [];

        foreach ($matchAttributes as $attribute) {
            $value = $channel->{$attribute} ?? null;

            // If any match attribute is null/empty, we can't create a valid match key
            if ($value === null || $value === '') {
                return null;
            }

            // Normalize the value for consistent matching
            $keyParts[] = strtolower(trim((string) $value));
        }

        // Create a composite key by joining all parts with a delimiter
        return implode('|', $keyParts);
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
