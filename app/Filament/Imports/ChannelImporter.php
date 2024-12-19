<?php

namespace App\Filament\Imports;

use App\Models\Channel;
use App\Models\Playlist;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;

class ChannelImporter extends Importer
{
    protected static ?string $model = Channel::class;

    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('playlist')
                ->required()
                ->label('Playlist')
                ->helperText('Select the playlist this import is associated with.')
                ->options(Playlist::all()->pluck('name', 'id'))
                ->searchable()
        ];
    }

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->requiredMapping()
                ->rules(['required', 'max:255']),
            ImportColumn::make('group')
                ->requiredMapping()
                ->rules(['required', 'max:255']),
            ImportColumn::make('channel')
                ->requiredMapping()
                ->rules(['required', 'numeric', 'min:0']),
            ImportColumn::make('enabled')
                ->requiredMapping()
                ->rules(['required', 'numeric', 'min:0', 'max:1'])
        ];
    }

    public function resolveRecord(): ?Channel
    {
        return Channel::firstOrNew([
            'name' => $this->data['name'],
            'group' => $this->data['group'],
            'playlist_id' => $this->options['playlist'],
        ]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your channel import has completed and ' . number_format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }
}