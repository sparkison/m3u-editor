<?php

namespace App\Console\Commands;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use Illuminate\Console\Command;
use React\EventLoop\Loop;
use React\Datagram\Factory;

class SsdpService extends Command
{
    protected $signature = 'ssdp:serve';
    protected $description = 'Run SSDP service for HDHomeRun discovery';

    public function handle()
    {
        $loop = Loop::get();
        $factory = new Factory($loop);

        $ssdpMulticast = '239.255.255.250';
        $ssdpPort = 1909; // Use 1900 for SSDP
        $deviceType = 'urn:schemas-upnp-org:device:MediaServer:1';

        // Simulating multiple HDHR devices
        $playlists = Playlist::select('uuid', 'name')->get();
        $customPlaylists = CustomPlaylist::select('uuid', 'name')->get();
        $mergedPlaylists = MergedPlaylist::select('uuid', 'name')->get();
        $hdhrDevices = $playlists->merge($customPlaylists)->merge($mergedPlaylists)->map(function ($playlist) {
            return ['uuid' => $playlist->uuid, 'name' => $playlist->name];
        })->toArray();

        // Create a UDP server using ReactPHP
        $factory->createServer("udp://0.0.0.0:$ssdpPort")->then(
            function ($server) use ($deviceType, $hdhrDevices) {
                $server->on('message', function ($message, $address) use ($deviceType, $hdhrDevices, $server) {
                    if (stripos($message, 'M-SEARCH') !== false && stripos($message, $deviceType) !== false) {
                        foreach ($hdhrDevices as $device) {
                            $uuid = $device['uuid'];
                            $deviceId = substr($uuid, 0, 8);
                            $location = route('playlist.hdhr', $uuid);

                            $response = "HTTP/1.1 200 OK\r\n"
                                . "CACHE-CONTROL: max-age=1800\r\n"
                                . "EXT:\r\n"
                                . "LOCATION: $location\r\n"
                                . "SERVER: Laravel/1.0 UPnP/1.0 HDHomeRun/1.0\r\n"
                                . "ST: $deviceType\r\n"
                                . "USN: uuid:$deviceId::$deviceType\r\n"
                                . "\r\n";

                            $server->send($response, $address);
                        }
                    }
                });
            },
            function ($error) {
                echo "Failed to create SSDP server: " . $error->getMessage() . PHP_EOL;
            }
        );

        // SSDP Notify Broadcast
        $loop->addPeriodicTimer(30, function () use ($ssdpMulticast, $ssdpPort, $deviceType, $hdhrDevices) {
            foreach ($hdhrDevices as $device) {
                $uuid = $device['uuid'];
                $deviceId = substr($uuid, 0, 8);
                $location = route('playlist.hdhr', $uuid);

                $notify = "NOTIFY * HTTP/1.1\r\n"
                    . "HOST: $ssdpMulticast:$ssdpPort\r\n"
                    . "CACHE-CONTROL: max-age=1800\r\n"
                    . "LOCATION: $location\r\n"
                    . "SERVER: Laravel/1.0 UPnP/1.0 HDHomeRun/1.0\r\n"
                    . "NT: $deviceType\r\n"
                    . "NTS: ssdp:alive\r\n"
                    . "USN: uuid:$deviceId::$deviceType\r\n"
                    . "\r\n";

                $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 2);
                socket_sendto($socket, $notify, strlen($notify), 0, $ssdpMulticast, $ssdpPort);
                socket_close($socket);
            }
        });

        $loop->run();
    }
}
