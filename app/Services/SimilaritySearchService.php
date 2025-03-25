<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use Illuminate\Support\Facades\Log;

class SimilaritySearchService
{
    // Configurable parameters
    private $matchThreshold = 40;
    private $matchBorderLine = 70;
    private $cosineCutoff = 0.65;

    // Words to ignore
    private $stopWords = [
        "tv",
        "channel",
        "network",
        "television",
        "east",
        "west",
        "hd",
        "uhd",
        "us",
        "usa",
        "not",
        "24/7",
        "1080p",
        "720p",
        "540p",
        "480p",
        "arabic",
        "latino",
        "film",
        "movie",
        "movies"
    ];

    /**
     * Find the best matching EPG channel for a given channel.
     * 
     * @param Channel $channel
     * @param Epg $epg
     * 
     * @return EpgChannel|null
     */
    public function findMatchingEpgChannel($channel, $epg = null): ?EpgChannel
    {
        $debug = config('app.debug');
        $regionCode = $epg->preferred_local ? strtolower($epg->preferred_local) : null;
        $fallbackName = trim($channel->title ?: $channel->name);
        $normalizedChan = $this->normalizeChannelName($fallbackName);

        if (!$normalizedChan) {
            if ($debug) {
                Log::info("Channel {$channel->id} '{$fallbackName}' => empty after normalization, skipping");
            }
            return null;
        }

        // Fetch EPG channels
        $epgChannels = $epg->channels()
            ->where(function ($query) use ($normalizedChan) {
                $query->where('channel_id', 'like', '%' . $normalizedChan . '%')
                    ->orWhere('name', 'like', '%' . $normalizedChan . '%');
            });

        // Setup variables
        $bestScore = PHP_INT_MAX; // Levenshtein: lower is better
        $bestMatch = null;
        $bestEpgForEmbedding = null;

        if ($epgChannels->count() === 0) {
            if ($debug) {
                Log::info("Channel {$channel->id} '{$fallbackName}' => no EPG channels found, skipping");
            }
            return null;
        }
        foreach ($epgChannels->cursor() as $epgChannel) {
            $normalizedEpg = $this->normalizeChannelName($epgChannel->name);
            if (!$normalizedEpg) continue;

            // Calculate fuzzy similarity
            $score = levenshtein($normalizedChan, $normalizedEpg);

            // Apply region-based bonus (convert to penalty for Levenshtein)
            if ($regionCode && stripos(strtolower($epgChannel->channel_id . ' ' . $epgChannel->name), $regionCode) !== false) {
                $regionPenalty = strlen($regionCode) * 1.5; // Dynamic scaling
                $score = max(0, $score - $regionPenalty);
            }

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMatch = $epgChannel;
            }

            // Store candidate for embedding similarity if in borderline range
            if ($score >= $this->matchThreshold && $score < $this->matchBorderLine) {
                $bestEpgForEmbedding = $epgChannel;
            }
        }

        // If we have a best match with Levenshtein < matchThreshold, return it
        if ($bestMatch && $bestScore < $this->matchThreshold) {
            if ($debug) {
                Log::info("Channel {$channel->id} '{$fallbackName}' matched with EPG channel_id={$bestMatch->channel_id} (score={$bestScore})");
            }
            return $bestMatch;
        }

        // **Cosine Similarity for Borderline Cases**
        if ($bestEpgForEmbedding) {
            $chanVector = $this->textToVector($normalizedChan);
            $epgVector = $this->textToVector($this->normalizeChannelName($bestEpgForEmbedding->name));
            if (empty($chanVector) || empty($epgVector)) {
                return null;
            }
            $similarity = $this->cosineSimilarity($chanVector, $epgVector);
            if ($similarity >= $this->cosineCutoff) {
                if ($debug) {
                    Log::info("Channel {$channel->id} '{$fallbackName}' matched via cosine similarity with channel_id={$bestEpgForEmbedding->channel_id} (cos-sim={$similarity})");
                }
                return $bestEpgForEmbedding;
            } else {
                if ($debug) {
                    Log::info("Channel {$channel->id} '{$fallbackName}' cosine-similarity with '{$bestEpgForEmbedding->name}' = {$similarity}");
                }
            }
        }

        return null;
    }

    /**
     * Normalize a channel name for similarity comparison.
     * 
     * @param string $name
     * 
     * @return string
     */
    private function normalizeChannelName($name): string
    {
        if (!$name) return '';
        $name = strtolower($name);
        // Remove brackets and parentheses
        $name = preg_replace('/\[.*?\]|\(.*?\)/', '', $name);
        // Remove special characters
        $name = preg_replace('/[^\w\s]/', '', $name);
        // Remove stop words
        $tokens = explode(' ', $name);
        $tokens = array_diff($tokens, $this->stopWords);
        return trim(implode(' ', $tokens));
    }

    /**
     * Convert a text into a word frequency vector.
     * 
     * @param string $text
     * 
     * @return array
     */
    private function textToVector($text): array
    {
        $words = explode(' ', $text);
        $vector = array_count_values($words); // Simple word frequency vector
        return $vector;
    }

    /**
     * Calculate the cosine similarity between two vectors.
     * 
     * @param array $vecA
     * @param array $vecB
     * 
     * @return float
     */
    private function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0;
        $magA = 0;
        $magB = 0;

        foreach ($vecA as $word => $countA) {
            $countB = $vecB[$word] ?? 0;
            $dotProduct += $countA * $countB;
            $magA += $countA ** 2;
        }

        foreach ($vecB as $countB) {
            $magB += $countB ** 2;
        }

        if ($magA == 0 || $magB == 0) return 0;

        return $dotProduct / (sqrt($magA) * sqrt($magB));
    }
}
