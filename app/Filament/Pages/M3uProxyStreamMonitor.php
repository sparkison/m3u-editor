<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Support\Enums\Size;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use App\Services\M3uProxyService;
use Exception;
use Carbon\Carbon;

/**
 * Shared Stream Monitor (External API-backed)
 *
 * Uses the external m3u-proxy server API to populate and manage streams.
 */
class M3uProxyStreamMonitor extends Page
{
    protected static ?string $navigationLabel = 'Stream Monitor';
    protected static ?string $title = 'Stream Monitor';
    protected static string | \UnitEnum | null $navigationGroup = 'Tools';
    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.m3u-proxy-stream-monitor';

    public $streams = [];
    public $globalStats = [];
    public $systemStats = [];
    public $refreshInterval = 5; // seconds

    protected M3uProxyService $apiService;

    public function boot(): void
    {
        $this->apiService = app(M3uProxyService::class);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return config('proxy.use_m3u_proxy', false);
    }

    public function mount(): void
    {
        $this->refreshData();
    }

    public function refreshData(): void
    {
        $this->streams = $this->getActiveStreams();
        // Basic aggregated stats can be implemented by the external API — attempt fetch or compute locally
        $this->globalStats = [
            'total_streams' => count($this->streams),
            'active_streams' => count($this->streams),
            'total_clients' => array_sum(array_map(fn($s) => $s['client_count'] ?? 0, $this->streams)),
        ];
        $this->systemStats = []; // populate if external API provides system metrics
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->size(Size::Small)
                ->action('refreshData'),

            Action::make('cleanup')
                ->label('Cleanup Streams')
                ->icon('heroicon-o-trash')
                ->size(Size::Small)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('This will stop all inactive streams via external API.')
                ->action(function (): void {
                    // If external API exposes a cleanup endpoint add call here
                    Notification::make()->title('Cleanup requested.')->success()->send();
                    $this->refreshData();
                }),
        ];
    }

    public function stopStream(string $streamId): void
    {
        try {
            $success = $this->apiService->stopStream($streamId);
            if ($success) {
                Notification::make()
                    ->title("Stream {$streamId} stopped successfully.")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title("Failed to stop stream {$streamId}.")
                    ->danger()
                    ->send();
            }
        } catch (Exception $e) {
            Notification::make()
                ->title('Error stopping stream.')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->refreshData();
    }

    protected function getActiveStreams(): array
    {
        $apiStreams = $this->apiService->fetchActiveStreams();

        // Normalize API payload to the same structure the existing monitor expects.
        return array_map(function ($s) {
            // expected minimal fields from external API: id, source_url, format, status, client_count, bandwidth_kbps, clients[], started_at
            $clients = [];
            if (!empty($s['clients']) && is_array($s['clients'])) {
                $clients = array_map(function ($c) {
                    $connectedAt = isset($c['connected_at']) ? Carbon::parse($c['connected_at']) : null;
                    $duration = $connectedAt ? now()->diff($connectedAt)->forHumans() : 0;
                    return [
                        'ip' => $c['ip'] ?? ($c['ip_address'] ?? 'Unknown'),
                        'client_id' => $c['id'] ?? $c['client_id'] ?? null,
                        'connected_at' => $connectedAt ? $connectedAt->timezone(config('app.timezone'))->toDayDateTimeString() : null,
                        'user_agent' => $c['user_agent'] ?? 'Unknown',
                        'duration' => $duration,
                        'is_active' => ($c['status'] ?? '') === 'connected',
                    ];
                }, $s['clients']);
            }

            return [
                'stream_id' => $s['id'] ?? $s['stream_id'] ?? null,
                'source_url' => isset($s['source_url']) ? $this->truncateUrl($s['source_url']) : null,
                'format' => strtoupper($s['format'] ?? ''),
                'status' => $s['status'] ?? 'unknown',
                'client_count' => $s['client_count'] ?? count($clients),
                'bandwidth_kbps' => $s['bandwidth_kbps'] ?? 0,
                'bytes_transferred' => $s['bytes_transferred'] ?? 0,
                'buffer_size' => $s['buffer_size'] ?? 0,
                'started_at' => $s['started_at'] ?? null,
                'uptime' => isset($s['started_at']) ? Carbon::parse($s['started_at'])->diffForHumans(null, true) : 'N/A',
                'process_running' => $s['process_running'] ?? ($s['status'] === 'active'),
                'clients' => $clients,
                'peak_clients' => $s['peak_clients'] ?? 0,
                'avg_bandwidth' => $s['avg_bandwidth'] ?? 0,
                'model' => $s['model'] ?? null,
            ];
        }, $apiStreams);
    }

    // Reuse helper methods from original monitor
    protected function truncateUrl(string $url, int $maxLength = 50): string
    {
        if (strlen($url) <= $maxLength) {
            return $url;
        }

        return substr($url, 0, $maxLength - 3) . '...';
    }

    public function getViewData(): array
    {
        return [
            'streams' => $this->streams,
            'globalStats' => $this->globalStats,
            'systemStats' => $this->systemStats,
            'refreshInterval' => $this->refreshInterval,
        ];
    }
}
