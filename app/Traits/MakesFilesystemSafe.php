<?php

namespace App\Traits;

trait MakesFilesystemSafe
{
    /**
     * Make a string safe for filesystem use while preserving Unicode characters like umlauts
     * 
     * @param string $name The original name
     * @return string Filesystem-safe name
     */
    private function makeFilesystemSafe(string $name): string
    {
        // Replace filesystem-unsafe characters but preserve Unicode characters
        $unsafe = ['/', '\\', ':', '*', '?', '"', '<', '>', '|', "\0"];
        $safe = str_replace($unsafe, ' ', $name);
        
        // Remove multiple spaces and trim
        $safe = preg_replace('/\s+/', ' ', trim($safe));
        
        // Remove leading/trailing dots (Windows limitation)
        $safe = trim($safe, '. ');
        
        return $safe ?: 'Unnamed';
    }
}