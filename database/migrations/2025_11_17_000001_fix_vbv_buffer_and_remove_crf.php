<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Fixes VBV underflow issues by:
     * 1. Increasing VBV buffer from 2500k to 10000k (4 seconds at 2500k maxrate)
     * 2. Removing CRF mode from profiles that use maxrate (conflicting rate control)
     * 3. Ensuring proper CBR mode configuration
     */
    public function up(): void
    {
        // Fix #1: Update bufsize from 2500k to 10000k (4-second buffer)
        DB::table('stream_profiles')
            ->where('args', 'LIKE', '%-bufsize {bufsize|2500k}%')
            ->update([
                'args' => DB::raw("REPLACE(args, '-bufsize {bufsize|2500k}', '-bufsize {bufsize|10000k}')"),
                'updated_at' => now(),
            ]);

        // Also update any profiles still using 5000k to 10000k
        DB::table('stream_profiles')
            ->where('args', 'LIKE', '%-bufsize {bufsize|5000k}%')
            ->update([
                'args' => DB::raw("REPLACE(args, '-bufsize {bufsize|5000k}', '-bufsize {bufsize|10000k}')"),
                'updated_at' => now(),
            ]);

        // Fix #2: Remove CRF mode from profiles that have both CRF and maxrate
        // This is a more complex replacement that requires careful handling
        $profiles = DB::table('stream_profiles')
            ->where('args', 'LIKE', '%-crf%')
            ->where('args', 'LIKE', '%-maxrate%')
            ->get();

        foreach ($profiles as $profile) {
            $args = $profile->args;
            
            // Replace -crf {crf|XX} with -b:v {bitrate|2000k}
            $args = preg_replace('/-crf\s+\{crf\|[0-9]+\}/', '-b:v {bitrate|2000k}', $args);
            
            // Update audio bitrate from 192k to 128k for consistency
            $args = str_replace('-b:a {audio_bitrate|192k}', '-b:a {audio_bitrate|128k}', $args);
            
            // Remove -hls_segment_filename if present (not needed, FFmpeg auto-generates)
            $args = preg_replace('/-hls_segment_filename\s+\S+\s+/', '', $args);
            
            DB::table('stream_profiles')
                ->where('id', $profile->id)
                ->update([
                    'args' => $args,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     * 
     * Reverts VBV buffer changes (not recommended - will cause VBV underflow).
     */
    public function down(): void
    {
        // Revert bufsize from 10000k to 2500k
        DB::table('stream_profiles')
            ->where('args', 'LIKE', '%-bufsize {bufsize|10000k}%')
            ->update([
                'args' => DB::raw("REPLACE(args, '-bufsize {bufsize|10000k}', '-bufsize {bufsize|2500k}')"),
                'updated_at' => now(),
            ]);

        // Note: We don't revert CRF changes as that would reintroduce the bug
        // Users would need to manually restore CRF mode if desired
    }
};

