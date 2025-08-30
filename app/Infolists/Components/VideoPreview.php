<?php

namespace App\Infolists\Components;

use Filament\Infolists\Components\Entry;

class VideoPreview extends Entry
{
    protected string $view = 'infolists.components.video-preview';

    protected bool $showDetails = true;
    protected bool $autoPlayEnabled = false;

    public function withDetails(bool $withDetails = true): static
    {
        $this->showDetails = $withDetails;
        return $this;
    }

    public function autoPlay(bool $autoPlay = true): static
    {
        $this->autoPlayEnabled = $autoPlay;
        return $this;
    }

    public function isAutoPlay(): bool
    {
        return (bool) $this->evaluate($this->autoPlayEnabled);
    }

    public function isWithDetails(): bool
    {
        return (bool) $this->evaluate($this->showDetails);
    }
}
