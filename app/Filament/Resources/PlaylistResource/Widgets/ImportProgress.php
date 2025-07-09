<?php

namespace App\Filament\Resources\PlaylistResource\Widgets;

use App\Enums\Status;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ImportProgress extends Widget
{
    protected static string $view = 'filament.resources.playlist-resource.widgets.import-progress';

    public ?Model $record = null;
    public $isProcessing = false;

    public function getColumnSpan(): int|string|array
    {
        return 'full';
    }

    public function getPollingInterval(): ?string
    {
        return '5s';
    }
    
    public function mount(?Model $record): void
    {
        $isProcessing = ($record?->progress < 100 ?? false)
            && ($record?->status === Status::Processing || $record?->status === Status::Pending);
        $this->record = $record;
        $this->isProcessing = $isProcessing;
        if ($isProcessing) {
            $this->dispatch('pollingStarted', ['record' => $this->record]);
        } else {
            $this->dispatch('pollingStopped', ['record' => $this->record]);
        }
    }

}
