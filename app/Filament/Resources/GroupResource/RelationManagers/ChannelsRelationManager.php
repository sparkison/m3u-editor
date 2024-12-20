<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Filament\Resources\ChannelResource;
use App\Filament\Resources\ChannelResource\Pages\ListChannels;
use App\Models\Channel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    public function form(Form $form): Form
    {
        return $form
            ->schema(ChannelResource::getForm());
    }

    public function table(Table $table): Table
    {
        return ChannelResource::table($table);
    }

    public function getTabs(): array
    {
        return ListChannels::tabs($this->ownerRecord->id);
    }
}
