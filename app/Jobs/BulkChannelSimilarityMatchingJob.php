<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Services\ChannelSimilarityMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Filament\Notifications\Notification as FilamentNotification;

class BulkChannelSimilarityMatchingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private array $channelIds,
        private int $userId,
        private array $options = []
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $similarityService = new ChannelSimilarityMatchingService();
        
        // Get primary channels (those not marked as fallback candidates)
        $primaryChannels = Channel::whereIn('id', $this->channelIds)
            ->where('user_id', $this->userId)
            ->where('is_fallback_candidate', false)
            ->get();
            
        // Get all fallback candidate channels for this user
        $fallbackCandidates = Channel::where('user_id', $this->userId)
            ->where('is_fallback_candidate', true)
            ->get();

        $matchesCreated = 0;
        $matchesSkipped = 0;
        
        $minThreshold = $this->options['min_threshold'] ?? 0.75;
        $overwriteExisting = $this->options['overwrite_existing'] ?? false;

        foreach ($primaryChannels as $primaryChannel) {
            try {
                // Skip if already has failovers and not overwriting
                if (!$overwriteExisting && $primaryChannel->failovers()->exists()) {
                    $matchesSkipped++;
                    continue;
                }

                // Find similar channels
                $similarChannels = $similarityService->findSimilarChannels(
                    $primaryChannel, 
                    $fallbackCandidates,
                    false // debug
                );

                // Filter by threshold
                $filteredMatches = $similarChannels->filter(function ($match) use ($minThreshold) {
                    return $match['similarity'] >= $minThreshold;
                });

                if ($filteredMatches->isEmpty()) {
                    continue;
                }

                // If overwriting, remove existing failovers first
                if ($overwriteExisting) {
                    ChannelFailover::where('channel_id', $primaryChannel->id)->delete();
                }

                // Create failover relationships for matches above threshold
                foreach ($filteredMatches as $match) {
                    ChannelFailover::updateOrCreate([
                        'channel_id' => $primaryChannel->id,
                        'channel_failover_id' => $match['channel']->id,
                    ], [
                        'auto_matched' => true,
                        'match_quality' => $match['similarity'],
                        'match_type' => $match['match_type'] ?? 'similarity'
                    ]);
                    
                    $matchesCreated++;
                }

            } catch (\Exception $e) {
                Log::error("Error processing channel {$primaryChannel->id} in bulk similarity matching", [
                    'error' => $e->getMessage(),
                    'channel_id' => $primaryChannel->id
                ]);
            }
        }

        // Send notification to user
        FilamentNotification::make()
            ->success()
            ->title('Channel Similarity Matching Complete')
            ->body("Created {$matchesCreated} fallover relationships. Skipped {$matchesSkipped} channels with existing failovers.")
            ->persistent()
            ->sendToDatabase(\App\Models\User::find($this->userId));

        Log::info("Bulk channel similarity matching completed", [
            'user_id' => $this->userId,
            'matches_created' => $matchesCreated,
            'matches_skipped' => $matchesSkipped,
            'primary_channels_processed' => $primaryChannels->count(),
            'fallback_candidates_available' => $fallbackCandidates->count()
        ]);
    }
}
