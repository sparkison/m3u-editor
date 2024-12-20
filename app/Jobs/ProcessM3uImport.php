<?php

namespace App\Jobs;

use App\Enums\PlaylistStatus;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Group;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use zikwall\m3ucontentparser\M3UContentParser;
use zikwall\m3ucontentparser\M3UItem;

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
            $playlistId = $this->playlist->id;
            $userId = $this->playlist->user_id;

            $parser = new M3UContentParser($this->playlist->url);
            $parser->parse();

            $count = 0;
            $channels = collect([]);
            $groups = collect([]);

            // Process each row of the M3U file
            foreach ($parser->all() as $item) {
                /**
                 * @var M3UItem $item 
                 */
                $channels->push([
                    'playlist_id' => $playlistId,
                    'user_id' => $userId,
                    'stream_id' => $item->getId(), // usually null/empty
                    'shift' => $item->getTvgShift(), // usually null/empty
                    'name' => $item->getTvgName(),
                    'url' => $item->getTvgUrl(),
                    'logo' => $item->getTvgLogo(),
                    'group' => $item->getGroupTitle(),
                    'lang' => $item->getLanguage(), // usually null/empty
                    'country' => $item->getCountry(), // usually null/empty
                ]);

                // Maintain a list of unique channel groups
                if (!$groups->contains('title', $item->getGroupTitle())) {
                    $groups->push([
                        'id' => null,
                        'playlist_id' => $playlistId,
                        'user_id' => $userId,
                        'name' => $item->getGroupTitle()
                    ]);
                }

                // Increment the counter
                $count++;
            }

            // Send m3u processed data to the channel import job
            dispatch(new ProcessChannelImport(
                $this->playlist,
                $count,
                $groups,
                $channels
            ));
        } catch (\Exception $e) {
            // Log the exception
            logger()->error($e->getMessage());

            // Send notification
            Notification::make()
                ->danger()
                ->title("Error processing '{$this->playlist->name}'")
                ->body('Please view your notifications for details.')
                ->broadcast($this->playlist->user);
            Notification::make()
                ->danger()
                ->title("Error processing '{$this->playlist->name}'")
                ->body($e->getMessage())
                ->sendToDatabase($this->playlist->user);

            // Update the playlist
            $this->playlist->update([
                'status' => PlaylistStatus::Failed,
                'channels' => 0,
                'synced' => now(),
                'errors' => $e->getMessage(),
            ]);
            return;
        }
    }
}
