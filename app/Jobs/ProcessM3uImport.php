<?php

namespace App\Jobs;

use App\Enums\PlaylistStatus;
use App\Models\Channel;
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
                })->chunk(100)->each(function (LazyCollection $channels) use (&$jobs, $userId, $playlistId, $batchNo) {
                    $groups = $channels->map(fn($ch) => [
                        'name' => $ch['group'],
                        'playlist_id' => $playlistId,
                        'user_id' => $userId,
                        'import_batch_no' => $batchNo,
                    ]);
                    // Add the jobs to the batch
                    // Import the groups first, then the channels
                    $jobs[] = new ProcessGroupImport($userId, $playlistId, $batchNo, $groups->toArray());
                    $jobs[] = new ProcessChannelImport($playlistId, $batchNo, $channels->toArray());
                });
                Bus::batch($jobs)
                    ->onConnection('redis')
                    ->then(function (Batch $batch)  {
                        // The batch has been completed successfully...
                    })->catch(function (Batch $batch, Throwable $e) {
                        // First batch job failure detected...
                    })->finally(function (Batch $batch) use ($playlist, $batchNo, $start) {
                        // All jobs completed...

                        // Calculate the time taken to complete the import
                        $completedIn = $start->diffInSeconds(now());
                        $completedInRounded = round($completedIn, 2);

                        // Send notification
                        Notification::make()
                            ->success()
                            ->title('Playlist Synced')
                            ->body("\"{$playlist->name}\" has been synced successfully.")
                            ->broadcast($playlist->user);
                        Notification::make()
                            ->success()
                            ->title('Playlist Synced')
                            ->body("\"{$playlist->name}\" has been synced successfully. Import completed in {$completedInRounded} seconds.")
                            ->sendToDatabase($playlist->user);

                        // Clear out invalid groups (if any)
                        Group::where([
                            ['playlist_id', $playlist->id],
                            ['import_batch_no', '!=', $batchNo],
                        ])->delete();

                        // Clear out invalid channels (if any)
                        Channel::where([
                            ['playlist_id', $playlist->id],
                            ['import_batch_no', '!=', $batchNo],
                        ])->delete();

                        // Update the playlist
                        $playlist->update([
                            'status' => PlaylistStatus::Completed,
                            'channels' => 0, // not using...
                            'synced' => now(),
                            'errors' => null,
                            'sync_time' => $completedIn
                        ]);
                    })->name('Playlist channel import')->dispatch();
            } else {
                // Update the playlist
                $error = "Unable to fetch the playlist from the provided URL.";
                // Send notification
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
