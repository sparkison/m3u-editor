<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use Carbon\CarbonInterval;
use Illuminate\Console\Command;

class RefreshEpg extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-epg {epg?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh EPGs in batch (or specific EPG when ID provided)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $epgId = $this->argument('epg');
        if ($epgId) {
            $this->info("Refreshing EPG with ID: {$epgId}");
            $epg = Epg::findOrFail($epgId);
            dispatch(new ProcessEpgImport($epg));
            $this->info('Dispatched EPG for refresh');
        } else {
            $this->info('Refreshing all EPGs');
            $eightHoursAgo = now()->subHours(8); // lowest interval
            $epgs = Epg::query()->where(
                'status',
                '!=',
                Status::Processing,
            )->whereDate('synced', '<=', $eightHoursAgo);
            $count = $epgs->count();
            if ($count === 0) {
                $this->info('No EPGs ready refresh');
                return;
            }
            $count = 0;
            $epgs->get()->each(function (Epg $epg) use (&$count) {
                // Check the sync interval to see if we need to refresh yet
                $nextSync = $epg->sync_interval
                    ? $epg->synced->add(CarbonInterval::fromString($epg->sync_interval))
                    : $epg->synced->addDay();
                if (!$nextSync->isFuture()) {
                    $count++;
                    dispatch(new ProcessEpgImport($epg));
                }
            });
            $this->info('Dispatched ' . $count . ' epgs for refresh');
        }
        return;
    }
}
