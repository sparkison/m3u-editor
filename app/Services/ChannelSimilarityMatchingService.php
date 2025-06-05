<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\ChannelFailover;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ChannelSimilarityMatchingService
{
    // Configurable parameters for matching
    private $bestMatchThreshold = 3;        // Levenshtein distance threshold for best matches
    private $goodMatchThreshold = 5;        // Levenshtein distance threshold for good matches
    private $cosineSimilarityThreshold = 0.75; // Cosine similarity threshold

    // Words to ignore when normalizing channel names
    private $stopWords = [
        'tv', 'channel', 'network', 'television', 'hd', 'uhd', 'fhd', 'sd',
        'us', 'usa', 'uk', 'ca', 'de', 'fr', 'it', 'es', 'nl', 'be', 'au',
        '1080p', '720p', '540p', '480p', '360p', '4k', 'live', 'stream',
        'online', 'web', 'digital', 'plus', 'premium', 'max', 'pro', 'super'
    ];

    /**
     * Find potential fallback channels for a given channel based on name similarity
     *
     * @param Channel $primaryChannel
     * @param Collection $candidateChannels
     * @param bool $debug
     * @return Collection
     */
    public function findSimilarChannels(Channel $primaryChannel, Collection $candidateChannels, bool $debug = false): Collection
    {
        $primaryName = $this->normalizeChannelName($primaryChannel->title_custom ?? $primaryChannel->title ?? '');
        $primaryNameAlt = $this->normalizeChannelName($primaryChannel->name_custom ?? $primaryChannel->name ?? '');
        
        if (empty($primaryName) && empty($primaryNameAlt)) {
            if ($debug) {
                Log::info("Channel {$primaryChannel->id} has no valid names for matching");
            }
            return collect();
        }

        $matches = collect();

        foreach ($candidateChannels as $candidate) {
            if ($candidate->id === $primaryChannel->id) {
                continue; // Skip self
            }

            $candidateName = $this->normalizeChannelName($candidate->title_custom ?? $candidate->title ?? '');
            $candidateNameAlt = $this->normalizeChannelName($candidate->name_custom ?? $candidate->name ?? '');

            // Calculate similarity scores for all name combinations
            $scores = [];
            
            if (!empty($primaryName) && !empty($candidateName)) {
                $scores[] = $this->calculateSimilarityScore($primaryName, $candidateName);
            }
            if (!empty($primaryName) && !empty($candidateNameAlt)) {
                $scores[] = $this->calculateSimilarityScore($primaryName, $candidateNameAlt);
            }
            if (!empty($primaryNameAlt) && !empty($candidateName)) {
                $scores[] = $this->calculateSimilarityScore($primaryNameAlt, $candidateName);
            }
            if (!empty($primaryNameAlt) && !empty($candidateNameAlt)) {
                $scores[] = $this->calculateSimilarityScore($primaryNameAlt, $candidateNameAlt);
            }

            if (empty($scores)) {
                continue;
            }

            // Use the best (lowest) Levenshtein score
            $bestScore = min(array_column($scores, 'levenshtein'));
            $bestMatch = collect($scores)->firstWhere('levenshtein', $bestScore);

            // Only consider channels that meet our similarity threshold
            if ($bestScore <= $this->goodMatchThreshold) {
                $matches->push([
                    'channel' => $candidate,
                    'similarity_score' => $bestScore,
                    'cosine_similarity' => $bestMatch['cosine'],
                    'match_quality' => $this->getMatchQuality($bestScore, $bestMatch['cosine']),
                    'matched_names' => [
                        'primary' => $bestMatch['primary_name'],
                        'candidate' => $bestMatch['candidate_name']
                    ]
                ]);

                if ($debug) {
                    $quality = $this->getMatchQuality($bestScore, $bestMatch['cosine']);
                    Log::info("Channel {$primaryChannel->id} '{$primaryName}' matched with {$candidate->id} '{$candidateName}' - Score: {$bestScore}, Quality: {$quality}");
                }
            }
        }

        // Sort by similarity score (lower is better for Levenshtein)
        return $matches->sortBy('similarity_score');
    }

    /**
     * Calculate similarity score between two normalized channel names
     *
     * @param string $name1
     * @param string $name2
     * @return array
     */
    private function calculateSimilarityScore(string $name1, string $name2): array
    {
        // Calculate Levenshtein distance
        $levenshtein = levenshtein($name1, $name2);
        
        // Calculate cosine similarity
        $cosine = $this->cosineSimilarity(
            $this->textToVector($name1),
            $this->textToVector($name2)
        );

        return [
            'levenshtein' => $levenshtein,
            'cosine' => $cosine,
            'primary_name' => $name1,
            'candidate_name' => $name2
        ];
    }

    /**
     * Determine match quality based on scores
     *
     * @param int $levenshteinScore
     * @param float $cosineSimilarity
     * @return string
     */
    private function getMatchQuality(int $levenshteinScore, float $cosineSimilarity): string
    {
        if ($levenshteinScore <= $this->bestMatchThreshold && $cosineSimilarity >= $this->cosineSimilarityThreshold) {
            return 'excellent';
        } elseif ($levenshteinScore <= $this->bestMatchThreshold) {
            return 'very_good';
        } elseif ($levenshteinScore <= $this->goodMatchThreshold && $cosineSimilarity >= $this->cosineSimilarityThreshold) {
            return 'good';
        } elseif ($levenshteinScore <= $this->goodMatchThreshold) {
            return 'fair';
        }
        return 'poor';
    }

    /**
     * Automatically create failover relationships for similar channels
     *
     * @param Channel $primaryChannel
     * @param Collection $similarChannels
     * @param string $minQuality
     * @return int Number of failovers created
     */
    public function createFailoverRelationships(Channel $primaryChannel, Collection $similarChannels, string $minQuality = 'good'): int
    {
        $qualityOrder = ['excellent', 'very_good', 'good', 'fair', 'poor'];
        $minQualityIndex = array_search($minQuality, $qualityOrder);
        
        if ($minQualityIndex === false) {
            $minQualityIndex = 2; // Default to 'good'
        }

        $created = 0;
        $sortOrder = 1;

        foreach ($similarChannels as $match) {
            $qualityIndex = array_search($match['match_quality'], $qualityOrder);
            
            if ($qualityIndex !== false && $qualityIndex <= $minQualityIndex) {
                // Check if failover relationship already exists
                $exists = ChannelFailover::where([
                    'channel_id' => $primaryChannel->id,
                    'channel_failover_id' => $match['channel']->id,
                ])->exists();

                if (!$exists) {
                    ChannelFailover::create([
                        'user_id' => $primaryChannel->user_id,
                        'channel_id' => $primaryChannel->id,
                        'channel_failover_id' => $match['channel']->id,
                        'sort' => $sortOrder++,
                        'metadata' => [
                            'auto_matched' => true,
                            'similarity_score' => $match['similarity_score'],
                            'cosine_similarity' => $match['cosine_similarity'],
                            'match_quality' => $match['match_quality'],
                            'matched_names' => $match['matched_names'],
                            'created_at' => now()->toISOString(),
                        ]
                    ]);
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * Find and group duplicate channels (channels with very similar names)
     *
     * @param Collection $channels
     * @param bool $debug
     * @return Collection
     */
    public function findDuplicateGroups(Collection $channels, bool $debug = false): Collection
    {
        $groups = collect();
        $processedChannels = collect();

        foreach ($channels as $channel) {
            if ($processedChannels->contains($channel->id)) {
                continue; // Already processed in a group
            }

            // Find all similar channels for this channel
            $similarChannels = $this->findSimilarChannels($channel, $channels, $debug);
            
            // Only create a group if we have excellent or very good matches
            $excellentMatches = $similarChannels->filter(function ($match) {
                return in_array($match['match_quality'], ['excellent', 'very_good']);
            });

            if ($excellentMatches->isNotEmpty()) {
                $group = collect([$channel]);
                $group = $group->concat($excellentMatches->pluck('channel'));
                
                $groups->push([
                    'primary_channel' => $channel,
                    'channels' => $group,
                    'total_channels' => $group->count(),
                    'best_match_quality' => $excellentMatches->first()['match_quality'] ?? 'none'
                ]);

                // Mark all channels in this group as processed
                $processedChannels = $processedChannels->concat($group->pluck('id'));

                if ($debug) {
                    $channelNames = $group->map(fn($c) => $c->title_custom ?? $c->title)->implode(', ');
                    Log::info("Found duplicate group with {$group->count()} channels: {$channelNames}");
                }
            }
        }

        return $groups;
    }

    /**
     * Public method to calculate similarity between two channel names
     *
     * @param string $name1
     * @param string $name2
     * @return float
     */
    public function calculateSimilarity(string $name1, string $name2): float
    {
        $normalized1 = $this->normalizeChannelName($name1);
        $normalized2 = $this->normalizeChannelName($name2);
        
        $score = $this->calculateSimilarityScore($normalized1, $normalized2);
        
        // Return a combined score (lower levenshtein is better, higher cosine is better)
        // Normalize to 0-1 scale where 1 is perfect match
        $maxLen = max(strlen($normalized1), strlen($normalized2));
        $levenshteinNormalized = $maxLen > 0 ? 1 - ($score['levenshtein'] / $maxLen) : 1;
        
        // Combine levenshtein and cosine (weighted average)
        return ($levenshteinNormalized * 0.6) + ($score['cosine'] * 0.4);
    }



    /**
     * Normalize a channel name for comparison
     *
     * @param string $name
     * @return string
     */
    private function normalizeChannelName(string $name): string
    {
        if (empty($name)) {
            return '';
        }

        $name = strtolower(trim($name));
        
        // Remove content in brackets and parentheses
        $name = preg_replace('/\[.*?\]|\(.*?\)/', '', $name);
        
        // Remove special characters and extra spaces
        $name = preg_replace('/[^\w\s]/', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        
        // Remove stop words
        $words = explode(' ', $name);
        $words = array_diff($words, $this->stopWords);
        
        return trim(implode(' ', $words));
    }

    /**
     * Convert text to word frequency vector
     *
     * @param string $text
     * @return array
     */
    private function textToVector(string $text): array
    {
        $words = explode(' ', $text);
        return array_count_values($words);
    }

    /**
     * Calculate cosine similarity between two vectors
     *
     * @param array $vecA
     * @param array $vecB
     * @return float
     */
    private function cosineSimilarity(array $vecA, array $vecB): float
    {
        if (empty($vecA) || empty($vecB)) {
            return 0.0;
        }

        $dotProduct = 0;
        $magA = 0;
        $magB = 0;

        // Calculate dot product and magnitude of A
        foreach ($vecA as $word => $countA) {
            $countB = $vecB[$word] ?? 0;
            $dotProduct += $countA * $countB;
            $magA += $countA ** 2;
        }

        // Calculate magnitude of B
        foreach ($vecB as $countB) {
            $magB += $countB ** 2;
        }

        $magnitude = sqrt($magA) * sqrt($magB);
        
        if ($magnitude == 0) {
            return 0.0;
        }

        return $dotProduct / $magnitude;
    }
}
