<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

use App\Models\Channel;

class ChannelFailover extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'channel_id' => 'integer',
        'channel_failover_id' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (ChannelFailover $failover) {
            if ($failover->channel_id === $failover->channel_failover_id) {
                throw ValidationException::withMessages([
                    'channel_failover_id' => 'A channel cannot failover to itself.',
                ]);
            }

            $source = Channel::with('playlist')->find($failover->channel_id);

            $skipTargetCheck = (bool) ($failover->external ?? false);
            unset($failover->external);

            if ($source?->playlist?->parent_id === null && ! $skipTargetCheck) {
                $target = Channel::with('playlist')->find($failover->channel_failover_id);

                if (! $target || $target->playlist?->parent_id !== null) {
                    throw ValidationException::withMessages([
                        'channel_failover_id' => 'Failover channel must belong to a parent playlist.',
                    ]);
                }
            }

            $duplicate = static::where('channel_id', $failover->channel_id)
                ->where('channel_failover_id', $failover->channel_failover_id)
                ->when($failover->exists, fn ($q) => $q->where('id', '!=', $failover->id))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'channel_failover_id' => 'This failover already exists.',
                ]);
            }
        });
    }

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
