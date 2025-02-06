<?php

namespace App\Filament\Resources;

use App\Enums\EpgStatus;
use App\Filament\Resources\EpgResource\Pages;
use App\Filament\Resources\EpgResource\RelationManagers;
use App\Models\Epg;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EpgResource extends Resource
{
    protected static ?string $model = Epg::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $label = 'EPG';

    protected static ?string $navigationGroup = 'EPG';

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100, 250])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn(EpgStatus $state) => $state->getColor()),
                Tables\Columns\IconColumn::make('auto_sync')
                    ->label('Auto Sync')
                    ->icon(fn(string $state): string => match ($state) {
                        '1' => 'heroicon-o-check-circle',
                        '0' => 'heroicon-o-minus-circle',
                    })->color(fn(string $state): string => match ($state) {
                        '1' => 'success',
                        '0' => 'danger',
                    })->sortable(),
                Tables\Columns\TextColumn::make('synced')
                    ->label('Last Synced')
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sync_time')
                    ->label('Sync Time')
                    ->formatStateUsing(fn(string $state): string => gmdate('H:i:s', $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('process')
                        ->label('Process')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessEpgImport($record));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('EPG is processing')
                                ->body('EPG is being processed in the background. Depending on the size of the guide data, this may take a while.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn($record): bool => ! $record->auto_sync)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process EPG now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('Download EPG')
                        ->label('Download EPG')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => route('epg.file', ['uuid' => $record->uuid]))
                        ->openUrlInNewTab(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('process')
                        ->label('Process selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new \App\Jobs\ProcessEpgImport($record));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected EPGs are processing')
                                ->body('The selected EPGs are being processed in the background. Depending on the size of the guide data, this may take a while.')
                                ->duration(10000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process the selected epg(s) now?')
                        ->modalSubmitActionLabel('Yes, process now')
                ]),
            ])->checkIfRecordIsSelectableUsing(
                fn($record): bool => $record->status !== EpgStatus::Processing,
            );
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEpgs::route('/'),
            // 'create' => Pages\CreateEpg::route('/create'),
            // 'edit' => Pages\EditEpg::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->columnSpan('full')
                ->required()
                ->helperText('Enter the name of the EPG. Internal use only.')
                ->maxLength(255),
            Forms\Components\Toggle::make('auto_sync')
                ->label('Automatically sync playlist every 24hr')
                ->live()
                ->default(true),
            Forms\Components\DateTimePicker::make('synced')
                ->columnSpan(2)
                ->prefix('Sync 24hr from')
                ->suffix('UTC')
                ->native(false)
                ->label('Last Synced')
                ->hidden(fn(Get $get, string $operation): bool => ! $get('auto_sync') || $operation === 'create')
                ->helperText('EPG will be synced every 24hr. Timestamp is automatically updated after each sync. Set to any time in the past (or future) and the next sync will run when 24hr has passed since the time set.'),

            Forms\Components\Section::make('XMLTV file or URL')
                ->description('You can either upload an XMLTV file or provide a URL to an XMLTV file. File should conform to the XMLTV format.')
                ->headerActions([
                    Forms\Components\Actions\Action::make('XMLTV Format')
                        ->label('XMLTV Format')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->iconPosition('after')
                        ->size('sm')
                        // ->url('https://wiki.xmltv.org/index.php/XMLTVFormat')
                        ->url('https://github.com/XMLTV/xmltv/blob/master/xmltv.dtd')
                        ->openUrlInNewTab(true),
                ])
                ->schema([
                    Forms\Components\TextInput::make('url')
                        ->label('URL')
                        ->requiredIf('uploads', [null, ''])
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->helperText('Enter the URL of the XMLTV guide data. If changing URL, the guide data will be re-imported. Use with caution as this could lead to data loss if the new guide differs from the old one.')
                        ->url()
                        ->maxLength(255),
                    Forms\Components\FileUpload::make('uploads')
                        ->label('File')
                        ->disk('local')
                        ->directory('epg')
                        ->rules(['file'])
                        ->requiredIf('url', [null, ''])
                        ->helperText('Upload the XMLTV file for the EPG. This will be used to import the guide data.'),
                ])->hiddenOn(['create'])
        ];
    }
}
