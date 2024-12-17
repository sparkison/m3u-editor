<?php

namespace App\Jobs;

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
        try {
            $parser = new M3UContentParser($this->playlist->url);
            $parser->parse();
            $count = 0;
            $bulk = collect([]);
            $groups = collect([]);
            foreach ($parser->all() as $item) {
                /**
                 * @var $item M3UItem
                 */
                $bulk->push([
                    'playlist_id' => $this->playlist->id,
                    'enabled' => true, // enabled by default
                    'name' => $item->getTvgName(),
                    'url' => $item->getTvgUrl(),
                    'group' => $item->getGroupTitle(),
                    'tvgid' => $item->getId(),
                    'logo' => $item->getTvgLogo(),
                    'language' => $item->getLanguage(),
                    'country' => $item->getCountry(),
                ]);
                if (!$groups->contains($item->getGroupTitle())) {
                    $groups->push($item->getGroupTitle());
                }

                // Increment the counter
                $count++;
            }

            foreach ($groups as $group) {
                $g = \App\Models\Group::firstOrCreate([
                    'name' => $group,
                ]);
            }

            $this->playlist->update([
                'channels' => $count,
                'synced' => now(),
            ]);
        } catch (\Exception $e) {
            $this->playlist->update([
                'channels' => 0,
                'synced' => now(),
                'errors' => $e->getMessage(),
            ]);
            return;
        }
    }
}
