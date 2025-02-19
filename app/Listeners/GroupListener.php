<?php

namespace App\Listeners;

use App\Models\Group;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class GroupListener implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(Group $event): void
    {
        //
    }
}
