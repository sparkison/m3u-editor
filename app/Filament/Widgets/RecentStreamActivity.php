<?php

namespace App\Filament\Widgets;

use App\Models\SharedStream;
use App\Models\SharedStreamClient;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentStreamActivity extends TableWidget
{
    protected static ?string $heading = 'Recent Stream Activity';
    protected static ?int $sort = 9;
    protected static ?string $pollingInterval = '10s';
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SharedStreamClient::query()
                    ->with('stream')
                    ->where('connected_at', '>=', now()->subMinutes(30))
                    ->orderBy('connected_at', 'desc')
                    ->limit(15)
            )
            ->columns([
                Tables\Columns\TextColumn::make('stream.title')
                    ->label('Stream')
                    ->limit(25)
                    ->tooltip(fn ($record) => $record->stream?->title ?? 'N/A')
                    ->default('N/A'),

                Tables\Columns\TextColumn::make('client_id')
                    ->label('Client ID')
                    ->formatStateUsing(fn ($state) => substr($state, -8))
                    ->tooltip(fn ($record) => $record->client_id),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('connected_at')
                    ->label('Connected')
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_activity')
                    ->label('Last Activity')
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('bytes_received')
                    ->label('Data Received')
                    ->formatStateUsing(fn ($state) => $this->formatBytes($state))
                    ->badge()
                    ->color(fn ($state) => $state > 1048576 ? 'success' : 'warning'), // 1MB threshold

                Tables\Columns\TextColumn::make('bandwidth_kbps')
                    ->label('Bandwidth')
                    ->formatStateUsing(fn ($state) => $this->formatBandwidth($state))
                    ->badge()
                    ->color(fn ($state) => $state > 2000 ? 'danger' : ($state > 1000 ? 'warning' : 'success')),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->getStateUsing(fn ($record) => $record->isActive() ? 'active' : 'inactive')
                    ->colors([
                        'success' => 'active',
                        'danger' => 'inactive',
                    ]),

                Tables\Columns\TextColumn::make('duration')
                    ->label('Duration')
                    ->formatStateUsing(function ($record) {
                        if (!$record->connected_at) {
                            return 'N/A';
                        }
                        
                        $seconds = $record->connected_at->diffInSeconds($record->last_activity ?? now());
                        return $this->formatDuration($seconds);
                    }),
            ])
            ->defaultSort('connected_at', 'desc')
            ->paginated(false)
            ->emptyStateHeading('No Recent Activity')
            ->emptyStateDescription('Stream client connections will appear here as they occur.')
            ->emptyStateIcon('heroicon-o-signal');
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 1) . ' ' . $units[$i];
    }

    private function formatBandwidth(int $kbps): string
    {
        if ($kbps === 0) {
            return '0 kbps';
        }

        if ($kbps >= 1000) {
            return round($kbps / 1000, 1) . ' Mbps';
        }

        return $kbps . ' kbps';
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        return $hours . 'h ' . $minutes . 'm';
    }
}
