<?php

namespace App\Models;

use App\Enums\PlaylistStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Playlist extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'channels' => 'integer',
        'synced' => 'datetime',
        'status' => PlaylistStatus::class,
    ];

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}
