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

        // Generate a filename
        $filename = Str::slug($epg->name) . '.xml';

        // Setup the file stream
        $fs = Storage::getDriver();
        $stream = $fs->readStream($epg->file_path);

        // Return the original file
        return response()->stream(
            function () use ($stream) {
                while (!feof($stream)) {
                    echo fread($stream, 8192); // Read in 8KB chunks
                    flush(); // Ensure immediate output
                }
            },
            200,
            [
                'Access-Control-Allow-Origin' => '*',
                'Content-Disposition' => "attachment; filename=$filename",
                'Content-Type' => 'application/xml',
            ]
        );
    }
}
