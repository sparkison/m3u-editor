<?php

namespace App\Jobs;

use App\Enums\EpgStatus;
use Throwable;
use Exception;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Job;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

class MapPlaylistChannelsToEpg implements ShouldQueue
{
    use Queueable;

    public $deleteWhenMissingModels = true;

    // Giving a timeout of 15 minutes to the Job to process the mapping
    public $timeout = 60 * 15;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $epg,
        public ?int $playlist = null,
        public ?array $channels = null,
        public ?bool $force = false,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Flag job start time
        $start = now();
        $batchNo = Str::orderedUuid()->toString();

        // Fetch the EPG
        $epg = Epg::find($this->epg);
        if (!$epg) {
            $error = "Unable to map to the selected EPG, it no longer exists. Please select a different EPG and try again.";
            Notification::make()
                ->danger()
                ->title("Error processing EPG channel mapping")
                ->body('Please view your notifications for details.')
                ->broadcast($epg->user);
            Notification::make()
                ->danger()
                ->title("Error processing EPG channel mapping")
                ->body($error)
                ->sendToDatabase($epg->user);
            return;
        }

        // Create the record
        $playlist = $this->playlist ? Playlist::find($this->playlist) : null;
        $subtext = $playlist ? ' -> ' . $playlist->name . ' mapping' : ' custom channel mapping';
        $map = EpgMap::create([
            'name' => $epg->name . $subtext,
            'epg_id' => $epg->id,
            'user_id' => $epg->user_id,
            'uuid' => $batchNo,
            'status' => EpgStatus::Processing,
            'processing' => true,
            'override' => $this->force,
        ]);

        try {
            // Fetch the playlist (if set)
            $channels = [];
            if ($this->channels) {
                $channels = Channel::whereIn('id', $this->channels)
                    ->when(!$this->force, function ($query) {
                        $query->where('epg_channel_id', null);
                    })->cursor();
            } else {
                if ($playlist) {
                    $channels = $playlist->channels()
                        ->when(!$this->force, function ($query) {
                            $query->where('epg_channel_id', null);
                        })->cursor();
                }
            }


            // Update the progress
            $progress = 0;
            $map->update(['progress' => $progress += 2]); // start at 2%

            // Map the channels
            $channelCount = 0;
            $mappedCount = 0;
            LazyCollection::make(function () use ($channels, $epg, &$channelCount, &$mappedCount) {
                foreach ($channels as $channel) {
                    // Increment counter
                    $channelCount++;

                    // Get the EPG channel
                    $epgChannel = $epg->channels()
                        ->where('channel_id', '!=', '')
                        ->where(function ($sub) use ($channel) {
                            return $sub->where('channel_id', '=', $channel->sream_id)
                                ->orWhere('channel_id', '=', $channel->name);
                        })
                        ->select('id', 'channel_id')
                        ->first();

                    // If EPG channel found, link it to the Playlist channel
                    if ($epgChannel) {
                        $mappedCount++;
                        $channel->epg_channel_id = $epgChannel->id;
                        yield $channel->toArray();
                    }
                }
            })->chunk(50)->each(function ($chunk) use ($epg, $batchNo) {
                Job::create([
                    'title' => "Processing channel import for EPG: {$epg->name}",
                    'batch_no' => $batchNo,
                    'payload' => $chunk->toArray(),
                    'variables' => [
                        'epgId' => $epg->id,
                    ]
                ]);
            });

            // Update the progress
            $map->update(['progress' => $progress += 20]);

            // Get the jobs for the batch
            $jobs = [];
            $batchCount = Job::where('batch_no', $batchNo)->count();
            $jobsBatch = Job::where('batch_no', $batchNo)->select('id')->cursor();
            $jobsBatch->chunk(50)->each(function ($chunk) use (&$jobs, $batchCount, $batchNo) {
                $jobs[] = new MapEpgToChannels($chunk->pluck('id')->toArray(), $batchCount, $batchNo);
            });

            // Last job in the batch
            $jobs[] = new MapEpgToChannelsComplete($epg, $batchCount, $channelCount, $mappedCount, $batchNo, $start);

            // Dispatch the batch
            Bus::chain($jobs)
                ->onConnection('redis') // force to use redis connection
                ->onQueue('import')
                ->catch(function (Throwable $e) use ($epg, $map) {
                    $error = "Error processing \"{$epg->name}\" mapping: {$e->getMessage()}";
                    Notification::make()
                        ->danger()
                        ->title("Error processing \"{$epg->name}\" mapping")
                        ->body('Please view your notifications for details.')
                        ->broadcast($epg->user);
                    Notification::make()
                        ->danger()
                        ->title("Error processing \"{$epg->name}\" mapping")
                        ->body($error)
                        ->sendToDatabase($epg->user);
                    $map->update([
                        'status' => EpgStatus::Failed,
                        'channels' => 0, // not using...
                        'errors' => $error,
                        'progress' => 100,
                        'processing' => false,
                    ]);
                })->dispatch();
        } catch (Exception $e) {
            // Log the exception
            logger()->error("Error processing \"{$epg->name}\" mapping: {$e->getMessage()}");

            // Send notification
            Notification::make()
                ->danger()
                ->title("Error processing \"{$epg->name}\" mapping")
                ->body('Please view your notifications for details.')
                ->broadcast($epg->user);
            Notification::make()
                ->danger()
                ->title("Error processing \"{$epg->name}\" mapping")
                ->body($e->getMessage())
                ->sendToDatabase($epg->user);

            // Update the playlist
            $map->update([
                'status' => EpgStatus::Failed,
                'errors' => $e->getMessage(),
                'progress' => 100,
                'processing' => false,
            ]);
        }
    }
}
