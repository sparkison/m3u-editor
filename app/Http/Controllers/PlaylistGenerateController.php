<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Enums\PlaylistChannelId;
use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlaylistGenerateController extends Controller
{
    public function __invoke(Request $request, string $uuid)
    {
        // Fetch the playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->firstOrFail();
        }

        // Check auth
        $auth = $playlist->playlistAuths()->where('enabled', true)->first();
        if ($auth) {
            if (
                $request->get('username') !== $auth->username ||
                $request->get('password') !== $auth->password
            ) {
                return response()->json(['Error' => 'Unauthorized'], 401);
            }
        }

        // Generate a filename
        $filename = Str::slug($playlist->name) . '.m3u';

        // Check if proxy enabled
        if ($request->has('proxy')) {
            $proxyEnabled = $request->input('proxy') === 'true';
        } else {
            $proxyEnabled = $playlist->enable_proxy;
        }

        // Get ll active channels
        return response()->stream(
            function () use ($playlist, $proxyEnabled) {
                // Get all active channels
                $channels = $playlist->channels()
                    ->where('enabled', true)
                    ->with('epgChannel')
                    ->orderBy('sort')
                    ->orderBy('channel')
                    ->orderBy('title')
                    ->get();

                // Output the enabled channels
                echo "#EXTM3U\n";
                $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;
                $idChannelBy = $playlist->id_channel_by;
                foreach ($channels as $channel) {
                    // Get the title and name
                    $title = $channel->title_custom ?? $channel->title;
                    $name = $channel->name_custom ?? $channel->name;
                    $url = $channel->url_custom ?? $channel->url;
                    $epgData = $channel->epgChannel ?? null;
                    $channelNo = $channel->channel;
                    $timeshift = $channel->shift ?? 0;
                    if (!$channelNo && $playlist->auto_channel_increment) {
                        $channelNo = ++$channelNumber;
                    }
                    if ($proxyEnabled) {
                        $url = ProxyFacade::getProxyUrlForChannel($channel->id);
                    }
                    $tvgId = $idChannelBy === PlaylistChannelId::TvgId
                        ? $channel->stream_id_custom ?? $channel->stream_id
                        : $channelNo;

                    // Get the icon
                    $icon = '';
                    if ($channel->logo_type === ChannelLogoType::Epg && $epgData) {
                        $icon = $epgData->icon ?? '';
                    } elseif ($channel->logo_type === ChannelLogoType::Channel) {
                        $icon = $channel->logo ?? '';
                    }

                    // Output the channel
                    echo "#EXTINF:-1 tvg-chno=\"$channelNo\" tvg-id=\"$tvgId\" timeshift=\"$timeshift\" tvg-name=\"$name\" tvg-logo=\"$icon\" group-title=\"$channel->group\"," . $title . "\n";
                    if ($channel->extvlcopt) {
                        foreach ($channel->extvlcopt as $extvlcopt) {
                            echo "#EXTVLCOPT:{$extvlcopt['key']}={$extvlcopt['value']}\n";
                        }
                    }
                    if ($channel->kodidrop) {
                        foreach ($channel->kodidrop as $kodidrop) {
                            echo "#KODIPROP:{$kodidrop['key']}={$kodidrop['value']}\n";
                        }
                    }
                    echo $url . "\n";
                }
            },
            200,
            [
                'Access-Control-Allow-Origin' => '*',
                'Content-Disposition' => "attachment; filename=$filename",
                'Content-Type' => 'application/vnd.apple.mpegurl'
            ]
        );
    }

    public function hdhr(string $uuid)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->first();
        }

        // Check if playlist exists
        if (!$playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Setup the HDHR device info
        $deviceInfo = $this->getDeviceInfo($playlist);
        $deviceInfoXml = collect($deviceInfo)->map(function ($value, $key) {
            return "<$key>$value</$key>";
        })->implode('');
        $xmlResponse = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><root>$deviceInfoXml</root>";

        // Return the XML response to mimic the HDHR device
        return response($xmlResponse)->header('Content-Type', 'application/xml');
    }

    public function hdhrOverview(Request $request, string $uuid)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->first();
        }

        // Check auth
        $auth = $playlist->playlistAuths()->where('enabled', true)->first();
        if ($auth) {
            if (
                $request->get('username') !== $auth->username ||
                $request->get('password') !== $auth->password
            ) {
                return response()->json(['Error' => 'Unauthorized'], 401);
            }
        }

        return view('hdhr', [
            'name' => $playlist->name,
            'uuid' => $uuid,
        ]);
    }

    public function hdhrDiscover(string $uuid)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->first();
        }

        // Check if playlist exists
        if (!$playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Return the HDHR device info
        return $this->getDeviceInfo($playlist);
    }

    public function hdhrLineup(string $uuid)
    {
        // Fetch the playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->firstOrFail();
        }

        // Get all active channels
        $channels = $playlist->channels()
            ->where('enabled', true)
            ->orderBy('sort')
            ->orderBy('channel')
            ->orderBy('title')
            ->get();

        // Check if proxy enabled
        $proxyEnabled = $playlist->enable_proxy;
        return response()->json($channels->transform(function (Channel $channel) use ($proxyEnabled) {
            $url = $channel->url_custom ?? $channel->url;
            if ($proxyEnabled) {
                $url = route('stream', base64_encode((string)$channel->id));
            }
            $streamId = $channel->stream_id_custom ?? $channel->stream_id;
            return [
                'GuideNumber' => $streamId,
                'GuideName' => $channel->title_custom ?? $channel->title,
                'URL' => $url,
            ];

            // Example of more detailed response
            //            return [
            //                'GuideNumber' => $channel->channel_number ?? $streamId, // Channel number (e.g., "100")
            //                'GuideName'   => $channel->title_custom ?? $channel->title, // Channel name
            //                'URL'         => $url, // Stream URL
            //                'HD'          => $is_hd ? 1 : 0, // HD flag
            //                'VideoCodec'  => 'H264', // Set based on your stream format
            //                'AudioCodec'  => 'AAC', // Set based on your stream format
            //                'Favorite'    => $favorite ? 1 : 0, // Favorite flag
            //                'DRM'         => 0, // Assuming no DRM
            //                'Streaming'   => 'direct', // Direct stream or transcoding
            //            ];
        }));
    }

    public function hdhrLineupStatus(string $uuid)
    {
        // No need to fetch, status is same for all...
        return response()->json([
            'ScanInProgress' => 0,
            'ScanPossible' => 1,
            'Source' => 'Cable',
            'SourceList' => ['Cable'],
        ]);
    }

    private function getDeviceInfo($playlist)
    {
        // Return the HDHR device info
        $uuid = $playlist->uuid;
        $tunerCount = $playlist->streams;
        $deviceId = substr($uuid, 0, 8);
        return [
            'DeviceID' => $deviceId,
            'FriendlyName' => "{$playlist->name} HDHomeRun",
            'ModelNumber' => 'HDTC-2US',
            'FirmwareName' => 'hdhomerun3_atsc',
            'FirmwareVersion' => '20200101',
            'DeviceAuth' => 'test_auth_token',
            'BaseURL' => route('playlist.hdhr.overview', $uuid),
            'LineupURL' => route('playlist.hdhr.lineup', $uuid),
            'TunerCount' => $tunerCount,
        ];
    }
}
