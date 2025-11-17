<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidateFfmpegVariables implements ValidationRule
{
    /**
     * Known FFmpeg variables that m3u-proxy can substitute.
     * These are the only variables that will be processed by m3u-proxy.
     */
    private const KNOWN_VARIABLES = [
        'input_url',      // Source stream URL (required)
        'output_args',    // Output arguments (required)
        'bitrate',        // Video bitrate (e.g., 2000k)
        'maxrate',        // Maximum bitrate for VBV (e.g., 2500k)
        'bufsize',        // VBV buffer size (e.g., 10000k)
        'audio_bitrate',  // Audio bitrate (e.g., 128k)
        'crf',            // Constant Rate Factor (not recommended with maxrate)
        'preset',         // Encoding preset (e.g., medium, fast)
        'width',          // Video width for scaling
        'height',         // Video height for scaling
        'fps',            // Frame rate
    ];

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Find all variables in the format {variable_name} or {variable_name|default}
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\|?[^}]*\}/', $value, $matches);
        
        if (empty($matches[1])) {
            // No variables found - this is fine
            return;
        }

        $foundVariables = $matches[1];
        $unknownVariables = [];

        foreach ($foundVariables as $variable) {
            if (!in_array($variable, self::KNOWN_VARIABLES)) {
                $unknownVariables[] = $variable;
            }
        }

        if (!empty($unknownVariables)) {
            $unknownList = implode(', ', array_map(fn($v) => "{{$v}}", $unknownVariables));
            $knownList = implode(', ', array_map(fn($v) => "{{$v}}", self::KNOWN_VARIABLES));
            
            $fail("Unknown FFmpeg variable(s) detected: {$unknownList}. These variables will not be substituted by m3u-proxy and may cause transcoding failures. Known variables: {$knownList}");
        }
    }

    /**
     * Get list of known variables for display purposes.
     */
    public static function getKnownVariables(): array
    {
        return self::KNOWN_VARIABLES;
    }
}

