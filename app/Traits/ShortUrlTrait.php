<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use AshAllenDesign\ShortURL\Classes\Builder;
use AshAllenDesign\ShortURL\Models\ShortURL;
use Illuminate\Support\Facades\DB;

trait ShortUrlTrait
{
    /**
     * Generate a short URL.
     *
     * @param  string  $url
     * @return Model
     */
    public function generateShortUrl(): Model
    {
        if ($this->short_urls_enabled) {
            $urls = [
                'm3u' => route('playlist.generate', ['uuid' => $this->uuid]),
                'hdhr' => route('playlist.hdhr.overview', ['uuid' => $this->uuid]),
                'epg' => route('epg.generate', ['uuid' => $this->uuid]),
            ];
            $short_urls = [];
            foreach ($urls as $type => $url) {
                $short = app(Builder::class)->destinationUrl($url)->make();
                $short_urls[] = [
                    'id' => $short->id,
                    'type' => $type,
                    'short_url' => $short->default_short_url,
                ];
            }
            $this->short_urls = $short_urls;
        } else {
            // Delete the short URLs that contain the playlist UUID
            DB::table('short_urls')
                ->whereRaw('destination_url LIKE ?', ['%' . $this->uuid . '%'])
                ->delete();
            // Set the short URLs to null
            $this->short_urls = null;
        }

        return $this;
    }
}
