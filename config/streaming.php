<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HLS Stream Monitoring Settings
    |--------------------------------------------------------------------------
    |
    | These settings control the behavior of the background job that monitors
    | the health of active HLS streams.
    |
    */

    /**
     * Monitor Job Interval (Seconds)
     *
     * How frequently the MonitorStreamHealthJob should run for each active stream
     * to perform health checks.
     */
    // 'monitor_job_interval_seconds' => env('MONITOR_JOB_INTERVAL_SECONDS', 10),
    // Note: The stream monitoring interval is now dynamically calculated within
    // App\Jobs\MonitorStreamHealthJob.php. It typically defaults to
    // max(3, floor(ffmpeg_hls_time / 2)), where ffmpeg_hls_time is sourced
    // from the General Settings (ProxyService::getStreamSettings()).
    // This 'monitor_job_interval_seconds' config value is no longer the direct source for the delay.

    /**
     * HLS Segment Age Multiplier
     *
     * This value is multiplied by the stream's HLS segment duration (hls_time)
     * to determine the maximum acceptable age of the latest .ts segment.
     * For example, if hls_time is 4 seconds and multiplier is 3, segments
     * older than 12 seconds will be considered stale.
     */
    'hls_segment_age_multiplier' => env('HLS_SEGMENT_AGE_MULTIPLIER', 3),

    /**
     * HLS Segment Grace Period (Seconds)
     *
     * The time (in seconds) after a stream starts during which the monitor
     * will not fail a stream for having no .ts segments. This allows FFmpeg
     * some time to initialize and produce the first few segments.
     */
    'hls_segment_grace_period_seconds' => env('HLS_SEGMENT_GRACE_PERIOD_SECONDS', 20),

    /**
     * Minimum Monitor Job Interval (Seconds)
     *
     * The absolute minimum interval (in seconds) for the MonitorStreamHealthJob.
     * The dynamic calculation (half of ffmpeg_hls_time) will not go below this value.
     */
    'min_monitor_job_interval_seconds' => env('MIN_MONITOR_JOB_INTERVAL_SECONDS', 3),

    /**
     * Monitor Job Retries
     *
     * The number of times a MonitorStreamHealthJob instance will be retried by
     * the queue worker if it fails due to an unexpected exception before being
     * marked as permanently failed.
     */
    'monitor_job_tries' => env('MONITOR_JOB_TRIES', 3),

    /**
     * Monitor Job Backoff (Seconds)
     *
     * The delay (in seconds) between retry attempts for a failed MonitorStreamHealthJob.
     * If you provide an array, it will use them in sequence for subsequent retries.
     * The value can be a comma-separated string in the .env file.
     * Example: MONITOR_JOB_BACKOFF_CSV="60,120,300"
     */
    'monitor_job_backoff' => env('MONITOR_JOB_BACKOFF_CSV')
                             ? array_map('intval', explode(',', env('MONITOR_JOB_BACKOFF_CSV')))
                             : [60, 120, 300], // Default if CSV not set

];
