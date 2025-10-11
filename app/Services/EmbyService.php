<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbyService
{
    private ?string $serverUrl;
    private ?string $apiKey;

    public function __construct()
    {
        $settings = app(GeneralSettings::class);
        $this->serverUrl = $settings->emby_server_url ? rtrim($settings->emby_server_url, '/') : null;
        $this->apiKey = $settings->emby_api_key;
    }

    /**
     * Check if Emby is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->serverUrl) && !empty($this->apiKey);
    }

    /**
     * Test connection to Emby server
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Emby server URL and API key must be configured');
        }

        try {
            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(10)->get($this->serverUrl . '/System/Info');

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to connect. Status: ' . $response->status(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get all libraries from Emby server
     */
    public function getLibraries(): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Emby server URL and API key must be configured');
        }

        try {
            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(30)->get($this->serverUrl . '/Library/VirtualFolders');

            if ($response->successful()) {
                return $response->json();
            }

            throw new Exception('Failed to fetch libraries. Status: ' . $response->status());
        } catch (Exception $e) {
            Log::error('Emby getLibraries error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get items from a specific library
     */
    public function getLibraryItems(string $libraryId, string $itemType = 'Movie'): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Emby server URL and API key must be configured');
        }

        try {
            $url = $this->serverUrl . '/Items';
            $params = [
                'ParentId' => $libraryId,
                'IncludeItemTypes' => $itemType,
                'Recursive' => 'true',
                'Fields' => 'Path,Overview,Genres,Studios,Tags,ProductionYear,PremiereDate,CommunityRating,OfficialRating,MediaStreams,MediaSources',
            ];
            
            Log::info('Emby API Request', [
                'url' => $url,
                'params' => $params,
            ]);

            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(60)->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Emby API Response', [
                    'total_items' => count($data['Items'] ?? []),
                    'sample' => isset($data['Items'][0]) ? $data['Items'][0]['Name'] : 'none',
                ]);
                return $data['Items'] ?? [];
            }

            Log::error('Emby API failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new Exception('Failed to fetch library items. Status: ' . $response->status() . ' - ' . $response->body());
        } catch (Exception $e) {
            Log::error('Emby getLibraryItems error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get detailed information about a specific item
     */
    public function getItem(string $itemId): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Emby server URL and API key must be configured');
        }

        try {
            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(30)->get($this->serverUrl . "/Items/{$itemId}");

            if ($response->successful()) {
                return $response->json();
            }

            throw new Exception('Failed to fetch item details. Status: ' . $response->status());
        } catch (Exception $e) {
            Log::error('Emby getItem error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get seasons for a TV series
     */
    public function getSeasons(string $seriesId): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Emby server URL and API key must be configured');
        }

        try {
            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(30)->get($this->serverUrl . '/Shows/' . $seriesId . '/Seasons', [
                'Fields' => 'Overview',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['Items'] ?? [];
            }

            throw new Exception('Failed to fetch seasons. Status: ' . $response->status());
        } catch (Exception $e) {
            Log::error('Emby getSeasons error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get episodes for a season
     */
    public function getEpisodes(string $seriesId, string $seasonId): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Emby server URL and API key must be configured');
        }

        try {
            $response = Http::withHeaders([
                'X-Emby-Token' => $this->apiKey,
            ])->timeout(30)->get($this->serverUrl . '/Shows/' . $seriesId . '/Episodes', [
                'SeasonId' => $seasonId,
                'Fields' => 'Path,Overview,MediaStreams,MediaSources',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['Items'] ?? [];
            }

            throw new Exception('Failed to fetch episodes. Status: ' . $response->status());
        } catch (Exception $e) {
            Log::error('Emby getEpisodes error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get image URL for an item
     */
    public function getImageUrl(string $itemId, string $imageType = 'Primary'): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return $this->serverUrl . "/Items/{$itemId}/Images/{$imageType}?api_key=" . $this->apiKey;
    }

    /**
     * Get streaming URL for an item
     */
    public function getStreamUrl(string $itemId): string
    {
        if (!$this->isConfigured()) {
            throw new Exception('Emby server URL and API key must be configured');
        }

        // Use lowercase 'static' parameter as per Emby API specification
        return $this->serverUrl . "/Videos/{$itemId}/stream?api_key=" . $this->apiKey . "&Static=true";
    }

    /**
     * Get direct file path from item
     */
    public function getFilePath(array $item): ?string
    {
        return $item['Path'] ?? $item['MediaSources'][0]['Path'] ?? null;
    }
}