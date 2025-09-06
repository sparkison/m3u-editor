<?php

namespace App\Filament\Resources\CustomPlaylistResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static ?string $label = 'Group';
    protected static ?string $pluralLabel = 'Groups';

    protected static ?string $title = 'Groups';
    protected static ?string $navigationLabel = 'Groups';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name.en')
                    ->label('Name')
                    ->required()
                    ->columnSpanFull()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        $ownerRecord = $this->ownerRecord;
        return $table
            ->recordTitleAttribute('name')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->modifyQueryUsing(function (Builder $query) use ($ownerRecord) {
                $query->where('type', $ownerRecord->uuid);
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextInputColumn::make('name')
                    ->sortable(),
            ])
            ->reorderable('order_column')
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('view_channels')
                    ->hiddenLabel()
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn($record) => 'Channels in "' . $record->name . '"')
                    ->modalContent(function ($record) {
                        $sort = request('sort');
                        $direction = request('direction', 'asc');
                        $allowedSorts = ['title', 'stream_id', 'enabled'];
                        if (in_array($sort, $allowedSorts)) {
                            $orderBy = $sort;
                            $orderDirection = $direction === 'desc' ? 'desc' : 'asc';
                        } else {
                            $orderBy = 'sort';
                            $orderDirection = 'asc';
                        }
                        $channels = \App\Models\Channel::withAnyTags([$record])
                            ->where('is_vod', false)
                            ->orderBy($orderBy, $orderDirection)
                            ->get();
                        if ($channels->isEmpty()) {
                            return view('filament.custom-playlist.no-channels');
                        }
                        return view('filament.custom-playlist.group-channels-list', [
                            'channels' => $channels,
                        ]);
                    }),
                Tables\Actions\DeleteAction::make()->button()->hiddenLabel()], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->using(function (array $data, string $model) use ($ownerRecord): Model {
                        $data['type'] = $ownerRecord->uuid;
                        $tag = $model::create($data);
                        $ownerRecord->attachTag($tag);
                        return $tag;
                    })
                    ->modalWidth('md')
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title('Group created')
                            ->body('You can now assign channels to this group from the Channels tab.'),
                    ),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
