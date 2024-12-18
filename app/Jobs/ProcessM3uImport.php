<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

use zikwall\m3ucontentparser\M3UContentParser;
use zikwall\m3ucontentparser\M3UItem;

class ProcessM3uImport implements ShouldQueue
{
    use Queueable;

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
        $this->playlist->update([
            'status' => 'processing',
            'synced' => now(),
            'errors' => null,
        ]);
        try {
            $playlistId = $this->playlist->id;

            $parser = new M3UContentParser($this->playlist->url);
            $parser->parse();

            $count = 0;
            $bulk = collect([]);
            $groups = collect([]);

            foreach ($parser->all() as $item) {
                /**
                 * @var M3UItem $item 
                 */
                $bulk->push([
                    'playlist_id' => $playlistId,
                    'stream_id' => $item->getId(),
                    'shift' => $item->getTvgShift(),
                    'name' => $item->getTvgName(),
                    'url' => $item->getTvgUrl(),
                    'logo' => $item->getTvgLogo(),
                    'group' => $item->getGroupTitle(),
                    'lang' => $item->getLanguage(),
                    'country' => $item->getCountry(),
                ]);
                if (!$groups->contains('title', $item->getGroupTitle())) {
                    $groups->push([
                        'playlist_id' => $playlistId,
                        'id' => null,
                        'title' => $item->getGroupTitle()
                    ]);
                }

                // Increment the counter
                $count++;
            }

            // Find/create the channel groups
            $groups = $groups->map(function ($group) {
                $model = \App\Models\Group::firstOrCreate([
                    'playlist_id' => $group['playlist_id'],
                    'name' => $group['title'],
                ]);
                return [
                    ...$group,
                    'id' => $model->id,
                ];
            });

            // Link the channel groups to the channels
            $bulk = $bulk->filter(function ($channel) use ($groups) {
                $model = Channel::where([
                    'playlist_id' => $channel['playlist_id'],
                    'stream_id' => $channel['stream_id'],
                ])->first();
                if ($model) {
                    // Update the existing channel, if found
                    $model->update([
                        ...$channel,
                        'group_id' => $groups->firstWhere('title', $channel['group'])['id'],
                    ]);
                    return false;
                } else {
                    return true;
                }
            })->map(function ($channel) use ($groups) {
                return [
                    ...$channel,
                    'group_id' => $groups->firstWhere('title', $channel['group'])['id'],
                ];
            });

            // Insert the new channels in bulk
            Channel::insert($bulk->toArray());

            // Update the playlist
            $this->playlist->update([
                'status' => 'completed',
                'channels' => $count,
                'synced' => now(),
                'errors' => null,
            ]);
        } catch (\Exception $e) {
            $this->playlist->update([
                'status' => 'failed',
                'channels' => 0,
                'synced' => now(),
                'errors' => $e->getMessage(),
            ]);
            return;
        }
    }
}
