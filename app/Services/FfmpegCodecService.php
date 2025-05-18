<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class FfmpegCodecService
{
    public function getEncoders(): array
    {
        return Cache::remember('ffmpeg_encoders', 3600, function () {

            $ffmpegPath = getenv('FFMPEG_PATH') ?: 'ffmpeg';

            $process = new Process([$ffmpegPath, '-hide_banner', '-encoders']);

            try {
                $process->mustRun();
            } catch (ProcessFailedException $e) {
                Log::error('FFmpeg encoders command failed: ' . $e->getMessage());
                return [];
            }

            $output = explode("\n", $process->getOutput());

            if (empty($output)) {
                Log::error('FFmpeg encoders command returned no output.');
                return [];
            } else {
                Log::info('FFmpeg encoders command executed successfully.');
                Log::debug('FFmpeg encoders output: ', $output);
            }

            $videoCodecs = [];
            $audioCodecs = [];
            $subtitleCodecs = [];

            foreach ($output as $line) {
                if (preg_match('/ ([AVS])([\.FXBSD]{5,6}) ([^=]\S+)\s+(.*)$/', $line, $matches)) {
                    [$_, $type, $flags, $codec, $description] = $matches;
                    if ($flags[2] === 'X') {
                        continue; // Skip experimental encoders
                    }

                    match ($type) {
                        'V' => $videoCodecs[$codec] = "<strong>$codec</strong></br><small><em>$description</em></small>",
                        'A' => $audioCodecs[$codec] = "<strong>$codec</strong></br><small><em>$description</em></small>",
                        'S' => $subtitleCodecs[$codec] = "<strong>$codec</strong></br><small><em>$description</em></small>",
                    };
                }
            }

            asort($videoCodecs);
            asort($audioCodecs);
            asort($subtitleCodecs);

            return [
                'video' => $videoCodecs,
                'audio' => $audioCodecs,
                'subtitle' => $subtitleCodecs,
            ];
        });
    }
}
