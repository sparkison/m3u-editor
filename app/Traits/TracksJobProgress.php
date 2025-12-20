<?php

namespace App\Traits;

use App\Models\JobProgress;
use Illuminate\Database\Eloquent\Model;

trait TracksJobProgress
{
    /**
     * The job progress instance for this job.
     */
    protected ?JobProgress $jobProgress = null;

    /**
     * Initialize job progress tracking.
     */
    protected function initializeJobProgress(
        string $name,
        ?Model $trackable = null,
        int $totalItems = 0,
        ?string $batchId = null
    ): JobProgress {
        $this->jobProgress = JobProgress::createForJob(
            jobType: static::class,
            name: $name,
            trackable: $trackable,
            totalItems: $totalItems,
            batchId: $batchId,
            userId: $trackable?->user_id ?? auth()->id()
        );

        return $this->jobProgress;
    }

    /**
     * Find or create job progress for a batch operation.
     */
    protected function findOrCreateBatchProgress(
        string $batchId,
        string $name,
        ?Model $trackable = null,
        int $totalItems = 0
    ): JobProgress {
        $this->jobProgress = JobProgress::findOrCreateForBatch(
            batchId: $batchId,
            jobType: static::class,
            name: $name,
            trackable: $trackable,
            totalItems: $totalItems,
            userId: $trackable?->user_id ?? auth()->id()
        );

        return $this->jobProgress;
    }

    /**
     * Start the job progress (mark as running).
     */
    protected function startJobProgress(): void
    {
        $this->jobProgress?->start();
    }

    /**
     * Update the total items count.
     */
    protected function setTotalItems(int $total): void
    {
        $this->jobProgress?->update(['total_items' => $total]);
    }

    /**
     * Update progress with a specific count.
     */
    protected function updateJobProgress(int $processed, ?string $message = null): void
    {
        $this->jobProgress?->progress($processed, $message);
    }

    /**
     * Increment progress by a specific amount.
     */
    protected function incrementJobProgress(int $amount = 1, ?string $message = null): void
    {
        $this->jobProgress?->incrementProgress($amount, $message);
    }

    /**
     * Update the status message.
     */
    protected function setJobProgressMessage(string $message): void
    {
        $this->jobProgress?->update(['message' => $message]);
    }

    /**
     * Mark the job as completed.
     */
    protected function completeJobProgress(?string $message = null): void
    {
        $this->jobProgress?->complete($message);
    }

    /**
     * Mark the job as failed.
     */
    protected function failJobProgress(?string $message = null): void
    {
        $this->jobProgress?->fail($message);
    }

    /**
     * Get the current job progress instance.
     */
    protected function getJobProgress(): ?JobProgress
    {
        return $this->jobProgress;
    }

    /**
     * Check if there's an active job for the given model.
     */
    protected function hasActiveJobFor(Model $model): bool
    {
        return JobProgress::hasActiveJobsFor($model);
    }
}
