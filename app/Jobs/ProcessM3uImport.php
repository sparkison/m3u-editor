<?php

namespace App\Jobs;

use App\Enums\PlaylistStatus;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use zikwall\m3ucontentparser\M3UContentParser;
use Illuminate\Support\Str;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Spatie\TemporaryDirectory\TemporaryDirectory;

use Throwable;

class ProcessM3uImport implements ShouldQueue
{
    use Queueable;

    // Giving a timeout of 10 minutes to the Job to process the file
    public $timeout = 600;

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
            $batchNo = Str::uuid7()->toString();

            // Normalize the playlist url and get the filename
            $url = str($playlist->url)->replace(' ', '%20');
            $tmpFile = $url->afterLast('/') . '.tmp';

            // We need to grab the file contents first and set to temp file
            $userAgent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13';
            $tmpDir = (new TemporaryDirectory())
                ->location('public')
                ->force()
                ->create();
            $tmpPath = $tmpDir->path($tmpFile);
            Http::sink($tmpPath)->withUserAgent($userAgent)
                ->throw()
                ->get($url->toString());

            // If fetched successfully, process the downloaded file!
            if (is_file($tmpPath)) {
                // Use LazyCollection to handle large files
                $commonFields = [
                    'playlist_id' => $playlistId,
                    'user_id' => $userId,
                    'import_batch_no' => $batchNo
                ];

                // Setup the parser
                $parser = new M3UContentParser($tmpPath);
                $parser->parse();

                // Extract the channels and groups from the m3u
                $groups = [];
                $channels = [];
                foreach ($parser->all() as $item) {
                    $groupTitle = $item->getGroupTitle();
                    $channels[] = [
                        ...$commonFields,
                        'stream_id' => $item->getId(), // usually null/empty
                        'name' => $item->getTvgName(),
                        'url' => $item->getTvgUrl(),
                        'logo' => $item->getTvgLogo(),
                        'group' => $groupTitle,
                        'lang' => $item->getLanguage(), // usually null/empty
                        'country' => $item->getCountry(), // usually null/empty
                    ];
                    if (!in_array($groupTitle, $groups)) {
                        $groups[] = $groupTitle;
                    }
                }
                $groups = array_map(function ($name) use ($commonFields) {
                    return [...$commonFields, 'name' => $name];
                }, $groups);

                // Chunk the jobs
                $jobs = [];
                foreach (array_chunk($groups, 200) as $chunk) {
                    $jobs[] = new ProcessGroupImport(
                        $playlistId,
                        $batchNo,
                        $chunk
                    );
                }
                foreach (array_chunk($channels, 200) as $chunk) {
                    $jobs[] = new ProcessChannelImport(
                        $playlistId,
                        $batchNo,
                        $chunk
                    );
                }

                // Clean up the temporary directory and files
                $tmpDir->delete();

                Bus::batch($jobs)
                    ->then(function (Batch $batch) use ($playlist, $batchNo, $start) {
                        // All jobs completed successfully...

                        // Send notification
                        Notification::make()
                            ->success()
                            ->title('Playlist Synced')
                            ->body("\"{$playlist->name}\" has been synced successfully.")
                            ->broadcast($playlist->user);
                        Notification::make()
                            ->success()
                            ->title('Playlist Synced')
                            ->body("\"{$playlist->name}\" has been synced successfully.")
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
                            'sync_time' => $start->diffInSeconds(now())
                        ]);
                    })->catch(function (Batch $batch, Throwable $e) {
                        // First batch job failure detected...
                    })->finally(function (Batch $batch) {
                        // The batch has finished executing...
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
