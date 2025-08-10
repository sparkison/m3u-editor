<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Filament\Resources\VodResource;
use App\Models\Channel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VodRelationManager extends RelationManager
{
    protected static string $relationship = 'vod_channels';

    protected static ?string $label = 'VOD Channels';
    protected static ?string $pluralLabel = 'VOD Channels';

    protected static ?string $title = 'VOD Channels';
    protected static ?string $navigationLabel = 'VOD Channels';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function isReadOnly(): bool
    {
        return false;
    }
    
    public function form(Form $form): Form
    {
        return $form
            ->schema(VodResource::getForm());
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return VodResource::infolist($infolist);
    }

    public function table(Table $table): Table
    {
        return VodResource::setupTable($table, $this->ownerRecord->id);
    }

    public function getTabs(): array
    {
        return \App\Filament\Resources\VodResource\Pages\ListVod::setupTabs($this->ownerRecord->id);
    }
}
