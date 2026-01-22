<?php

use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\NetworkProgramme;
use App\Services\NetworkScheduleService;
use Carbon\Carbon;

it('preserves a programme that starts exactly at regeneration boundary and avoids duplicates', function () {
    Carbon::setTestNow($now = Carbon::parse('2026-01-21 11:00:00'));

    $network = Network::factory()->create([
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $c1 = Channel::factory()->create();
    $c2 = Channel::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c2),
        'contentable_id' => $c2->id,
        'sort_order' => 2,
        'weight' => 1,
    ]);

    // Create programme A 10:00-11:00 and programme B 11:00-12:00
    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'A',
        'start_time' => $now->copy()->subHour(),
        'end_time' => $now->copy(),
        'duration_seconds' => 3600,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
    ]);

    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'B',
        'start_time' => $now->copy(),
        'end_time' => $now->copy()->addHour(),
        'duration_seconds' => 3600,
        'contentable_type' => get_class($c2),
        'contentable_id' => $c2->id,
    ]);

    expect($network->programmes()->count())->toBe(2);

    $service = app(NetworkScheduleService::class);

    $service->generateSchedule($network);

    // Assert B still exists and no duplicate starts at now
    $programmesAtNow = $network->programmes()->where('start_time', $now)->get();
    expect($programmesAtNow->count())->toBe(1);
    expect($programmesAtNow->first()->contentable_id)->toBe($c2->id);
});
