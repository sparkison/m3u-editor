<?php

namespace App\Console\Commands;

use App\Enums\EpgStatus;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
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
                EpgStatus::Processing,
            )->whereDate('synced', '<=', $eightHoursAgo);
            $count = $epgs->count();
            if ($count === 0) {
                $this->info('No EPGs ready refresh');
                return;
            }
            $epgs->get()->each(function (Epg $epg) {
                // Check the sync interval to see if we need to refresh yet
                $nextSync = $epg->synced->add($epg->interval ?? '24 hours');
                if (!$nextSync->isFuture()) {
                    dispatch(new ProcessEpgImport($epg));
                }
            });
            $this->info('Dispatched ' . $count . ' epgs for refresh');
        }
        return;
    }
}
