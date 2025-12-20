<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JobProgress extends Model
{
    use HasFactory;

    protected $table = 'job_progress';

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'total_items' => 'integer',
        'processed_items' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'job_type',
        'batch_id',
        'trackable_type',
        'trackable_id',
        'name',
        'total_items',
        'processed_items',
        'status',
        'message',
        'started_at',
        'completed_at',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Get the user that owns this job progress.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the trackable model (Playlist, Epg, etc).
     */
    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Calculate progress percentage.
     */
    public function getProgressPercentAttribute(): float
    {
        if ($this->total_items === 0) {
            return $this->status === self::STATUS_COMPLETED ? 100 : 0;
        }

        return round(($this->processed_items / $this->total_items) * 100, 2);
    }

    /**
     * Check if job is active (pending or running).
     */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING]);
    }

    /**
     * Check if job is finished (completed, failed, or cancelled).
     */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED]);
    }

    /**
     * Scope for active jobs.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING]);
    }

    /**
     * Scope for finished jobs.
     */
    public function scopeFinished($query)
    {
        return $query->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED]);
    }

    /**
     * Scope for a specific trackable model.
     */
    public function scopeForTrackable($query, Model $model)
    {
        return $query->where('trackable_type', get_class($model))
            ->where('trackable_id', $model->getKey());
    }

    /**
     * Start the job (set status to running).
     */
    public function start(): self
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        return $this;
    }

    /**
     * Update progress.
     */
    public function progress(int $processed, ?string $message = null): self
    {
        $data = ['processed_items' => $processed];

        if ($message !== null) {
            $data['message'] = $message;
        }

        $this->update($data);

        return $this;
    }

    /**
     * Increment progress by a given amount.
     */
    public function incrementProgress(int $amount = 1, ?string $message = null): self
    {
        $this->increment('processed_items', $amount);

        if ($message !== null) {
            $this->update(['message' => $message]);
        }

        return $this;
    }

    /**
     * Mark job as completed.
     */
    public function complete(?string $message = null): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'message' => $message ?? $this->message,
            'processed_items' => $this->total_items > 0 ? $this->total_items : $this->processed_items,
        ]);

        return $this;
    }

    /**
     * Mark job as failed.
     */
    public function fail(?string $message = null): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
            'message' => $message ?? $this->message,
        ]);

        return $this;
    }

    /**
     * Mark job as cancelled.
     */
    public function cancel(?string $message = null): self
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'completed_at' => now(),
            'message' => $message ?? 'Job was cancelled',
        ]);

        return $this;
    }

    /**
     * Create a new job progress record.
     */
    public static function createForJob(
        string $jobType,
        string $name,
        ?Model $trackable = null,
        ?int $totalItems = 0,
        ?string $batchId = null,
        ?int $userId = null
    ): self {
        return self::create([
            'user_id' => $userId ?? auth()->id(),
            'job_type' => $jobType,
            'batch_id' => $batchId,
            'trackable_type' => $trackable ? get_class($trackable) : null,
            'trackable_id' => $trackable?->getKey(),
            'name' => $name,
            'total_items' => $totalItems,
            'processed_items' => 0,
            'status' => self::STATUS_PENDING,
        ]);
    }

    /**
     * Find or create a job progress for a batch.
     */
    public static function findOrCreateForBatch(
        string $batchId,
        string $jobType,
        string $name,
        ?Model $trackable = null,
        ?int $totalItems = 0,
        ?int $userId = null
    ): self {
        return self::firstOrCreate(
            ['batch_id' => $batchId],
            [
                'user_id' => $userId ?? auth()->id(),
                'job_type' => $jobType,
                'trackable_type' => $trackable ? get_class($trackable) : null,
                'trackable_id' => $trackable?->getKey(),
                'name' => $name,
                'total_items' => $totalItems,
                'processed_items' => 0,
                'status' => self::STATUS_PENDING,
            ]
        );
    }

    /**
     * Get count of active jobs for a user.
     */
    public static function activeCountForUser(?int $userId = null): int
    {
        $query = self::active();

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->count();
    }

    /**
     * Check if there are active jobs for a specific trackable.
     */
    public static function hasActiveJobsFor(Model $model): bool
    {
        return self::forTrackable($model)->active()->exists();
    }
}
