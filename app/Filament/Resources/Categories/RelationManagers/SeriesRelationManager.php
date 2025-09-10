<?php

namespace App\Filament\Resources\Categories\RelationManagers;

use Filament\Schemas\Schema;
use App\Filament\Resources\Series\SeriesResource;
use App\Filament\Resources\Series\Pages\ListSeries;
use Filament\Forms;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Hydrat\TableLayoutToggle\Concerns\HasToggleableTable;

class SeriesRelationManager extends RelationManager
{
    // use HasToggleableTable;

    protected static string $relationship = 'series';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(SeriesResource::getForm());
    }

    public function table(Table $table): Table
    {
        $table = $table->reorderRecordsTriggerAction(function ($action) {
            return $action->button()->label('Sort');
        })->defaultSort('sort', 'asc')->reorderable('sort');
        return SeriesResource::setupTable($table, $this->ownerRecord->id);
    }
}
