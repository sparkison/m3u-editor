<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Epg;
use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Services\EpgCacheService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EpgApiController extends Controller
{
    /**
     * Get EPG data for viewing with pagination support
     *
     * @param string $uuid
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getData(string $uuid, Request $request)
    {
        $epg = Epg::where('uuid', $uuid)->firstOrFail();
        $cacheService = new EpgCacheService();
        
        // Pagination parameters
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);

        // Date parameters
        $startDate = $request->get('start_date', Carbon::now()->format('Y-m-d'));
        $endDate = $request->get('end_date', Carbon::parse($startDate)->format('Y-m-d'));
        
        Log::info('EPG API Request', [
            'uuid' => $uuid,
            'page' => $page,
            'per_page' => $perPage,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        try {
            // Check if cache exists and is valid
            if (!$cacheService->isCacheValid($epg)) {
                // If cache is invalid, try to regenerate it
                Log::info("Cache invalid for EPG {$epg->name}, attempting regeneration");
                $cacheGenerated = $cacheService->cacheEpgData($epg);
                
                if (!$cacheGenerated) {
                    return response()->json([
                        'error' => 'Failed to generate EPG cache. Please try refreshing the EPG.',
                        'suggestion' => 'Try using the "Refresh EPG" button to regenerate the data.'
                    ], 500);
                }
            }

            // Get cached channels with pagination
            $channelData = $cacheService->getCachedChannels($epg, $page, $perPage);
            $channels = $channelData['channels'];
            $pagination = $channelData['pagination'];

            // Get cached programmes for the requested date and channels
            $channelIds = array_keys($channels);
            $programmes = $cacheService->getCachedProgrammes($epg, $startDate, $channelIds);

            // Get cache metadata
            $metadata = $cacheService->getCacheMetadata($epg);

            return response()->json([
                'epg' => [
                    'id' => $epg->id,
                    'name' => $epg->name,
                    'uuid' => $epg->uuid,
                ],
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'pagination' => $pagination,
                'channels' => $channels,
                'programmes' => $programmes,
                'cache_info' => [
                    'cached' => true,
                    'cache_created' => $metadata['cache_created'] ?? null,
                    'total_programmes' => $metadata['total_programmes'] ?? 0,
                    'programme_date_range' => $metadata['programme_date_range'] ?? null,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error retrieving EPG data for {$epg->name}: {$e->getMessage()}");
            return response()->json([
                'error' => 'Failed to retrieve EPG data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get EPG data for a specific playlist with pagination support
     *
     * @param string $uuid Playlist UUID
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDataForPlaylist(string $uuid, Request $request)
    {
        // Find the playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->first();
        }
        
        if (!$playlist) {
            return response()->json(['error' => 'Playlist not found'], 404);
        }

        $cacheService = new EpgCacheService();
        
        // Pagination parameters
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);

        // Date parameters
        $startDate = $request->get('start_date', Carbon::now()->format('Y-m-d'));
        $endDate = $request->get('end_date', Carbon::parse($startDate)->format('Y-m-d'));
        
        Log::info('EPG API Request for Playlist', [
            'playlist_uuid' => $uuid,
            'playlist_name' => $playlist->name,
            'page' => $page,
            'per_page' => $perPage,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        try {
            // Get enabled channels from the playlist
            $playlistChannels = $playlist->channels()
                ->where('enabled', true)
                ->with('epgChannel')
                ->orderBy('sort')
                ->orderBy('channel')
                ->orderBy('title')
                ->get();

            // Group channels by EPG and collect EPG data
            $epgChannelMap = [];
            $epgIds = [];
            $playlistChannelData = [];

            foreach ($playlistChannels as $channel) {
                $epgData = $channel->epgChannel ?? null;
                
                if ($epgData) {
                    $epgId = $epgData->epg_id;
                    $epgIds[] = $epgId;
                    
                    if (!isset($epgChannelMap[$epgId])) {
                        $epgChannelMap[$epgId] = [];
                    }
                    
                    // Map EPG channel ID to playlist channel info
                    $epgChannelMap[$epgId][$epgData->channel_id] = [
                        'playlist_channel_id' => $channel->id,
                        'title' => $channel->title_custom ?? $channel->title,
                        'display_name' => $channel->name_custom ?? $channel->name,
                        'channel_number' => $channel->channel,
                        'group' => $channel->group ?? $channel->group_internal,
                        'logo' => $channel->logo ?? ''
                    ];
                }
                
                // Store channel data for pagination
                $playlistChannelData[] = [
                    'id' => $channel->id,
                    'title' => $channel->title_custom ?? $channel->title,
                    'display_name' => $channel->name_custom ?? $channel->name,
                    'channel_number' => $channel->channel,
                    'group' => $channel->group ?? $channel->group_internal,
                    'logo' => $channel->logo ?? '',
                    'has_epg' => $epgData !== null,
                    'epg_channel_id' => $epgData->channel_id ?? null
                ];
            }

            // Apply pagination to playlist channels
            $totalChannels = count($playlistChannelData);
            $skip = ($page - 1) * $perPage;
            $paginatedChannels = array_slice($playlistChannelData, $skip, $perPage);
            
            // Convert to associative array format for consistency with getData
            $channels = [];
            foreach ($paginatedChannels as $channel) {
                $channels[$channel['id']] = $channel;
            }

            // Get EPG data from cache for the paginated channels
            $programmes = [];
            $epgIds = array_unique($epgIds);
            
            foreach ($epgIds as $epgId) {
                $epg = Epg::find($epgId);
                if (!$epg) continue;

                // Check if cache exists and is valid
                if (!$cacheService->isCacheValid($epg)) {
                    Log::info("Cache invalid for EPG {$epg->name}, attempting regeneration");
                    $cacheGenerated = $cacheService->cacheEpgData($epg);
                    
                    if (!$cacheGenerated) {
                        Log::warning("Failed to generate cache for EPG {$epg->name}");
                        continue;
                    }
                }

                // Get the EPG channel IDs we need for this EPG
                $neededEpgChannelIds = array_keys($epgChannelMap[$epgId] ?? []);
                
                // Get programmes from cache
                $epgProgrammes = $cacheService->getCachedProgrammes($epg, $startDate, $neededEpgChannelIds);
                
                // Map programmes to playlist channels
                foreach ($epgProgrammes as $epgChannelId => $channelProgrammes) {
                    if (isset($epgChannelMap[$epgId][$epgChannelId])) {
                        $playlistChannelInfo = $epgChannelMap[$epgId][$epgChannelId];
                        $playlistChannelId = $playlistChannelInfo['playlist_channel_id'];
                        
                        // Only include programmes for channels in current page
                        if (isset($channels[$playlistChannelId])) {
                            $programmes[$playlistChannelId] = $channelProgrammes;
                        }
                    }
                }
            }

            // Create pagination info
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_channels' => $totalChannels,
                'returned_channels' => count($paginatedChannels),
                'has_more' => ($skip + $perPage) < $totalChannels,
                'next_page' => ($skip + $perPage) < $totalChannels ? $page + 1 : null,
            ];

            return response()->json([
                'playlist' => [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'uuid' => $playlist->uuid,
                    'type' => get_class($playlist),
                ],
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'pagination' => $pagination,
                'channels' => $channels,
                'programmes' => $programmes,
                'cache_info' => [
                    'cached' => true,
                    'epg_count' => count($epgIds),
                    'channels_with_epg' => count(array_filter($playlistChannelData, fn($ch) => $ch['has_epg'])),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error retrieving EPG data for playlist {$playlist->name}: {$e->getMessage()}");
            return response()->json([
                'error' => 'Failed to retrieve EPG data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Parse XMLTV datetime format
     *
     * @param string $datetime
     * @return Carbon|null
     */
    private function parseXmltvDateTime($datetime)
    {
        try {
            // Format: YYYYMMDDHHMMSS +ZZZZ or YYYYMMDDHHMMSS
            if (preg_match('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\s*([+-]\d{4})?/', $datetime, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
                $hour = $matches[4];
                $minute = $matches[5];
                $second = $matches[6];
                $timezone = $matches[7] ?? '+0000';

                $dateString = "{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}";
                
                // Convert timezone offset to proper format
                if (preg_match('/([+-])(\d{2})(\d{2})/', $timezone, $tzMatches)) {
                    $tzString = $tzMatches[1] . $tzMatches[2] . ':' . $tzMatches[3];
                    $dateString .= ' ' . $tzString;
                }

                return Carbon::parse($dateString);
            }
        } catch (\Exception $e) {
            // Return null if parsing fails
        }

        return null;
    }
}
