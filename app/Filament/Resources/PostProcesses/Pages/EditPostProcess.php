<?php

namespace App\Filament\Resources\PostProcesses\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\PostProcesses\PostProcessResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPostProcess extends EditRecord
{
    protected static string $resource = PostProcessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave()
    {
        // $this->dispatch('refreshRelation');
    }
}
