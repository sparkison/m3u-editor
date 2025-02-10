<?php

namespace App\Filament\Resources;

use Croustibat\FilamentJobsMonitor\Resources\QueueMonitorResource as Resource;

class QueueMonitorResource extends Resource
{
    public static function getNavigationSort(): ?int
    {
        return 99; // Sort after all other resources
    }
}
