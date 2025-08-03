<?php

namespace App\Services;

use App\Models\Epg;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
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
            // Check if EPG file has been modified since cache was created
            $epgFilePath = Storage::disk('local')->path($epg->file_path);
            if (!file_exists($epgFilePath)) {
                return false;
            }

            // Use json_decode for metadata parsing since it will be a small file
            $metadata = json_decode(Storage::disk('local')->get($metadataPath), true);

            $epgFileModified = filemtime($epgFilePath);
            $cacheCreated = $metadata['cache_created'] ?? 0;

            return $epgFileModified <= $cacheCreated;
        } catch (\Exception $e) {
            Log::warning("Invalid cache metadata for EPG {$epg->uuid}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Cache EPG data from XML file using memory-efficient streaming
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
            set_time_limit(600); // 10 minutes

            // Start by clearing existing cache
            $this->clearCache($epg);
            $cacheDir = $this->getCacheDir($epg);
            Storage::disk('local')->makeDirectory($cacheDir);

            // Parse and save channels using streaming
            Log::debug("Parsing and saving channels for {$epg->name}");
            $channelCount = $this->parseAndSaveChannels($epg, $epgFilePath);
            Log::debug("Processed {$channelCount} channels");

            // Parse and save programmes using streaming by date
            Log::debug("Parsing and saving programmes for {$epg->name}");
            $programmeStats = $this->parseAndSaveProgrammes($epg, $epgFilePath);
            Log::debug("Processed {$programmeStats['total']} programmes across {$programmeStats['date_count']} dates");

            // Save metadata
            $metadata = [
                'epg_uuid' => $epg->uuid,
                'epg_name' => $epg->name,
                'cache_created' => time(),
                'cache_version' => self::CACHE_VERSION,
                'total_channels' => $channelCount,
                'total_programmes' => $programmeStats['total'],
                'programme_date_range' => $programmeStats['date_range'],
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
     * Parse and save channels using memory-efficient streaming
     */
    private function parseAndSaveChannels(Epg $epg, string $filePath): int
    {
        $channelCount = 0;
        $batchSize = 1000; // Process channels in batches
        $channelBatch = [];

        foreach ($this->parseChannelsStream($filePath) as $channelId => $channel) {
            $channelBatch[$channelId] = $channel;
            $channelCount++;

            // Save in batches to manage memory
            if (count($channelBatch) >= $batchSize) {
                $this->saveChannelBatch($epg, $channelBatch, $channelCount <= $batchSize);
                $channelBatch = [];
            }
        }

        // Save remaining channels
        if (!empty($channelBatch)) {
            $this->saveChannelBatch($epg, $channelBatch, $channelCount <= $batchSize);
        }

        return $channelCount;
    }

    /**
     * Parse and save programmes using memory-efficient streaming by date
     */
    private function parseAndSaveProgrammes(Epg $epg, string $filePath): array
    {
        $totalProgrammes = 0;
        $dateRangeTracker = ['min_date' => null, 'max_date' => null];
        $dateFiles = [];

        foreach ($this->parseProgrammesStream($filePath) as $programme) {
            $date = Carbon::parse($programme['start'])->format('Y-m-d');

            // Track date range
            if ($dateRangeTracker['min_date'] === null || $date < $dateRangeTracker['min_date']) {
                $dateRangeTracker['min_date'] = $date;
            }
            if ($dateRangeTracker['max_date'] === null || $date > $dateRangeTracker['max_date']) {
                $dateRangeTracker['max_date'] = $date;
            }

            // Initialize date file if not exists
            if (!isset($dateFiles[$date])) {
                $dateFiles[$date] = [];
            }
            if (!isset($dateFiles[$date][$programme['channel']])) {
                $dateFiles[$date][$programme['channel']] = [];
            }

            $dateFiles[$date][$programme['channel']][] = $programme;
            $totalProgrammes++;

            // Save and clear memory periodically for each date
            if (count($dateFiles[$date]) > 100) { // Batch by number of channels per date
                $this->saveDateProgrammes($epg, $date, $dateFiles[$date]);
                $dateFiles[$date] = [];
            }
        }

        // Save remaining programmes
        foreach ($dateFiles as $date => $dateProgrammes) {
            if (!empty($dateProgrammes)) {
                $this->saveDateProgrammes($epg, $date, $dateProgrammes);
            }
        }

        return [
            'total' => $totalProgrammes,
            'date_count' => count(array_keys($dateFiles)),
            'date_range' => $dateRangeTracker,
        ];
    }

    /**
     * Stream parse channels from EPG file using generators
     */
    private function parseChannelsStream(string $filePath): \Generator
    {
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
                    yield $channelId => $channel;
                }
            }
        }
        $channelReader->close();
    }

    /**
     * Stream parse programmes from EPG file using generators
     */
    private function parseProgrammesStream(string $filePath): \Generator
    {
        $programReader = new XMLReader();
        $programReader->open('compress.zlib://' . $filePath);
        $processedCount = 0;

        while (@$programReader->read()) {
            if ($programReader->nodeType == XMLReader::ELEMENT && $programReader->name === 'programme') {
                $processedCount++;

                // Safety limit
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
                    yield $programme;
                }
            }
        }
        $programReader->close();
    }

    /**
     * Save channel batch to file using memory-efficient approach
     */
    private function saveChannelBatch(Epg $epg, array $channelBatch, bool $isFirst): void
    {
        $channelsPath = $this->getCacheFilePath($epg, self::CHANNELS_FILE);

        if ($isFirst) {
            // First batch - create new file
            Storage::disk('local')->put(
                $channelsPath,
                json_encode($channelBatch, JSON_UNESCAPED_UNICODE)
            );
        } else {
            // Subsequent batches - merge with existing data using JsonMachine
            $existingData = [];

            if (Storage::disk('local')->exists($channelsPath)) {
                try {
                    $existingStream = Items::fromFile(
                        Storage::disk('local')->path($channelsPath),
                        ['decoder' => new ExtJsonDecoder(true)]
                    );

                    // Convert existing data to array (should be relatively small for channels)
                    foreach ($existingStream as $channelId => $channel) {
                        $existingData[$channelId] = $channel;
                    }
                } catch (\Exception $e) {
                    Log::warning("Could not read existing channel data, creating new file: {$e->getMessage()}");
                    $existingData = [];
                }
            }

            $mergedData = array_merge($existingData, $channelBatch);
            Storage::disk('local')->put(
                $channelsPath,
                json_encode($mergedData, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * Save programmes for a specific date using memory-efficient approach
     */
    private function saveDateProgrammes(Epg $epg, string $date, array $dateProgrammes): void
    {
        $filename = "programmes-{$date}.json";
        $programmesPath = $this->getCacheFilePath($epg, $filename);

        if (Storage::disk('local')->exists($programmesPath)) {
            // Merge with existing data for this date using JsonMachine
            $existingData = [];

            try {
                $existingStream = Items::fromFile(
                    Storage::disk('local')->path($programmesPath),
                    ['decoder' => new ExtJsonDecoder(true)]
                );

                // Convert existing data to array for merging
                foreach ($existingStream as $channelId => $programmes) {
                    $existingData[$channelId] = $programmes;
                }
            } catch (\Exception $e) {
                Log::warning("Could not read existing programme data for {$date}, creating new file: {$e->getMessage()}");
                $existingData = [];
            }

            // Merge programmes
            foreach ($dateProgrammes as $channelId => $programmes) {
                if (!isset($existingData[$channelId])) {
                    $existingData[$channelId] = [];
                }
                $existingData[$channelId] = array_merge($existingData[$channelId], $programmes);
            }
            $dateProgrammes = $existingData;
        }

        Storage::disk('local')->put(
            $programmesPath,
            json_encode($dateProgrammes, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Get cached channels using memory-efficient streaming
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

        try {
            // Use JsonMachine for memory-efficient parsing - single iteration
            $channelsStream = Items::fromFile(
                Storage::disk('local')->path($channelsPath),
                ['decoder' => new ExtJsonDecoder(true)]
            );

            // Single pass through the data to collect pagination info
            $channels = [];
            $totalChannels = 0;
            $skip = ($page - 1) * $perPage;
            $collected = 0;
            $hasMore = false;

            foreach ($channelsStream as $channelId => $channel) {
                $totalChannels++;

                // Skip to the desired page
                if ($totalChannels <= $skip) {
                    continue;
                }

                // Collect channels for this page
                if ($collected < $perPage) {
                    $channels[$channelId] = $channel;
                    $collected++;
                } else {
                    // We have enough for this page, and there's at least one more
                    $hasMore = true;
                    break;
                }
            }

            return [
                'channels' => $channels,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_channels' => $skip + $collected + ($hasMore ? 1 : 0), // Estimate
                    'returned_channels' => count($channels),
                    'has_more' => $hasMore,
                    'next_page' => $hasMore ? $page + 1 : null,
                ]
            ];
        } catch (\Exception $e) {
            Log::error("Error reading cached channels: {$e->getMessage()}");
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
    }

    /**
     * Get cached programmes for a specific date and channels using memory-efficient streaming
     */
    public function getCachedProgrammes(Epg $epg, string $date, array $channelIds = []): array
    {
        $programmesPath = $this->getCacheFilePath($epg, "programmes-{$date}.json");

        if (!Storage::disk('local')->exists($programmesPath)) {
            return [];
        }

        try {
            // Use JsonMachine for memory-efficient parsing
            $programmesStream = Items::fromFile(
                Storage::disk('local')->path($programmesPath),
                ['decoder' => new ExtJsonDecoder(true)]
            );

            $programmes = [];
            foreach ($programmesStream as $channelId => $channelProgrammes) {
                // Filter by channel IDs if provided
                if (!empty($channelIds) && !in_array($channelId, $channelIds)) {
                    continue;
                }
                $programmes[$channelId] = $channelProgrammes;
            }

            return $programmes;
        } catch (\Exception $e) {
            Log::error("Error reading cached programmes for date {$date}: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Get cached programmes for a date range and channels using memory-efficient streaming
     */
    public function getCachedProgrammesRange(Epg $epg, string $startDate, string $endDate, array $channelIds = []): array
    {
        $allProgrammes = [];
        $currentDate = Carbon::parse($startDate);
        $endDateCarbon = Carbon::parse($endDate);

        while ($currentDate <= $endDateCarbon) {
            $dateStr = $currentDate->format('Y-m-d');

            // Stream programmes for this date
            foreach ($this->streamCachedProgrammesForDate($epg, $dateStr, $channelIds) as $channelId => $programmes) {
                if (!isset($allProgrammes[$channelId])) {
                    $allProgrammes[$channelId] = [];
                }
                $allProgrammes[$channelId] = array_merge($allProgrammes[$channelId], $programmes);
            }
            $currentDate->addDay();
        }

        // Sort programmes by start time within each channel using generators
        foreach ($allProgrammes as $channelId => $programmes) {
            usort($allProgrammes[$channelId], function ($a, $b) {
                return strcmp($a['start'], $b['start']);
            });
        }

        return $allProgrammes;
    }

    /**
     * Stream cached programmes for a specific date using generators
     */
    private function streamCachedProgrammesForDate(Epg $epg, string $date, array $channelIds = []): \Generator
    {
        $programmesPath = $this->getCacheFilePath($epg, "programmes-{$date}.json");
        if (!Storage::disk('local')->exists($programmesPath)) {
            return;
        }
        try {
            // Use JsonMachine for memory-efficient parsing
            $programmesStream = Items::fromFile(
                Storage::disk('local')->path($programmesPath),
                ['decoder' => new ExtJsonDecoder(true)]
            );
            foreach ($programmesStream as $channelId => $channelProgrammes) {
                // Filter by channel IDs if provided
                if (!empty($channelIds) && !in_array($channelId, $channelIds)) {
                    continue;
                }

                yield $channelId => $channelProgrammes;
            }
        } catch (\Exception $e) {
            Log::error("Error streaming cached programmes for date {$date}: {$e->getMessage()}");
        }
    }

    /**
     * Get cache metadata using memory-efficient parsing
     */
    public function getCacheMetadata(Epg $epg): ?array
    {
        $metadataPath = $this->getCacheFilePath($epg, self::METADATA_FILE);
        if (!Storage::disk('local')->exists($metadataPath)) {
            return null;
        }
        try {
            $metadata = json_decode(Storage::disk('local')->get($metadataPath), true);
            return $metadata;
        } catch (\Exception $e) {
            Log::error("Error reading cache metadata: {$e->getMessage()}");
            return null;
        }
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
