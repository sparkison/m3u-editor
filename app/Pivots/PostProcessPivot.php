<?php

namespace App\Pivots;

use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\Playlist;
use App\Models\PostProcess;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PostProcessPivot extends Pivot
{
    protected $table = 'processables';

    public function postProcess(): BelongsTo
    {
        return $this->belongsTo(PostProcess::class);
    }

    public function type(): string
    {
        switch ($this->processable_type) {
            case Epg::class:
                return 'EPG';
            case CustomPlaylist::class:
                return 'Custom Playlist';
            case MergedPlaylist::class:
                return 'Merged Playlist';
            default:
                return 'Playlist';
        }
    }

    public function model(): BelongsTo
    {
        switch ($this->processable_type) {
            case Epg::class:
                return $this->belongsTo(Epg::class, 'processable_id');
            case CustomPlaylist::class:
                return $this->belongsTo(CustomPlaylist::class, 'processable_id');
            case MergedPlaylist::class:
                return $this->belongsTo(MergedPlaylist::class, 'processable_id');
            default:
                return $this->belongsTo(Playlist::class, 'processable_id');
        }
    }
}
