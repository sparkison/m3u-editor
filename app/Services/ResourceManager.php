<?php

namespace App\Services;

use App\Settings\GeneralSettings;

/**
 * Manages resource allocation for sync operations based on system capabilities.
 * Provides automatic hardware detection and configurable performance profiles.
 */
class ResourceManager
{
    /**
     * Performance profiles with chunk sizes and concurrency settings.
     * These are tuned for different hardware capabilities.
     */
    public const PROFILES = [
        'low' => [
            'label' => 'Low (1-2 GB RAM)',
            'metadata_chunk_size' => 5,
            'strm_sync_chunk_size' => 10,
            'cleanup_chunk_size' => 250,
            'memory_limit_mb' => 256,
        ],
        'medium' => [
            'label' => 'Medium (2-4 GB RAM)',
            'metadata_chunk_size' => 15,
            'strm_sync_chunk_size' => 35,
            'cleanup_chunk_size' => 500,
            'memory_limit_mb' => 512,
        ],
        'high' => [
            'label' => 'High (4-8 GB RAM)',
            'metadata_chunk_size' => 30,
            'strm_sync_chunk_size' => 75,
            'cleanup_chunk_size' => 1000,
            'memory_limit_mb' => 1024,
        ],
        'ultra' => [
            'label' => 'Ultra (8+ GB RAM)',
            'metadata_chunk_size' => 50,
            'strm_sync_chunk_size' => 100,
            'cleanup_chunk_size' => 2000,
            'memory_limit_mb' => 2048,
        ],
    ];

    protected ?string $detectedProfile = null;

    protected ?int $availableMemoryMb = null;

    protected ?int $cpuCores = null;

    /**
     * Get the current performance profile based on settings or auto-detection.
     */
    public function getProfile(): array
    {
        $settings = app(GeneralSettings::class);
        $profileName = $settings->sync_performance_profile ?? 'auto';

        if ($profileName === 'auto') {
            $profileName = $this->detectOptimalProfile();
        }

        return self::PROFILES[$profileName] ?? self::PROFILES['medium'];
    }

    /**
     * Get the profile name (for display purposes).
     */
    public function getProfileName(): string
    {
        $settings = app(GeneralSettings::class);
        $profileName = $settings->sync_performance_profile ?? 'auto';

        if ($profileName === 'auto') {
            return 'auto ('.$this->detectOptimalProfile().')';
        }

        return $profileName;
    }

    /**
     * Get metadata fetch chunk size.
     */
    public function getMetadataChunkSize(): int
    {
        $settings = app(GeneralSettings::class);

        // If custom chunk size is set, use it
        if (($settings->sync_custom_metadata_chunk_size ?? 0) > 0) {
            return $settings->sync_custom_metadata_chunk_size;
        }

        return $this->getProfile()['metadata_chunk_size'];
    }

    /**
     * Get STRM file sync chunk size.
     */
    public function getStrmSyncChunkSize(): int
    {
        $settings = app(GeneralSettings::class);

        // If custom chunk size is set, use it
        if (($settings->sync_custom_strm_chunk_size ?? 0) > 0) {
            return $settings->sync_custom_strm_chunk_size;
        }

        return $this->getProfile()['strm_sync_chunk_size'];
    }

    /**
     * Get cleanup operation chunk size.
     */
    public function getCleanupChunkSize(): int
    {
        $settings = app(GeneralSettings::class);

        // If custom chunk size is set, use it
        if (($settings->sync_custom_cleanup_chunk_size ?? 0) > 0) {
            return $settings->sync_custom_cleanup_chunk_size;
        }

        return $this->getProfile()['cleanup_chunk_size'];
    }

    /**
     * Detect the optimal performance profile based on system resources.
     */
    public function detectOptimalProfile(): string
    {
        if ($this->detectedProfile !== null) {
            return $this->detectedProfile;
        }

        $memoryMb = $this->getAvailableMemoryMb();
        $cores = $this->getCpuCores();

        // Determine profile based on available memory
        // We're conservative because we share resources with other processes
        if ($memoryMb >= 6000 && $cores >= 4) {
            $this->detectedProfile = 'ultra';
        } elseif ($memoryMb >= 3000 && $cores >= 2) {
            $this->detectedProfile = 'high';
        } elseif ($memoryMb >= 1500) {
            $this->detectedProfile = 'medium';
        } else {
            $this->detectedProfile = 'low';
        }

        return $this->detectedProfile;
    }

    /**
     * Get available system memory in MB.
     * Considers Docker memory limits if running in a container.
     */
    public function getAvailableMemoryMb(): int
    {
        if ($this->availableMemoryMb !== null) {
            return $this->availableMemoryMb;
        }

        $memoryMb = 2048; // Default fallback
        $dockerLimit = 0;
        $systemMemory = 0;

        // Try to detect Docker memory limit
        $dockerLimit = $this->getDockerMemoryLimitMb();

        // Always try to read system memory on Linux
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo && preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                $systemMemory = (int) ($matches[1] / 1024);
            } elseif ($meminfo && preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                // Fallback to total memory if available not found
                $systemMemory = (int) ($matches[1] / 1024 * 0.7); // Assume 70% available
            }
        }

        // Use the higher of Docker limit or system memory
        // Docker limit of 0 means no limit set, so use system memory
        // If Docker limit is set but system memory is higher, still use system memory
        // as it represents what's actually available
        if ($dockerLimit > 0 && $systemMemory > 0) {
            // Use the minimum of Docker limit and system memory (can't use more than Docker allows)
            $memoryMb = min($dockerLimit, $systemMemory);
        } elseif ($systemMemory > 0) {
            $memoryMb = $systemMemory;
        } elseif ($dockerLimit > 0) {
            $memoryMb = $dockerLimit;
        }

        // PHP memory limit applies per-request, not to the overall sync capability
        // So we don't let it override the system memory detection

        $this->availableMemoryMb = max(512, $memoryMb); // Minimum 512MB

        return $this->availableMemoryMb;
    }

    /**
     * Get Docker memory limit if running in a container.
     * Returns 0 if no meaningful limit is set.
     */
    protected function getDockerMemoryLimitMb(): int
    {
        // cgroups v2
        $cgroupFile = '/sys/fs/cgroup/memory.max';
        if (is_readable($cgroupFile)) {
            $limit = @file_get_contents($cgroupFile);
            $limit = trim($limit);

            // "max" means no limit
            if ($limit === 'max') {
                return 0;
            }

            if (is_numeric($limit)) {
                $limitMb = (int) ($limit / 1024 / 1024);
                // Very high values (>256GB) typically mean no real limit
                if ($limitMb > 256000) {
                    return 0;
                }

                return $limitMb;
            }
        }

        // cgroups v1
        $cgroupFile = '/sys/fs/cgroup/memory/memory.limit_in_bytes';
        if (is_readable($cgroupFile)) {
            $limit = @file_get_contents($cgroupFile);
            if ($limit && is_numeric(trim($limit))) {
                $limitMb = (int) (trim($limit) / 1024 / 1024);
                // Very high values (>256GB) typically mean no real limit set
                // The default is often 9223372036854771712 bytes (max int64)
                if ($limitMb > 256000) {
                    return 0;
                }

                return $limitMb;
            }
        }

        return 0;
    }

    /**
     * Get PHP memory limit in MB.
     */
    protected function getPhpMemoryLimitMb(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return 0; // Unlimited
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        return match ($unit) {
            'g' => $value * 1024,
            'm' => $value,
            'k' => (int) ($value / 1024),
            default => (int) ($value / 1024 / 1024),
        };
    }

    /**
     * Get number of CPU cores available.
     */
    public function getCpuCores(): int
    {
        if ($this->cpuCores !== null) {
            return $this->cpuCores;
        }

        $cores = 2; // Default fallback

        // Try Docker CPU quota first
        $dockerCores = $this->getDockerCpuCores();
        if ($dockerCores > 0) {
            $cores = $dockerCores;
        } elseif (PHP_OS_FAMILY === 'Linux') {
            // Use nproc on Linux
            $nproc = @shell_exec('nproc 2>/dev/null');
            if ($nproc && is_numeric(trim($nproc))) {
                $cores = (int) trim($nproc);
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            // macOS
            $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($sysctl && is_numeric(trim($sysctl))) {
                $cores = (int) trim($sysctl);
            }
        }

        $this->cpuCores = max(1, $cores);

        return $this->cpuCores;
    }

    /**
     * Get Docker CPU cores limit if set.
     */
    protected function getDockerCpuCores(): int
    {
        // cgroups v2
        $cgroupFile = '/sys/fs/cgroup/cpu.max';
        if (is_readable($cgroupFile)) {
            $content = @file_get_contents($cgroupFile);
            if ($content && preg_match('/(\d+)\s+(\d+)/', $content, $matches)) {
                $quota = (int) $matches[1];
                $period = (int) $matches[2];
                if ($period > 0 && $quota > 0) {
                    return (int) ceil($quota / $period);
                }
            }
        }

        // cgroups v1
        $quotaFile = '/sys/fs/cgroup/cpu/cpu.cfs_quota_us';
        $periodFile = '/sys/fs/cgroup/cpu/cpu.cfs_period_us';
        if (is_readable($quotaFile) && is_readable($periodFile)) {
            $quota = (int) @file_get_contents($quotaFile);
            $period = (int) @file_get_contents($periodFile);
            if ($period > 0 && $quota > 0) {
                return (int) ceil($quota / $period);
            }
        }

        return 0;
    }

    /**
     * Get system info for display.
     */
    public function getSystemInfo(): array
    {
        return [
            'available_memory_mb' => $this->getAvailableMemoryMb(),
            'cpu_cores' => $this->getCpuCores(),
            'detected_profile' => $this->detectOptimalProfile(),
            'current_profile' => $this->getProfileName(),
            'metadata_chunk_size' => $this->getMetadataChunkSize(),
            'strm_sync_chunk_size' => $this->getStrmSyncChunkSize(),
            'cleanup_chunk_size' => $this->getCleanupChunkSize(),
        ];
    }
}
