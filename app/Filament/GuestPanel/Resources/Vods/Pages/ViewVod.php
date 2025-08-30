<?php

namespace App\Filament\GuestPanel\Resources\Vods\Pages;

use App\Facades\PlaylistFacade;
use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\Vods\VodResource;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas;
use Filament\Schemas\Components\Section;
use Illuminate\Contracts\Support\Htmlable;

class ViewVod extends ViewRecord
{
    use HasPlaylist;

    protected static string $resource = VodResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back to VOD')
                ->url(VodResource::getUrl('index'))
                ->icon('heroicon-s-arrow-left')
                ->color('gray')
                ->size('sm'),
        ];
    }

    public function infolist(Schemas\Schema $schema): Schemas\Schema
    {
        return $schema
            ->components([
                // 
            ]);
    }
}
