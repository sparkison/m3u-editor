<?php

namespace App\Filament\Resources\PostProcessResource\Pages;

use App\Filament\Resources\PostProcessResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListPostProcesses extends ListRecords
{
    protected static string $resource = PostProcessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
            Actions\CreateAction::make()
                ->using(function (array $data, string $model): Model {
                    $data['user_id'] = auth()->id();
                    return $model::create($data);
                })
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title('Post Process created')
                        ->body('You can now assign Playlists or EPGs.'),
                )->successRedirectUrl(fn($record): string => EditPostProcess::getUrl(['record' => $record])),

        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
