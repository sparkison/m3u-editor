<?php

namespace App\Listeners;

use App\Models\Group;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class GroupListener
{
    /**
     * Create the event listener.
     * 
     * @param Group $group
     */
    public function __construct(
        public Group $group,
    ) {
        //
    }
}
