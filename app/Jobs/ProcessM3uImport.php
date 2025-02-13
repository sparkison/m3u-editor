<?php

namespace App\Jobs;

use Throwable;
use App\Enums\PlaylistStatus;
use App\Models\Group;
use App\Models\Playlist;
use M3uParser\M3uParser;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;

class ProcessM3uImport implements ShouldQueue
{
    use Queueable;

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
        // Don't update if currently processing
        if ($this->playlist->processing) {
            return;
        }
        if (!$this->force) {
            // Check if auto sync is enabled, or the playlist hasn't been synced yet
            if (!$this->playlist->auto_sync && $this->playlist->synced) {
                return;
            }
        }

        // Update the playlist status to processing
        $this->playlist->update([
            'processing' => true,
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

            // Normalize the playlist url and get the filename
            $url = str($playlist->url)->replace(' ', '%20');

            // We need to grab the file contents first and set to temp file
            $userAgent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13';
            $response = Http::withUserAgent($userAgent)
                ->timeout(60 * 5) // set timeout to five minues
                ->throw()->get($url->toString());

            // If fetched successfully, process the results!
            if ($response->ok()) {
                $m3uParser = new M3uParser();
                $m3uParser->addDefaultTags();
                $data = $m3uParser->parse($response->body());

                // Update progress
                $playlist->update(['progress' => 10]); // set to 10% to start

                // Setup common field values
                $channelFields = [
                    'title' => null,
                    'name' => null,
                    'url' => null,
                    'logo' => null,
                    'group' => null,
                    'stream_id' => null,
                    'lang' => null,
                    'country' => null,
                    'playlist_id' => $playlistId,
                    'user_id' => $userId,
                    'import_batch_no' => $batchNo,
                ];

                // Setup the attribute -> key mapping
                $attributes = [
                    'tvg-name' => 'name',
                    'tvg-id' => 'stream_id',
                    'tvg-logo' => 'logo',
                    'group-title' => 'group',
                ];

                // Extract the channels and groups from the m3u
                $jobs = [];
                LazyCollection::make(function () use ($data, $channelFields, $attributes) {
                    foreach ($data as $item) {
                        $channel = [
                            ...$channelFields,
                            'url' => $item->getPath(),
                        ];
                        foreach ($item->getExtTags() as $extTag) {
                            if ($extTag instanceof \M3uParser\Tag\ExtInf) {
                                $channel['title'] = $extTag->getTitle();
                                foreach ($attributes as $attribute => $key) {
                                    if ($extTag->hasAttribute($attribute)) {
                                        $channel[$key] = $extTag->getAttribute($attribute);
                                    }
                                }
                            }
                        }
                        if (!isset($channel['name'])) {
                            // Name is required, fallback to stream ID if available, otherwise set to title
                            // Channel will be skipped on import of not set to something...
                            $channel['name'] = $channel['stream_id'] ?? $channel['title'];
                        }
                        yield $channel;
                    }
                })->groupBy('group')->chunk(10)->each(function (LazyCollection $grouped) use (&$jobs, $userId, $playlistId, $batchNo) {
                    $grouped->each(function ($channels, $groupName) use (&$jobs, $userId, $playlistId, $batchNo) {
                        $group = Group::firstOrCreate([
                            'name' => $groupName,
                            'playlist_id' => $playlistId,
                            'user_id' => $userId,
                        ]);
                        $group->update([
                            'import_batch_no' => $batchNo,
                        ]);
                        $channels->chunk(100)->each(function ($chunk) use (&$jobs, $playlistId, $batchNo, $group) {
                            $jobs[] = new ProcessChannelImport($playlistId, $batchNo, $group, $chunk->toArray());
                        });
                    });
                });

                // Add progress update job to the batch
                $count = count($jobs);
                $chunkSize = ceil($count / 10);
                for ($i = $chunkSize; $i < $count; $i += $chunkSize) {
                    array_splice($jobs, $i, 0, function () use ($playlist, $i, $count) {
                        $playlist->update(['progress' => ($i / $count) * 100]);
                    });
                }

                // Last job in the batch
                $jobs[] = new ProcessM3uImportComplete($userId, $playlistId, $batchNo, $start);
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
            } else {
                $error = "Unable to fetch the playlist from the provided URL.";
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$this->playlist->name}\"")
                    ->body('Please view your notifications for details.')
                    ->broadcast($this->playlist->user);
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$this->playlist->name}\"")
                    ->body($error)
                    ->sendToDatabase($this->playlist->user);
                $playlist->update([
                    'status' => PlaylistStatus::Failed,
                    'channels' => 0, // not using...
                    'synced' => now(),
                    'errors' => $error,
                    'progress' => 100,
                    'processing' => false,
                ]);
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
