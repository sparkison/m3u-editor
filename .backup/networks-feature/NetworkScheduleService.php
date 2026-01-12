<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\NetworkProgramme;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NetworkScheduleService
{
    /**
     * Default schedule generation window in days.
     */
    protected int $scheduleWindowDays = 7;

    /**
     * Minimum content duration in seconds (fallback for missing duration).
     */
    protected int $minimumDurationSeconds = 1800; // 30 minutes

    /**
     * Generate the programme schedule for a network.
     */
    public function generateSchedule(Network $network, ?Carbon $startFrom = null): void
    {
        $startFrom = $startFrom ?? Carbon::now();
        $endAt = $startFrom->copy()->addDays($this->scheduleWindowDays);

        Log::info("Generating schedule for network {$network->name}", [
            'network_id' => $network->id,
            'start_from' => $startFrom->toDateTimeString(),
            'end_at' => $endAt->toDateTimeString(),
        ]);

        // Get all content for this network
        $contentItems = $this->getOrderedContent($network);

        if ($contentItems->isEmpty()) {
            Log::warning("No content found for network {$network->name}");

            return;
        }

        DB::transaction(function () use ($network, $startFrom, $endAt, $contentItems) {
            // Clear future programmes (keep past for history)
            $network->programmes()
                ->where('start_time', '>=', $startFrom)
                ->delete();

            // Generate new schedule
            $currentTime = $startFrom->copy();
            $contentIndex = 0;
            $contentCount = $contentItems->count();

            while ($currentTime->lt($endAt)) {
                $content = $contentItems[$contentIndex];
                $duration = $this->getContentDuration($content);

                // Create programme entry
                NetworkProgramme::create([
                    'network_id' => $network->id,
                    'title' => $this->getContentTitle($content),
                    'description' => $this->getContentDescription($content),
                    'image' => $this->getContentImage($content),
                    'start_time' => $currentTime->copy(),
                    'end_time' => $currentTime->copy()->addSeconds($duration),
                    'duration_seconds' => $duration,
                    'contentable_type' => get_class($content),
                    'contentable_id' => $content->id,
                ]);

                $currentTime->addSeconds($duration);

                // Move to next content (loop if needed)
                $contentIndex++;
                if ($network->loop_content && $contentIndex >= $contentCount) {
                    $contentIndex = 0;
                } elseif (! $network->loop_content && $contentIndex >= $contentCount) {
                    break;
                }
            }

            // Update schedule generation timestamp
            $network->update(['schedule_generated_at' => Carbon::now()]);
        });

        Log::info("Schedule generated for network {$network->name}", [
            'programme_count' => $network->programmes()->count(),
        ]);
    }

    /**
     * Get ordered content based on network schedule type.
     */
    protected function getOrderedContent(Network $network): Collection
    {
        $networkContent = $network->networkContent()->with('contentable')->get();

        if ($networkContent->isEmpty()) {
            return collect();
        }

        return match ($network->schedule_type) {
            'shuffle' => $this->shuffleContent($networkContent),
            'sequential' => $networkContent->sortBy('sort_order')->pluck('contentable'),
            default => $networkContent->sortBy('sort_order')->pluck('contentable'),
        };
    }

    /**
     * Shuffle content with weighting support.
     */
    protected function shuffleContent(Collection $networkContent): Collection
    {
        // Build weighted list
        $weighted = collect();
        foreach ($networkContent as $item) {
            for ($i = 0; $i < $item->weight; $i++) {
                $weighted->push($item->contentable);
            }
        }

        return $weighted->shuffle();
    }

    /**
     * Get the duration of a content item in seconds.
     */
    protected function getContentDuration(Episode|Channel $content): int
    {
        if ($content instanceof Episode) {
            $duration = (int) ($content->info['duration'] ?? 0);

            return $duration > 0 ? $duration : $this->minimumDurationSeconds;
        }

        if ($content instanceof Channel) {
            $duration = (int) ($content->movie_data['info']['duration_secs'] ?? 0);

            return $duration > 0 ? $duration : $this->minimumDurationSeconds;
        }

        return $this->minimumDurationSeconds;
    }

    /**
     * Get the title for a content item.
     */
    protected function getContentTitle(Episode|Channel $content): string
    {
        if ($content instanceof Episode) {
            $series = $content->series;
            $seasonNum = $content->season ?? 1;
            $episodeNum = $content->episode_num ?? 1;

            return $series
                ? "{$series->name} S{$seasonNum}E{$episodeNum}"
                : $content->title;
        }

        return $content->name ?? $content->title ?? 'Unknown';
    }

    /**
     * Get the description for a content item.
     */
    protected function getContentDescription(Episode|Channel $content): ?string
    {
        if ($content instanceof Episode) {
            return $content->info['plot'] ?? $content->plot ?? null;
        }

        if ($content instanceof Channel) {
            return $content->movie_data['info']['plot'] ?? null;
        }

        return null;
    }

    /**
     * Get the image URL for a content item.
     */
    protected function getContentImage(Episode|Channel $content): ?string
    {
        if ($content instanceof Episode) {
            return $content->cover ?? $content->info['movie_image'] ?? null;
        }

        if ($content instanceof Channel) {
            return $content->logo ?? $content->movie_data['info']['cover_big'] ?? null;
        }

        return null;
    }

    /**
     * Get the currently airing programme for a network.
     */
    public function getCurrentProgramme(Network $network): ?NetworkProgramme
    {
        $now = Carbon::now();

        return $network->programmes()
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->first();
    }

    /**
     * Get upcoming programmes for a network.
     */
    public function getUpcomingProgrammes(Network $network, int $limit = 10): Collection
    {
        $now = Carbon::now();

        return $network->programmes()
            ->where('start_time', '>', $now)
            ->orderBy('start_time')
            ->limit($limit)
            ->get();
    }

    /**
     * Regenerate schedules for all networks that need it.
     */
    public function regenerateStaleSchedules(): void
    {
        $networks = Network::where('enabled', true)->get();

        foreach ($networks as $network) {
            if ($network->needsScheduleRegeneration()) {
                $this->generateSchedule($network);
            }
        }
    }
}
