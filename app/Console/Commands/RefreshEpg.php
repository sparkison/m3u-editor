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
            $twentyFourHoursAgo = now()->subDay();
            $epgs = Epg::query()->where(
                'status',
                '!=',
                EpgStatus::Processing,
            )->whereDate('synced', '<=', $twentyFourHoursAgo);
            $count = $epgs->count();
            if ($count === 0) {
                $this->info('No EPGs ready refresh');
                return;
            }
            $epgs->get()->each(fn(Epg $playlist) => dispatch(new ProcessEpgImport($playlist)));
            $this->info('Dispatched ' . $count . ' epgs for refresh');
        }
        return;
    }
}
