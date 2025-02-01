<?php

namespace App\Http\Controllers;

use App\Models\Epg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

class EpgFileController extends Controller
{
    public function __invoke(string $uuid)
    {
        $epg = Epg::where('uuid', $uuid)->firstOrFail();
        if (!Storage::exists($epg->file_path)) {
            abort(404);
        }
        $filePath = Storage::disk('local')->path($epg->file_path);
        $fileStream = Storage::disk('local')->readStream($epg->file_path);
        $fileSize = filesize($filePath);
        $headers = [
            'Content-type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename=epg.xml', // if we need to force download
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
            'Content-Length' => $fileSize,
        ];
        set_time_limit(0);
        $end = $fileSize - 1;
        $callback = function () use ($fileStream, $end) {
            ob_get_clean();
            $i = 0;
            $buffer = 102400;
            while (!feof($fileStream) && $i <= $end) {
                $bytesToRead = $buffer;
                if ($i + $bytesToRead > $end) {
                    $bytesToRead = $end - $i + 1;
                }
                $data = fread($fileStream, $bytesToRead);
                echo $data;
                flush();
                $i += $bytesToRead;
            }
            fclose($fileStream);
        };
        return Response::stream($callback, 200, $headers)->send();
    }
}
