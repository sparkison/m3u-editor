<?php

namespace App\Services;

class FfmpegCodecService
{
    public static function getVideoCodecs(?string $accelerationMethod): array
    {
        $codecs = ['' => 'Default (Copy Original)'];

        switch ($accelerationMethod) {
            case 'qsv':
                $qsvCodecs = [
                    'h264_qsv' => 'H.264 (QSV)',
                    'hevc_qsv' => 'HEVC (QSV)',
                    'mjpeg_qsv' => 'MJPEG (QSV)',
                    'mpeg2_qsv' => 'MPEG-2 (QSV)',
                    'vc1_qsv'   => 'VC-1 (QSV)',
                    'vp9_qsv'   => 'VP9 (QSV)',
                    'av1_qsv'   => 'AV1 (QSV)', // Added as per log example
                ];
                $codecs = array_merge($codecs, $qsvCodecs);
                break;

            case 'vaapi':
                $vaapiCodecs = [
                    'h264_vaapi' => 'H.264 (VAAPI)',
                    'hevc_vaapi' => 'HEVC (VAAPI)',
                    'mjpeg_vaapi' => 'MJPEG (VAAPI)',
                    'mpeg2_vaapi' => 'MPEG-2 (VAAPI)',
                    'vp8_vaapi' => 'VP8 (VAAPI)',
                    'vp9_vaapi' => 'VP9 (VAAPI)',
                    'av1_vaapi' => 'AV1 (VAAPI)',
                ];
                $codecs = array_merge($codecs, $vaapiCodecs);
                break;

            case 'none':
            default:
                $softwareCodecs = [
                    'libx264' => 'H.264 (libx264)',
                    'libx265' => 'H.265 (libx265)',
                    'mpeg4' => 'MPEG-4 Part 2 (mpeg4)',
                    'libvpx-vp9' => 'VP9 (libvpx-vp9)',
                    'libaom-av1' => 'AV1 (libaom-av1)',
                    // Common software codecs based on typical FFmpeg builds
                    'vp8' => 'VP8 (libvpx)', // Often available
                    'theora' => 'Theora (libtheora)', // Often available
                    'dnxhd' => 'DNxHD (dnxhd)',
                    'prores' => 'ProRes (prores_ks / prores_aw)', // prores_ks is common, prores_aw for Apple
                    'mjpeg' => 'MJPEG (libjpeg)',
                    'huffyuv' => 'Huffyuv',
                    'ffv1' => 'FFV1 (Lossless)',
                ];
                $codecs = array_merge($codecs, $softwareCodecs);
                break;
        }
        return $codecs;
    }

    public static function getAudioCodecs(?string $accelerationMethod = null): array
    {
        // Audio codecs are generally not hardware accelerated in the same way,
        // so the list can be the same regardless of $accelerationMethod for now.
        return [
            '' => 'Default (Copy Original)',
            'aac' => 'AAC (Advanced Audio Coding)',
            'libmp3lame' => 'MP3 (LAME)',
            'ac3' => 'AC3 (Dolby Digital)',
            'opus' => 'Opus (libopus)',
            'flac' => 'FLAC (Free Lossless Audio Codec)',
            'vorbis' => 'Vorbis (libvorbis)',
            'pcm_s16le' => 'PCM 16-bit Signed LE',
            'pcm_s24le' => 'PCM 24-bit Signed LE',
            'alac' => 'ALAC (Apple Lossless Audio Codec)',
        ];
    }

    public static function getSubtitleCodecs(?string $accelerationMethod = null): array
    {
        // Subtitle codecs are not typically hardware accelerated.
        return [
            '' => 'Default (Copy Original)',
            'srt' => 'SRT (SubRip Text)', // More descriptive
            'ass' => 'ASS (Advanced SubStation Alpha)',
            'ssa' => 'SSA (SubStation Alpha)', // Often same as ASS but good to list
            'subrip' => 'SubRip (subrip internal)', // FFmpeg's internal srt encoder/decoder
            'webvtt' => 'WebVTT (Web Video Text Tracks)',
            'mov_text' => 'MOV Text (QuickTime Timed Text)',
            'dvbsub' => 'DVB Subtitles',
            'pgssub' => 'PGS Subtitles (Blu-ray/HD DVD)', // More descriptive
        ];
    }

    public static function isHardwareVideoCodec(?string $codec): bool
    {
        if (empty($codec)) {
            return false;
        }
        return str_contains($codec, '_qsv') || str_contains($codec, '_vaapi');
    }
}
