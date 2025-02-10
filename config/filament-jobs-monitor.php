<?php

return [
    'resources' => [
        'enabled' => true,
        'label' => 'Job',
        'plural_label' => 'Jobs',
        'navigation_group' => 'Settings',
        'navigation_icon' => 'heroicon-o-cpu-chip',
        'navigation_sort' => true,
        'navigation_count_badge' => true,
        // 'resource' => Croustibat\FilamentJobsMonitor\Resources\QueueMonitorResource::class,
        'resource' => App\Filament\Resources\QueueMonitorResource::class,
        'cluster' => null,
    ],
    'pruning' => [
        'enabled' => true,
        'retention_days' => 3,
    ],
    'queues' => [
        'default'
    ],
];
