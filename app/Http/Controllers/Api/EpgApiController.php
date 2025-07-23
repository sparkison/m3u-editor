<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Epg;
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
