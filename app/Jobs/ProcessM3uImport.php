<?php

namespace App\Jobs;

use Throwable;
use App\Enums\PlaylistStatus;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Exception;
use M3uParser\M3uParser;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;

class ProcessM3uImport implements ShouldQueue
{
    use Queueable;

    public $maxItems = 50000;

    public $deleteWhenMissingModels = true;

    // Giving a timeout of 15 minutes to the Job to process the file
    public $timeout = 60 * 15;

    /**
     * Create a new job instance.
     * 
     * @param Playlist $playlist
     */
    public function __construct(
        public Playlist $playlist,
        public ?bool $force = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->force) {
            // Don't update if currently processing
            if ($this->playlist->processing) {
                return;
            }

            // Check if auto sync is enabled, or the playlist hasn't been synced yet
            if (!$this->playlist->auto_sync && $this->playlist->synced) {
                return;
            }
        }

        // Update the playlist status to processing
        $this->playlist->update([
            'processing' => true,
            'status' => PlaylistStatus::Processing,
            'errors' => null,
            'progress' => 0,
        ]);

        // Flag job start time
        $start = now();

        // Surround in a try/catch block to catch any exceptions
        try {
            // Get the playlist
            $playlist = $this->playlist;

            // Get the playlist details
            $playlistId = $playlist->id;
            $userId = $playlist->user_id;
            $batchNo = Str::orderedUuid()->toString();

            $filePath = null;
            if ($playlist->url && str_starts_with($playlist->url, 'http')) {
                // Normalize the playlist url and get the filename
                $url = str($playlist->url)->replace(' ', '%20');

                // We need to grab the file contents first and set to temp file
                $userPreferences = app(GeneralSettings::class);
                try {
                    $verify = !$userPreferences->disable_ssl_verification;
                    $userAgent = $userPreferences->playlist_agent_string;
                } catch (Exception $e) {
                    $verify = true;
                    $userAgent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13';
                }
                $response = Http::withUserAgent($userAgent)
                    ->withOptions(['verify' => $verify])
                    ->timeout(60 * 5) // set timeout to five minues
                    ->throw()->get($url->toString());

                if ($response->ok()) {
                    // Remove previous saved files
                    Storage::disk('local')->deleteDirectory($playlist->folder_path);

                    // Save the file to local storage
                    Storage::disk('local')->put(
                        $playlist->file_path,
                        $response->body()
                    );

                    // Update the file path
                    $filePath = Storage::disk('local')->path($playlist->file_path);
                }
            } else {
                // Get uploaded file contents
                if ($playlist->uploads && Storage::disk('local')->exists($playlist->uploads)) {
                    // Get the contents and the path
                    $filePath = Storage::disk('local')->path($playlist->uploads);
                } else if ($playlist->url) {
                    $filePath = $playlist->url;
                }
                
            }

            // Update progress
            $playlist->update(['progress' => 5]); // set to 5% to start

            // If file path is set, we can process the file
            if ($filePath) {
                // Update progress
                $playlist->update(['progress' => 10]);

                // Setup common field values
                $channelFields = [
                    'title' => null,
                    'name' => null,
                    'url' => null,
                    'logo' => null,
                    'group' => null,
                    'group_internal' => null,
                    'stream_id' => null,
                    'lang' => null,
                    'country' => null,
                    'playlist_id' => $playlistId,
                    'user_id' => $userId,
                    'import_batch_no' => $batchNo,
                    'enabled' => $playlist->enable_channels,
                ];

                // Setup the attribute -> key mapping
                $attributes = [
                    'name' => 'tvg-name',
                    'stream_id' => 'tvg-id',
                    'logo' => 'tvg-logo',
                    'group' => 'group-title',
                    'group_internal' => 'group-title',
                    'channel' => 'tvg-chno',
                    'lang' => 'tvg-language',
                    'country' => 'tvg-country',
                ];

                // Check if preprocessing is enabled
                $preprocess = $playlist->import_prefs['preprocess'] ?? false;

                // Extract the channels and groups from the m3u
                $groups = [];
                $excludeFileTypes = $playlist->import_prefs['ignored_file_types'] ?? [];
                $selectedGroups = $playlist->import_prefs['selected_groups'] ?? [];
                $includedGroupPrefixes = $playlist->import_prefs['included_group_prefixes'] ?? [];
                LazyCollection::make(function () use ($filePath, $channelFields, $attributes, $excludeFileTypes) {
                    $m3uParser = new M3uParser();
                    $m3uParser->addDefaultTags();
                    $count = 0;
                    foreach ($m3uParser->parseFile($filePath, max_length: 2048) as $item) {
                        $url = $item->getPath();
                        if ($count++ >= $this->maxItems) {
                            break;
                        }
                        foreach ($excludeFileTypes as $excludeFileType) {
                            if (str_ends_with($url, $excludeFileType)) {
                                continue 2;
                            }
                        }
                        $channel = [
                            ...$channelFields,
                            'url' => $url,
                        ];
                        foreach ($item->getExtTags() as $extTag) {
                            if ($extTag instanceof \M3uParser\Tag\ExtInf) {
                                $channel['title'] = $extTag->getTitle();
                                foreach ($attributes as $key => $attribute) {
                                    if ($extTag->hasAttribute($attribute)) {
                                        if ($attribute === 'tvg-chno') {
                                            $channel[$key] = (int)$extTag->getAttribute($attribute);
                                        } else {
                                            $channel[$key] = str_replace(
                                                [',', '"', "'"],
                                                '',
                                                $extTag->getAttribute($attribute)
                                            );
                                        }
                                    }
                                }
                            }
                        }
                        if (!isset($channel['title'])) {
                            // Name is required, fallback to stream ID if available, otherwise set to title
                            // Channel will be skipped on import of not set to something...
                            $channel['title'] = $channel['stream_id'] ?? $channel['name'];
                        }
                        yield $channel;
                    }
                })->groupBy('group')->chunk(10)->each(function (LazyCollection $grouped) use (&$groups, $selectedGroups, $includedGroupPrefixes, $preprocess, $userId, $playlistId, $batchNo) {
                    $grouped->each(function ($channels, $groupName) use (&$groups, $selectedGroups, $includedGroupPrefixes, $preprocess, $userId, $playlistId, $batchNo) {
                        $groupNames = explode(';', $groupName);
                        foreach ($groupNames as $groupName) {
                            // Trim whitespace
                            $groupName = str_replace(
                                [',', '"', "'"],
                                '',
                                trim($groupName)
                            );

                            // Add to groups if not already added
                            $groups[] = $groupName;
                            $shouldAdd = !$preprocess;
                            if (!$shouldAdd) {
                                // If preprocessing, check if group is selected...
                                $shouldAdd = in_array(
                                    $groupName,
                                    $selectedGroups
                                );
                            }
                            if (!$shouldAdd) {
                                // ...if group not selected, check if group starts with any of the included prefixes
                                // (only check if the group isn't directly included already)
                                foreach ($includedGroupPrefixes as $prefix) {
                                    if (str_starts_with($groupName, $prefix)) {
                                        $shouldAdd = true;
                                        break;
                                    }
                                }
                            }

                            // Confirm if adding or skipping
                            if ($shouldAdd) {
                                // Add group and associated channels
                                $group = Group::where([
                                    'name_internal' => $groupName,
                                    'playlist_id' => $playlistId,
                                    'user_id' => $userId,
                                    'custom' => false,
                                ])->first();
                                if (!$group) {
                                    $group = Group::create([
                                        'name' => $groupName,
                                        'name_internal' => $groupName,
                                        'playlist_id' => $playlistId,
                                        'user_id' => $userId,
                                        'import_batch_no' => $batchNo,
                                    ]);
                                } else {
                                    $group->update([
                                        'import_batch_no' => $batchNo,
                                    ]);
                                }
                                $channels->chunk(50)->each(function ($chunk) use ($playlistId, $batchNo, $group) {
                                    Job::create([
                                        'title' => "Processing channel import for group: {$group->name}",
                                        'batch_no' => $batchNo,
                                        'payload' => $chunk->toArray(),
                                        'variables' => [
                                            'groupId' => $group->id,
                                            'playlistId' => $playlistId,
                                        ]
                                    ]);
                                });
                            }
                        }
                    });
                });

                // Remove duplicate groups
                $groups = array_values(array_unique($groups));

                // Update progress
                $playlist->update(['progress' => 15]);

                // Check if preprocessing, and not groups selected yet
                if ($preprocess && count($selectedGroups) === 0 && count($includedGroupPrefixes) === 0) {
                    $completedIn = $start->diffInSeconds(now());
                    $completedInRounded = round($completedIn, 2);
                    $playlist->update([
                        'status' => PlaylistStatus::Completed,
                        'channels' => 0, // not using...
                        'synced' => now(),
                        'errors' => null,
                        'sync_time' => $completedIn,
                        'progress' => 100,
                        'processing' => false,
                        'groups' => $groups,
                    ]);

                    // Send notification
                    Notification::make()
                        ->success()
                        ->title('Playlist Preprocessing Completed')
                        ->body("\"{$playlist->name}\" has been preprocessed.")
                        ->broadcast($playlist->user);
                    Notification::make()
                        ->success()
                        ->title('Playlist Synced')
                        ->body("\"{$playlist->name}\" has been preprocessed successfully. You can now select the groups you would like to import and process the playlist again to import your selected groups. Preprocessing completed in {$completedInRounded} seconds.")
                        ->sendToDatabase($playlist->user);
                } else {
                    // Get the jobs for the batch
                    $jobs = [];
                    $batchCount = Job::where('batch_no', $batchNo)->count();
                    $jobsBatch = Job::where('batch_no', $batchNo)->select('id')->cursor();
                    $jobsBatch->chunk(100)->each(function ($chunk) use (&$jobs, $batchCount) {
                        $jobs[] = new ProcessM3uImportChunk($chunk->pluck('id')->toArray(), $batchCount);
                    });

                    // Last job in the batch
                    $jobs[] = new ProcessM3uImportComplete($userId, $playlistId, $groups, $batchNo, $start);
                    Bus::chain($jobs)
                        ->onConnection('redis') // force to use redis connection
                        ->onQueue('import')
                        ->catch(function (Throwable $e) use ($playlist) {
                            $error = "Error processing \"{$playlist->name}\": {$e->getMessage()}";
                            Notification::make()
                                ->danger()
                                ->title("Error processing \"{$playlist->name}\"")
                                ->body('Please view your notifications for details.')
                                ->broadcast($playlist->user);
                            Notification::make()
                                ->danger()
                                ->title("Error processing \"{$playlist->name}\"")
                                ->body($error)
                                ->sendToDatabase($playlist->user);
                            $playlist->update([
                                'status' => PlaylistStatus::Failed,
                                'channels' => 0, // not using...
                                'synced' => now(),
                                'errors' => $error,
                                'progress' => 100,
                                'processing' => false,
                            ]);
                        })->dispatch();
                }
            } else {
                // Log the exception
                logger()->error("Error processing \"{$playlist->name}\"");

                // Send notification
                $error = "Invalid playlist file. Unable to read or download your playlist file. Please check the URL or uploaded file and try again.";
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$playlist->name}\"")
                    ->body('Please view your notifications for details.')
                    ->broadcast($playlist->user);
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$playlist->name}\"")
                    ->body($error)
                    ->sendToDatabase($playlist->user);

                // Update the Playlist
                $playlist->update([
                    'status' => PlaylistStatus::Failed,
                    'channels' => 0, // not using...
                    'synced' => now(),
                    'errors' => $error,
                    'progress' => 100,
                    'processing' => false,
                ]);
                return;
            }
        } catch (\Exception $e) {
            // Log the exception
            logger()->error("Error processing \"{$this->playlist->name}\": {$e->getMessage()}");

            // Send notification
            Notification::make()
                ->danger()
                ->title("Error processing \"{$this->playlist->name}\"")
                ->body('Please view your notifications for details.')
                ->broadcast($this->playlist->user);
            Notification::make()
                ->danger()
                ->title("Error processing \"{$this->playlist->name}\"")
                ->body($e->getMessage())
                ->sendToDatabase($this->playlist->user);

            // Update the playlist
            $this->playlist->update([
                'status' => PlaylistStatus::Failed,
                'synced' => now(),
                'errors' => $e->getMessage(),
                'progress' => 100,
                'processing' => false,
            ]);
        }
        return;
    }
}
