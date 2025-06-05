<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelFailover extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'channel_id', 
        'channel_failover_id',
        'sort',
        'metadata',
        'auto_matched',
        'match_quality',
        'match_type'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'channel_id' => 'integer',
        'channel_failover_id' => 'integer',
        'metadata' => 'array',
        'auto_matched' => 'boolean',
        'match_quality' => 'decimal:4',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function channelFailover(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_failover_id');
    }
}
