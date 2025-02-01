<?php

namespace App\Http\Controllers;

use App\Models\Epg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EpgFileController extends Controller
{
    public function __invoke(string $uuid)
    {
        $epg = Epg::where('uuid', $uuid)->firstOrFail();
        if (!Storage::exists($epg->file_path)) {
            abort(404);
        }
        $fs = Storage::getDriver();
        $stream = $fs->readStream($epg->file_path);
        return response()->stream(
            function () use ($stream) {
                while (ob_get_level() > 0) ob_end_flush();
                fpassthru($stream);
            },
            200,
            [
                'Content-Type' => 'application/xml',
                'Content-disposition' => 'attachment; filename="' . Str::slug($epg->name) . '.xml"',
            ]
        );
    }
}
