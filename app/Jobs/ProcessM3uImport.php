<?php

namespace App\Jobs;

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

use Throwable;

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
        public Playlist $playlist
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Don't update if currently processing
        if ($this->playlist->status === PlaylistStatus::Processing) {
            return;
        }

        // Update the playlist status to processing
        $this->playlist->update([
            'status' => PlaylistStatus::Processing,
            'errors' => null,
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
            $results = Http::withUserAgent($userAgent)
                ->timeout(60 * 5) // set timeout to five minues
                ->throw()
                ->get($url->toString());

            // If fetched successfully, process the results!
            if ($results) {
                $m3uParser = new M3uParser();
                $m3uParser->addDefaultTags();
                $data = $m3uParser->parse($results);

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
                        yield $channel;
                    }
                })->groupBy('group')->chunk(200)->each(function (LazyCollection $grouped) use (&$jobs, $userId, $playlistId, $batchNo) {
                    foreach ($grouped->toArray() as $group => $channels) {
                        $group = Group::firstOrCreate([
                            ['name', $group],
                            ['playlist_id', $playlistId],
                            ['user_id', $userId],
                        ]);
                        $group->update([
                            'import_batch_no' => $batchNo,
                        ]);
                        $jobs[] = new ProcessChannelImport($playlistId, $batchNo, $group, $channels);
                    }
                });

                // Last job in the batch
                $jobs[] = new ProcessM3uImportComplete($userId, $playlistId, $batchNo, $start);
                Bus::chain($jobs)
                    ->onConnection('redis') // force to use redis connection
                    ->catch(function (Throwable $e) use ($playlist) {
                        $error = "Unable to process the proved playlist: {$e->getMessage()}";
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
                ]);
            }
        } catch (\Exception $e) {
            // Log the exception
            logger()->error($e->getMessage());

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
            ]);
        }
        return;
    }
}
