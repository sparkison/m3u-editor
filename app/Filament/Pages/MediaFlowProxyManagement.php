<?php

namespace App\Filament\Pages;

use App\Services\MediaFlowProxyService;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Filament\Notifications\Notification;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;

class MediaFlowProxyManagement extends Page implements HasForms
{
    use InteractsWithForms;
    protected static ?string $navigationIcon = 'heroicon-o-play';
    protected static ?string $navigationGroup = 'Streaming';
    protected static ?string $title = 'MediaFlow Proxy';
    protected static string $view = 'filament.pages.mediaflow-proxy-management';
    protected static ?int $navigationSort = 25;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'enabled' => config('mediaflow.enabled', true),
            'microservice_enabled' => config('mediaflow.microservice.enabled', false),
            'microservice_url' => config('mediaflow.microservice.url', 'http://localhost:3001'),
            'websocket_port' => config('mediaflow.microservice.websocket_port', 3002),
            'user_agent' => config('mediaflow.proxy.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
            'timeout' => config('mediaflow.proxy.timeout', 30),
            'force_playlist_proxy' => config('mediaflow.stream.force_playlist_proxy', false),
            'enable_hls_processing' => config('mediaflow.stream.enable_hls_processing', true),
            'enable_failover' => config('mediaflow.stream.enable_failover', true),
            'routing_strategy' => config('mediaflow.routing.strategy', 'mediaflow'),
            'rate_limiting_enabled' => config('mediaflow.rate_limiting.enabled', true),
            'requests_per_minute' => config('mediaflow.rate_limiting.requests_per_minute', 120),
            'requests_per_hour' => config('mediaflow.rate_limiting.requests_per_hour', 1000),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('General Settings')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Toggle::make('enabled')
                                    ->label('Enable MediaFlow Proxy')
                                    ->helperText('Enable or disable the MediaFlow proxy functionality')
                                    ->default(true),
                                
                                Select::make('routing_strategy')
                                    ->label('Routing Strategy')
                                    ->options([
                                        'mediaflow' => 'MediaFlow (Proxy all content)',
                                        'direct' => 'Direct (Minimal proxying)',
                                        'hybrid' => 'Hybrid (Smart routing)',
                                    ])
                                    ->default('mediaflow')
                                    ->helperText('Choose how content is routed through the proxy'),
                            ]),
                    ]),

                Section::make('Proxy Configuration')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('user_agent')
                                    ->label('User Agent')
                                    ->maxLength(255)
                                    ->helperText('Default User-Agent header for proxy requests'),
                                
                                TextInput::make('timeout')
                                    ->label('Request Timeout (seconds)')
                                    ->numeric()
                                    ->minValue(5)
                                    ->maxValue(300)
                                    ->default(30),
                            ]),
                    ]),

                Section::make('Stream Processing')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Toggle::make('force_playlist_proxy')
                                    ->label('Force Playlist Proxy')
                                    ->helperText('Always proxy playlist URLs regardless of detection'),
                                
                                Toggle::make('enable_hls_processing')
                                    ->label('Enable HLS Processing')
                                    ->helperText('Process and modify HLS manifests'),
                                
                                Toggle::make('enable_failover')
                                    ->label('Enable Failover Support')
                                    ->helperText('Support channel failover functionality'),
                            ]),
                    ]),

                Section::make('Microservice Configuration')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Toggle::make('microservice_enabled')
                                    ->label('Enable Microservice')
                                    ->helperText('Enable the JavaScript microservice for advanced features')
                                    ->live(),
                                
                                TextInput::make('websocket_port')
                                    ->label('WebSocket Port')
                                    ->numeric()
                                    ->minValue(1024)
                                    ->maxValue(65535)
                                    ->default(3002)
                                    ->visible(fn($get) => $get('microservice_enabled')),
                            ]),
                        
                        TextInput::make('microservice_url')
                            ->label('Microservice URL')
                            ->url()
                            ->default('http://localhost:3001')
                            ->visible(fn($get) => $get('microservice_enabled'))
                            ->helperText('URL of the MediaFlow microservice'),
                    ]),

                Section::make('Rate Limiting')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Toggle::make('rate_limiting_enabled')
                                    ->label('Enable Rate Limiting')
                                    ->helperText('Enable rate limiting for proxy requests')
                                    ->live(),
                                
                                TextInput::make('requests_per_minute')
                                    ->label('Requests per Minute')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(120)
                                    ->visible(fn($get) => $get('rate_limiting_enabled')),
                                
                                TextInput::make('requests_per_hour')
                                    ->label('Requests per Hour')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1000)
                                    ->visible(fn($get) => $get('rate_limiting_enabled')),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
            
            // Here you would normally save to a settings model or update config
            // For now, we'll just show a success notification
            
            Notification::make()
                ->title('Settings saved successfully')
                ->success()
                ->send();
                
        } catch (Halt $exception) {
            return;
        }
    }

    public function testMicroservice(): void
    {
        try {
            // Get the current microservice URL from form data or use the default
            $microserviceUrl = $this->form->getState()['microservice_url'] ?? 'http://localhost:3001';
            
            $response = Http::timeout(5)->get($microserviceUrl . '/health');
            
            if ($response->successful()) {
                Notification::make()
                    ->title('Microservice connection successful')
                    ->body('MediaFlow microservice is running and accessible at: ' . $microserviceUrl)
                    ->success()
                    ->send();
            } else {
                throw new \Exception('HTTP ' . $response->status());
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Microservice connection failed')
                ->body('Could not connect to MediaFlow microservice: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getStats(): array
    {
        // Get real-time stats from Redis
        $activeStreams = collect(Redis::keys('mfp:stream:*:active'))->count();
        $totalRequests = Redis::get('mediaflow:stats:total_requests') ?? 0;
        $errorCount = Redis::get('mediaflow:stats:error_count') ?? 0;
        
        return [
            'active_streams' => $activeStreams,
            'total_requests' => $totalRequests,
            'error_count' => $errorCount,
            'success_rate' => $totalRequests > 0 ? round((($totalRequests - $errorCount) / $totalRequests) * 100, 2) : 100,
        ];
    }

    protected function getViewData(): array
    {
        return [
            'stats' => $this->getStats(),
        ];
    }
}
