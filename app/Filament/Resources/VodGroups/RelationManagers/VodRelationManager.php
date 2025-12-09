<?php

namespace App\Filament\Resources\VodGroups\RelationManagers;

use Filament\Schemas\Schema;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Filament\Resources\Vods\VodResource;
use App\Models\Channel;
use Filament\Forms;
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
    
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(VodResource::getForm());
    }

    public function infolist(Schema $schema): Schema
    {
        return VodResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        $table = $table->reorderRecordsTriggerAction(function ($action) {
            return $action->button()->label('Sort');
        })->defaultSort('sort', 'asc')->reorderable('sort');
        return VodResource::setupTable($table, $this->ownerRecord->id);
    }

    public function getTabs(): array
    {
        return ListVod::setupTabs($this->ownerRecord->id);
    }
}
