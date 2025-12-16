<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;

class StrmFileMapping extends Model
{
    protected $fillable = [
        'syncable_type',
        'syncable_id',
        'sync_location',
        'current_path',
        'current_url',
        'path_options',
    ];

    protected $casts = [
        'path_options' => 'array',
    ];

    /**
     * Get the parent syncable model (Channel for VOD, Series Episode, etc.)
     */
    public function syncable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Find or create a mapping for a syncable item
     */
    public static function findForSyncable(Model $syncable, string $syncLocation): ?self
    {
        return self::where('syncable_type', get_class($syncable))
            ->where('syncable_id', $syncable->id)
            ->where('sync_location', $syncLocation)
            ->first();
    }

    /**
     * Sync the .strm file - handles create, rename, and URL updates
     * 
     * @param Model $syncable The model being synced (Channel, Episode, etc.)
     * @param string $syncLocation Base sync location path
     * @param string $expectedPath The expected full path for the .strm file
     * @param string $url The URL to store in the .strm file
     * @param array $pathOptions The options used to generate the path (for tracking changes)
     * @return self The mapping record
     */
    public static function syncFile(
        Model $syncable,
        string $syncLocation,
        string $expectedPath,
        string $url,
        array $pathOptions = []
    ): self {
        $mapping = self::findForSyncable($syncable, $syncLocation);

        // Case 1: No existing mapping - create new file
        if (! $mapping) {
            return self::createNewFile($syncable, $syncLocation, $expectedPath, $url, $pathOptions);
        }

        // Case 2: Path changed - rename the file
        if ($mapping->current_path !== $expectedPath) {
            return self::renameFile($mapping, $expectedPath, $url, $pathOptions);
        }

        // Case 3: URL changed - update the file content
        if ($mapping->current_url !== $url) {
            return self::updateFileUrl($mapping, $url);
        }

        // Case 4: Nothing changed - just return the mapping
        return $mapping;
    }

    /**
     * Create a new .strm file and mapping
     */
    protected static function createNewFile(
        Model $syncable,
        string $syncLocation,
        string $path,
        string $url,
        array $pathOptions
    ): self {
        // Ensure directory exists
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        // Write the file
        file_put_contents($path, $url);

        Log::debug("STRM Sync: Created new file", ['path' => $path]);

        // Create and return the mapping
        return self::create([
            'syncable_type' => get_class($syncable),
            'syncable_id' => $syncable->id,
            'sync_location' => $syncLocation,
            'current_path' => $path,
            'current_url' => $url,
            'path_options' => $pathOptions,
        ]);
    }

    /**
     * Rename an existing .strm file
     */
    protected static function renameFile(
        self $mapping,
        string $newPath,
        string $url,
        array $pathOptions
    ): self {
        $oldPath = $mapping->current_path;
        $oldDirectory = dirname($oldPath);

        // Ensure new directory exists
        $newDirectory = dirname($newPath);
        if (! is_dir($newDirectory)) {
            mkdir($newDirectory, 0777, true);
        }

        // Rename or create the file
        if (file_exists($oldPath)) {
            // Rename the file
            rename($oldPath, $newPath);
            Log::info("STRM Sync: Renamed file", ['from' => $oldPath, 'to' => $newPath]);

            // Update URL if it changed
            if ($mapping->current_url !== $url) {
                file_put_contents($newPath, $url);
            }

            // Clean up empty old directories
            self::cleanupEmptyDirectories($oldDirectory, $mapping->sync_location);
        } else {
            // Old file doesn't exist, create new one
            file_put_contents($newPath, $url);
            Log::debug("STRM Sync: Created file (old file missing)", ['path' => $newPath]);
        }

        // Update the mapping
        $mapping->update([
            'current_path' => $newPath,
            'current_url' => $url,
            'path_options' => $pathOptions,
        ]);

        return $mapping;
    }

    /**
     * Update the URL in an existing .strm file
     */
    protected static function updateFileUrl(self $mapping, string $url): self
    {
        if (file_exists($mapping->current_path)) {
            file_put_contents($mapping->current_path, $url);
            Log::debug("STRM Sync: Updated URL", ['path' => $mapping->current_path]);
        } else {
            // File doesn't exist, create it
            $directory = dirname($mapping->current_path);
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($mapping->current_path, $url);
            Log::debug("STRM Sync: Recreated file with new URL", ['path' => $mapping->current_path]);
        }

        $mapping->update(['current_url' => $url]);

        return $mapping;
    }

    /**
     * Delete the .strm file and its mapping
     */
    public function deleteFile(): void
    {
        if (file_exists($this->current_path)) {
            unlink($this->current_path);
            Log::debug("STRM Sync: Deleted file", ['path' => $this->current_path]);

            // Clean up empty directories
            self::cleanupEmptyDirectories(dirname($this->current_path), $this->sync_location);
        }

        $this->delete();
    }

    /**
     * Clean up empty directories up to the sync location
     */
    protected static function cleanupEmptyDirectories(string $directory, string $syncLocation): void
    {
        $syncLocation = rtrim($syncLocation, '/');
        
        // Don't delete the sync location itself
        while ($directory !== $syncLocation && strlen($directory) > strlen($syncLocation)) {
            if (is_dir($directory) && self::isDirectoryEmpty($directory)) {
                rmdir($directory);
                Log::debug("STRM Sync: Removed empty directory", ['path' => $directory]);
                $directory = dirname($directory);
            } else {
                break;
            }
        }
    }

    /**
     * Check if a directory is empty
     */
    protected static function isDirectoryEmpty(string $directory): bool
    {
        if (! is_dir($directory)) {
            return false;
        }
        
        $files = scandir($directory);
        return count($files) <= 2; // Only . and ..
    }

    /**
     * Delete all mappings and files for a syncable that no longer exist
     */
    public static function cleanupOrphaned(string $syncableType, string $syncLocation): int
    {
        $count = 0;
        
        self::where('syncable_type', $syncableType)
            ->where('sync_location', $syncLocation)
            ->chunk(100, function ($mappings) use (&$count) {
                foreach ($mappings as $mapping) {
                    // Check if the syncable still exists and is enabled
                    $syncable = $mapping->syncable;
                    if (! $syncable || ! ($syncable->enabled ?? true)) {
                        $mapping->deleteFile();
                        $count++;
                    }
                }
            });

        return $count;
    }
}
