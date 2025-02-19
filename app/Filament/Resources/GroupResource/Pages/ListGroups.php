<?php

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Resources\GroupResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListGroups extends ListRecords
{
    protected static string $resource = GroupResource::class;

    protected ?string $subheading = 'Change group names, or add new groups to organize your channels. Only custom groups can be deleted. Head to channels to bulk move channels between groups.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->using(function (array $data, string $model): Model {
                    $data['user_id'] = auth()->id();
                    $data['custom'] = true;
                    return $model::create($data);
                })
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title('Group createdd')
                        ->body('You can now assign channels to this group from the Channels section.'),
                )
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
