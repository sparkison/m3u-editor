<?php

namespace App\Services;

use App\Models\Epg;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use XMLReader;

class EpgCacheService
{
    private const CACHE_VERSION = 'v1';
    private const CHANNELS_FILE = 'channels.json';
    private const METADATA_FILE = 'metadata.json';
    private const MAX_PROGRAMMES = 200000; // Safety limit to prevent memory issues

    /**
     * Get the cache directory path for an EPG
     */
    private function getCacheDir(Epg $epg): string
    {
        return "epg-cache/{$epg->uuid}/" . self::CACHE_VERSION;
    }

    /**
     * Get cache file path
     */
    private function getCacheFilePath(Epg $epg, string $filename): string
    {
        return $this->getCacheDir($epg) . '/' . $filename;
    }

    /**
     * Check if cache is valid
     */
    public function isCacheValid(Epg $epg): bool
    {
        $metadataPath = $this->getCacheFilePath($epg, self::METADATA_FILE);

        if (!Storage::disk('local')->exists($metadataPath)) {
            return false;
        }

        try {
            $metadata = json_decode(Storage::disk('local')->get($metadataPath), true);

            // Check if EPG file has been modified since cache was created
            $epgFilePath = Storage::disk('local')->path($epg->file_path);
            if (!file_exists($epgFilePath)) {
                return false;
            }

            $epgFileModified = filemtime($epgFilePath);
            $cacheCreated = $metadata['cache_created'] ?? 0;

            return $epgFileModified <= $cacheCreated;
        } catch (\Exception $e) {
            Log::warning("Invalid cache metadata for EPG {$epg->uuid}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Cache EPG data from XML file
     */
    public function cacheEpgData(Epg $epg): bool
    {
        $epgFilePath = Storage::disk('local')->path($epg->file_path);

        if (!file_exists($epgFilePath)) {
            Log::error("EPG file not found: {$epgFilePath}");
            return false;
        }

        try {
            Log::debug("Starting EPG cache generation for {$epg->name}");

            // Set memory limit and execution time for large files
            ini_set('memory_limit', '2G');
            set_time_limit(600); // 10 minutes

            // Parse channels and programmes separately for better memory management
            Log::debug("Parsing channels for {$epg->name}");
            $channels = $this->parseChannels($epgFilePath);
            Log::debug("Parsed " . count($channels) . " channels");

            Log::debug("Parsing programmes for {$epg->name}");
            $programmes = $this->parseProgrammes($epgFilePath);
            $totalProgrammes = array_sum(array_map('count', $programmes));
            Log::debug("Parsed {$totalProgrammes} programmes");

            // Start by clearing existing cache
            $this->clearCache($epg);
            $cacheDir = $this->getCacheDir($epg);
            Storage::disk('local')->makeDirectory($cacheDir);

            // Save channels
            Log::debug("Saving channels cache for {$epg->name}");
            Storage::disk('local')->put(
                $this->getCacheFilePath($epg, self::CHANNELS_FILE),
                json_encode($channels, JSON_UNESCAPED_UNICODE)
            );

            // Save programmes (chunked by date for better performance)
            Log::debug("Saving programmes cache for {$epg->name}");
            $this->saveProgrammesByDate($epg, $programmes);

            // Save metadata
            $metadata = [
                'epg_uuid' => $epg->uuid,
                'epg_name' => $epg->name,
                'cache_created' => time(),
                'cache_version' => self::CACHE_VERSION,
                'total_channels' => count($channels),
                'total_programmes' => $totalProgrammes,
                'programme_date_range' => $this->getProgrammeDateRange($programmes),
            ];

            Storage::disk('local')->put(
                $this->getCacheFilePath($epg, self::METADATA_FILE),
                json_encode($metadata, JSON_PRETTY_PRINT)
            );

            // Flag EPG as cached
            $epg->update(['is_cached' => true]);

            Log::debug("EPG cache generated successfully", $metadata);
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to cache EPG data for {$epg->name}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Parse channels from EPG file
     */
    private function parseChannels(string $filePath): array
    {
        $channels = [];
        $channelReader = new XMLReader();
        $channelReader->open('compress.zlib://' . $filePath);

        while (@$channelReader->read()) {
            if ($channelReader->nodeType == XMLReader::ELEMENT && $channelReader->name === 'channel') {
                $channelId = trim($channelReader->getAttribute('id') ?: '');
                $innerXML = $channelReader->readOuterXml();
                $innerReader = new XMLReader();
                $innerReader->xml($innerXML);

                $channel = [
                    'id' => $channelId,
                    'display_name' => '',
                    'icon' => '',
                    'lang' => 'en'
                ];

                while (@$innerReader->read()) {
                    if ($innerReader->nodeType == XMLReader::ELEMENT) {
                        switch ($innerReader->name) {
                            case 'display-name':
                                if (!$channel['display_name']) {
                                    $channel['display_name'] = trim($innerReader->readString() ?: '');
                                    $channel['lang'] = trim($innerReader->getAttribute('lang') ?: '') ?: 'en';
                                }
                                break;
                            case 'icon':
                                $channel['icon'] = trim($innerReader->getAttribute('src') ?: '');
                                break;
                        }
                    }
                }
                $innerReader->close();

                if ($channelId) {
                    $channels[$channelId] = $channel;
                }
            }
        }
        $channelReader->close();

        return $channels;
    }

    /**
     * Parse programmes from EPG file
     */
    private function parseProgrammes(string $filePath): array
    {
        $programmes = [];
        $programReader = new XMLReader();
        $programReader->open('compress.zlib://' . $filePath);
        $processedCount = 0;

        while (@$programReader->read()) {
            if ($programReader->nodeType == XMLReader::ELEMENT && $programReader->name === 'programme') {
                $processedCount++;

                // Safety limit (reduced for memory efficiency)
                if ($processedCount > self::MAX_PROGRAMMES) {
                    Log::warning("Programme processing limit reached at {$processedCount}");
                    break;
                }

                $channelId = trim($programReader->getAttribute('channel') ?: '');
                $start = trim($programReader->getAttribute('start') ?: '');
                $stop = trim($programReader->getAttribute('stop') ?: '');

                if (!$channelId || !$start) {
                    continue;
                }

                $startDateTime = $this->parseXmltvDateTime($start);
                $stopDateTime = $stop ? $this->parseXmltvDateTime($stop) : null;

                if (!$startDateTime) {
                    continue;
                }

                $innerXML = $programReader->readOuterXml();
                $innerReader = new XMLReader();
                $innerReader->xml($innerXML);

                $programme = [
                    'channel' => $channelId,
                    'start' => $startDateTime->toISOString(),
                    'stop' => $stopDateTime ? $stopDateTime->toISOString() : null,
                    'title' => '',
                    'desc' => '',
                    'category' => '',
                    'icon' => ''
                ];

                while (@$innerReader->read()) {
                    if ($innerReader->nodeType == XMLReader::ELEMENT) {
                        switch ($innerReader->name) {
                            case 'title':
                                $programme['title'] = trim($innerReader->readString() ?: '');
                                break;
                            case 'desc':
                                $programme['desc'] = trim($innerReader->readString() ?: '');
                                break;
                            case 'category':
                                if (!$programme['category']) {
                                    $programme['category'] = trim($innerReader->readString() ?: '');
                                }
                                break;
                            case 'icon':
                                $programme['icon'] = trim($innerReader->getAttribute('src') ?: '');
                                break;
                        }
                    }
                }
                $innerReader->close();

                if ($programme['title']) {
                    if (!isset($programmes[$channelId])) {
                        $programmes[$channelId] = [];
                    }
                    $programmes[$channelId][] = $programme;
                }
            }
        }
        $programReader->close();

        return $programmes;
    }

    /**
     * Save programmes chunked by date for better performance
     */
    private function saveProgrammesByDate(Epg $epg, array $programmes): void
    {
        $programmesByDate = [];

        foreach ($programmes as $channelId => $channelProgrammes) {
            foreach ($channelProgrammes as $programme) {
                $date = Carbon::parse($programme['start'])->format('Y-m-d');

                if (!isset($programmesByDate[$date])) {
                    $programmesByDate[$date] = [];
                }
                if (!isset($programmesByDate[$date][$channelId])) {
                    $programmesByDate[$date][$channelId] = [];
                }

                $programmesByDate[$date][$channelId][] = $programme;
            }
        }

        // Save each date's programmes to a separate file
        foreach ($programmesByDate as $date => $dateProgrammes) {
            $filename = "programmes-{$date}.json";
            Storage::disk('local')->put(
                $this->getCacheFilePath($epg, $filename),
                json_encode($dateProgrammes, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * Get cached channels
     */
    public function getCachedChannels(Epg $epg, int $page = 1, int $perPage = 50): array
    {
        $channelsPath = $this->getCacheFilePath($epg, self::CHANNELS_FILE);

        if (!Storage::disk('local')->exists($channelsPath)) {
            return [
                'channels' => [],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_channels' => 0,
                    'returned_channels' => 0,
                    'has_more' => false,
                    'next_page' => null,
                ]
            ];
        }

        $allChannels = json_decode(Storage::disk('local')->get($channelsPath), true);
        $channelsList = array_values($allChannels);

        $totalChannels = count($channelsList);
        $skip = ($page - 1) * $perPage;
        $paginatedChannels = array_slice($channelsList, $skip, $perPage);

        // Convert back to associative array
        $channels = [];
        foreach ($paginatedChannels as $channel) {
            $channels[$channel['id']] = $channel;
        }

        return [
            'channels' => $channels,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_channels' => $totalChannels,
                'returned_channels' => count($paginatedChannels),
                'has_more' => ($skip + $perPage) < $totalChannels,
                'next_page' => ($skip + $perPage) < $totalChannels ? $page + 1 : null,
            ]
        ];
    }

    /**
     * Get cached programmes for a specific date and channels
     */
    public function getCachedProgrammes(Epg $epg, string $date, array $channelIds = []): array
    {
        $programmesPath = $this->getCacheFilePath($epg, "programmes-{$date}.json");

        if (!Storage::disk('local')->exists($programmesPath)) {
            return [];
        }

        // Temporarily increase memory limit for large EPG files
        $originalMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            $programmes = json_decode(Storage::disk('local')->get($programmesPath), true);

            // Filter by channel IDs if provided
            if (!empty($channelIds)) {
                $filtered = [];
                foreach ($channelIds as $channelId) {
                    if (isset($programmes[$channelId])) {
                        $filtered[$channelId] = $programmes[$channelId];
                    }
                }
                return $filtered;
            }

            return $programmes;
        } finally {
            // Restore original memory limit
            ini_set('memory_limit', $originalMemoryLimit);
        }
    }

    /**
     * Get cached programmes for a date range and channels
     */
    public function getCachedProgrammesRange(Epg $epg, string $startDate, string $endDate, array $channelIds = []): array
    {
        $allProgrammes = [];
        $currentDate = Carbon::parse($startDate);
        $endDateCarbon = Carbon::parse($endDate);

        while ($currentDate <= $endDateCarbon) {
            $dateStr = $currentDate->format('Y-m-d');
            $dayProgrammes = $this->getCachedProgrammes($epg, $dateStr, $channelIds);

            // Merge programmes from this date
            foreach ($dayProgrammes as $channelId => $programmes) {
                if (!isset($allProgrammes[$channelId])) {
                    $allProgrammes[$channelId] = [];
                }
                $allProgrammes[$channelId] = array_merge($allProgrammes[$channelId], $programmes);
            }

            $currentDate->addDay();
        }

        // Sort programmes by start time within each channel
        foreach ($allProgrammes as $channelId => $programmes) {
            usort($allProgrammes[$channelId], function ($a, $b) {
                return strcmp($a['start'], $b['start']);
            });
        }

        return $allProgrammes;
    }

    /**
     * Get cache metadata
     */
    public function getCacheMetadata(Epg $epg): ?array
    {
        $metadataPath = $this->getCacheFilePath($epg, self::METADATA_FILE);

        if (!Storage::disk('local')->exists($metadataPath)) {
            return null;
        }

        return json_decode(Storage::disk('local')->get($metadataPath), true);
    }

    /**
     * Clear cache for an EPG
     */
    public function clearCache(Epg $epg): bool
    {
        // Get the cache directory
        $cacheDir = $this->getCacheDir($epg);
        try {
            // Flag EPG as not cached
            $epg->update(['is_cached' => false]);

            // Delete cache directory
            Storage::disk('local')->deleteDirectory($cacheDir);

            // Log cache clearing
            Log::debug("Cleared cache for EPG {$epg->name}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to clear cache for EPG {$epg->name}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Get programme date range
     */
    private function getProgrammeDateRange(array $programmes): array
    {
        $minDate = null;
        $maxDate = null;

        foreach ($programmes as $channelProgrammes) {
            foreach ($channelProgrammes as $programme) {
                $date = Carbon::parse($programme['start']);

                if ($minDate === null || $date->lt($minDate)) {
                    $minDate = $date;
                }
                if ($maxDate === null || $date->gt($maxDate)) {
                    $maxDate = $date;
                }
            }
        }

        return [
            'min_date' => $minDate ? $minDate->format('Y-m-d') : null,
            'max_date' => $maxDate ? $maxDate->format('Y-m-d') : null,
        ];
    }

    /**
     * Parse XMLTV datetime format
     */
    private function parseXmltvDateTime(string $datetime): ?Carbon
    {
        try {
            if (preg_match('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\s*([+-]\d{4})?/', $datetime, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
                $hour = $matches[4];
                $minute = $matches[5];
                $second = $matches[6];
                $timezone = $matches[7] ?? '+0000';

                $dateString = "{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}";

                if (preg_match('/([+-])(\d{2})(\d{2})/', $timezone, $tzMatches)) {
                    $tzString = $tzMatches[1] . $tzMatches[2] . ':' . $tzMatches[3];
                    $dateString .= ' ' . $tzString;
                }

                return Carbon::parse($dateString);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to parse XMLTV datetime: {$datetime}");
        }

        return null;
    }
}
