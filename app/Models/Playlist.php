<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Filament\Forms;

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
    ];

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required(),
            Forms\Components\TextInput::make('url')
                ->hiddenOn(['edit'])
                ->required()
            // Forms\Components\,
        ];
    }
}
