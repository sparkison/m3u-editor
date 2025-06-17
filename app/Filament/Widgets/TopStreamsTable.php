<?php

namespace App\Filament\Widgets;

use App\Models\SharedStream;
use App\Models\SharedStreamStat;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TopStreamsTable extends TableWidget
{
    protected static ?string $heading = 'Top Performing Streams';
    protected static ?int $sort = 6;
    protected static ?string $pollingInterval = '30s';
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SharedStream::query()
                    ->with(['stats' => function ($query) {
                        $query->where('recorded_at', '>=', now()->subHour())
                              ->orderBy('recorded_at', 'desc');
                    }])
                    ->where('status', 'active')
                    ->orderByDesc('client_count')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('stream_id')
                    ->label('Stream ID')
                    ->searchable()
                    ->limit(12)
                    ->tooltip(fn ($record) => $record->stream_id),

                Tables\Columns\TextColumn::make('source_url')
                    ->label('Source')
                    ->searchable()
                    ->limit(40)
                    ->formatStateUsing(fn ($state) => parse_url($state, PHP_URL_HOST) ?: 'Unknown')
                    ->tooltip(fn ($record) => $record->source_url),

                Tables\Columns\BadgeColumn::make('format')
                    ->label('Format')
                    ->colors([
                        'primary' => 'hls',
                        'success' => 'dash',
                        'warning' => 'ts',
                        'danger' => 'mp4',
                    ]),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'active',
                        'warning' => 'starting',
                        'danger' => 'error',
                        'secondary' => 'stopped',
                    ]),

                Tables\Columns\TextColumn::make('client_count')
                    ->label('Clients')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => $state > 10 ? 'success' : ($state > 5 ? 'warning' : 'primary')),

                Tables\Columns\TextColumn::make('bandwidth_kbps')
                    ->label('Bandwidth')
                    ->formatStateUsing(fn ($state) => $this->formatBandwidth($state))
                    ->badge()
                    ->color(fn ($state) => $state > 5000 ? 'danger' : ($state > 2000 ? 'warning' : 'success')),

                Tables\Columns\TextColumn::make('bytes_transferred')
                    ->label('Data Transferred')
                    ->formatStateUsing(fn ($state) => $this->formatBytes($state)),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Uptime')
                    ->formatStateUsing(fn ($state) => $state ? $state->diffForHumans(null, true) : 'N/A')
                    ->sortable(),

                Tables\Columns\IconColumn::make('health_status')
                    ->label('Health')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->getStateUsing(fn ($record) => $record->health_status === 'healthy'),
            ])
            ->defaultSort('client_count', 'desc')
            ->paginated(false);
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

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
