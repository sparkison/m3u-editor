<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidateVbvParameters implements ValidationRule
{
    /**
     * Run the validation rule.
     * 
     * Validates VBV (Video Buffering Verifier) parameters to prevent underflow errors.
     * 
     * Critical errors (blocking):
     * - bitrate > maxrate
     * - bufsize < maxrate (less than 1 second buffering)
     * - CRF mode with maxrate (conflicting rate control)
     * 
     * Warnings (non-blocking):
     * - bufsize < 4× maxrate (less than 4 seconds buffering)
     * - Aggressive presets (ultrafast, superfast, veryfast)
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $errors = [];
        $warnings = [];

        // Extract parameter values from FFmpeg arguments
        $params = $this->extractParameters($value);

        // Critical Error #1: Check if both CRF and maxrate are present
        if ($params['has_crf'] && $params['has_maxrate']) {
            $errors[] = "Conflicting rate control modes: Cannot use both -crf and -maxrate together. Remove -crf for proper CBR encoding, or remove -maxrate for CRF (VBR) mode.";
        }

        // Only validate VBV parameters if maxrate is present (CBR mode)
        if ($params['has_maxrate']) {
            $bitrate = $params['bitrate'];
            $maxrate = $params['maxrate'];
            $bufsize = $params['bufsize'];

            // Critical Error #2: bitrate > maxrate
            if ($bitrate !== null && $maxrate !== null && $bitrate > $maxrate) {
                $errors[] = "Invalid bitrate configuration: bitrate ({$bitrate}k) exceeds maxrate ({$maxrate}k). Bitrate must be ≤ maxrate.";
            }

            // Critical Error #3: bufsize < maxrate (less than 1 second)
            if ($bufsize !== null && $maxrate !== null && $bufsize < $maxrate) {
                $errors[] = "Insufficient VBV buffer: bufsize ({$bufsize}k) is less than maxrate ({$maxrate}k). This provides less than 1 second of buffering and will cause VBV underflow errors. Minimum recommended: " . ($maxrate * 4) . "k (4 seconds).";
            }

            // Warning #1: bufsize < 4× maxrate (suboptimal buffering)
            if ($bufsize !== null && $maxrate !== null && $bufsize >= $maxrate && $bufsize < ($maxrate * 4)) {
                $bufferSeconds = round($bufsize / $maxrate, 1);
                $warnings[] = "Suboptimal VBV buffer: bufsize ({$bufsize}k) provides only {$bufferSeconds} seconds of buffering. Recommended: " . ($maxrate * 4) . "k (4 seconds) for optimal stability across varying network conditions.";
            }
        }

        // Warning #2: Aggressive presets
        if (preg_match('/-preset\s+(ultrafast|superfast|veryfast)/', $value, $presetMatch)) {
            $preset = $presetMatch[1];
            $warnings[] = "Aggressive encoding preset detected: '-preset {$preset}' prioritizes speed over bitrate consistency and may cause VBV underflow. Recommended presets for live streaming: medium, fast, or slow.";
        }

        // If there are critical errors, fail validation
        if (!empty($errors)) {
            $fail(implode(' ', $errors));
        }

        // Warnings are logged but don't block saving
        // They will be displayed in the UI via helper text
    }

    /**
     * Extract FFmpeg parameters from the arguments string.
     */
    private function extractParameters(string $args): array
    {
        $params = [
            'bitrate' => null,
            'maxrate' => null,
            'bufsize' => null,
            'has_crf' => false,
            'has_maxrate' => false,
        ];

        // Extract bitrate (e.g., -b:v 2000k or -b:v {bitrate|2000k})
        if (preg_match('/-b:v\s+(?:\{bitrate\|)?(\d+)k/', $args, $match)) {
            $params['bitrate'] = (int) $match[1];
        }

        // Extract maxrate (e.g., -maxrate 2500k or -maxrate {maxrate|2500k})
        if (preg_match('/-maxrate\s+(?:\{maxrate\|)?(\d+)k/', $args, $match)) {
            $params['maxrate'] = (int) $match[1];
            $params['has_maxrate'] = true;
        }

        // Extract bufsize (e.g., -bufsize 10000k or -bufsize {bufsize|10000k})
        if (preg_match('/-bufsize\s+(?:\{bufsize\|)?(\d+)k/', $args, $match)) {
            $params['bufsize'] = (int) $match[1];
        }

        // Check for CRF mode
        if (preg_match('/-crf\s+/', $args)) {
            $params['has_crf'] = true;
        }

        return $params;
    }

    /**
     * Get validation warnings (non-blocking issues).
     * This is called separately to display warnings in the UI.
     */
    public static function getWarnings(string $args): array
    {
        $warnings = [];
        $validator = new self();
        $params = $validator->extractParameters($args);

        // Check for suboptimal buffer size
        if ($params['has_maxrate'] && $params['bufsize'] !== null && $params['maxrate'] !== null) {
            if ($params['bufsize'] >= $params['maxrate'] && $params['bufsize'] < ($params['maxrate'] * 4)) {
                $bufferSeconds = round($params['bufsize'] / $params['maxrate'], 1);
                $warnings[] = "⚠️ Suboptimal VBV buffer: {$bufferSeconds} seconds. Recommended: 4 seconds (" . ($params['maxrate'] * 4) . "k).";
            }
        }

        // Check for aggressive presets
        if (preg_match('/-preset\s+(ultrafast|superfast|veryfast)/', $args, $match)) {
            $warnings[] = "⚠️ Aggressive preset '{$match[1]}' may cause VBV underflow. Recommended: medium, fast, or slow.";
        }

        return $warnings;
    }
}

