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
use zikwall\m3ucontentparser\M3UItem;
use Illuminate\Support\Str;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\LazyCollection;

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

        // Surround in a try/catch block to catch any exceptions
        try {
            // Get the playlist
            $playlist = $this->playlist;

            // Get the playlist details
            $playlistId = $playlist->id;
            $userId = $playlist->user_id;
            $batchNo = Str::uuid7()->toString();
            $url = $playlist->url;

            // Use LazyCollection to handle large files
            $commonFields = [
                'playlist_id' => $playlistId,
                'user_id' => $userId,
                'import_batch_no' => $batchNo
            ];
            $m3uData = LazyCollection::make(function () use ($url, $commonFields) {
                $parser = new M3UContentParser($url);
                $parser->parse();

                // Process each row of the M3U file
                foreach ($parser->all() as $item) {
                    yield [
                        ...$commonFields,
                        'stream_id' => $item->getId(), // usually null/empty
                        'name' => $item->getTvgName(),
                        'url' => $item->getTvgUrl(),
                        'logo' => $item->getTvgLogo(),
                        'group' => $item->getGroupTitle(),
                        'lang' => $item->getLanguage(), // usually null/empty
                        'country' => $item->getCountry(), // usually null/empty
                    ];
                }
            });

            $jobs = [];
            foreach ($m3uData->chunk(500) as $chunk) {
                $jobs[] = new ProcessChannelAndGroupImport(
                    $playlistId,
                    $batchNo,
                    $chunk->toArray()
                );
            }
            Bus::batch($jobs)
                ->then(function (Batch $batch) use ($playlist, $batchNo) {
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
                    ]);
                })->catch(function (Batch $batch, Throwable $e) {
                    // First batch job failure detected...
                })->finally(function (Batch $batch) {
                    // The batch has finished executing...
                })->name('Playlist channel import')->dispatch();
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
