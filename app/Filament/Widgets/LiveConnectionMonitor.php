<?php

namespace App\Filament\Widgets;

use App\Models\SharedStream;
use App\Models\SharedStreamClient;
use App\Services\StreamMonitorService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Redis;

class LiveConnectionMonitor extends Widget
{
    protected static string $view = 'filament.widgets.live-connection-monitor';
    protected static ?int $sort = 10;
    protected static ?string $pollingInterval = '5s';
    protected int | string | array $columnSpan = 'full';

    public function getViewData(): array
    {
        return [
            'recentConnections' => $this->getRecentConnections(),
            'connectionStats' => $this->getConnectionStats(),
            'activeConnections' => $this->getActiveConnections(),
        ];
    }

    protected function getRecentConnections(): array
    {
        return SharedStreamClient::with('stream')
            ->where('connected_at', '>=', now()->subMinutes(5))
            ->orderBy('connected_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($client) {
                return [
                    'id' => $client->client_id,
                    'ip' => $client->ip_address,
                    'stream_title' => 'Stream ' . substr($client->stream?->stream_id ?? '', -8),
                    'stream_id' => substr($client->stream?->stream_id ?? '', -8),
                    'connected_at' => $client->connected_at,
                    'duration' => $client->connected_at->diffInSeconds(now()),
                    'status' => $client->isActive() ? 'active' : 'inactive',
                    'bandwidth' => $client->bandwidth_kbps,
                ];
            })
            ->toArray();
    }

    protected function getConnectionStats(): array
    {
        $now = now();
        
        return [
            'last_minute' => SharedStreamClient::where('connected_at', '>=', $now->copy()->subMinute())->count(),
            'last_5_minutes' => SharedStreamClient::where('connected_at', '>=', $now->copy()->subMinutes(5))->count(),
            'last_hour' => SharedStreamClient::where('connected_at', '>=', $now->copy()->subHour())->count(),
            'active_now' => SharedStreamClient::where('status', 'connected')
                ->where('last_activity_at', '>=', $now->copy()->subMinutes(2))
                ->count(),
        ];
    }

    protected function getActiveConnections(): array
    {
        $monitorService = app(StreamMonitorService::class);
        $stats = $monitorService->getStreamingStats();
        
        return [
            'total_active_streams' => $stats['shared_streams']['total_streams'] ?? 0,
            'total_clients' => $stats['shared_streams']['total_clients'] ?? 0,
            'total_bandwidth' => array_sum(array_column($stats['shared_streams']['streams'] ?? [], 'bandwidth_kbps')),
            'streams_by_format' => $this->getStreamsByFormat(),
        ];
    }

    protected function getStreamsByFormat(): array
    {
        return SharedStream::active()
            ->selectRaw('format, COUNT(*) as count, SUM(client_count) as total_clients')
            ->groupBy('format')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->format => [
                    'streams' => $item->count,
                    'clients' => $item->total_clients,
                ]];
            })
            ->toArray();
    }
}
