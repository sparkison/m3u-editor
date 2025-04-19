<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Cerbero\JsonParser\JsonParser;

class TestXtream extends Command implements PromptsForMissingInput
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:xtream-test
                        {url : The URL of the Xtream API}
                        {user : The username for the Xtream API}
                        {password : The password for the Xtream API}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test connection to Xtream API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Normalize the playlist url and get the filename
        $user = urlencode($this->argument('user'));
        $password = $this->argument('password');

        $type = $this->choice(
            'What category would you like to fetch? (live or vod)',
            ['live', 'vod'],
            'live'
        );

        $baseUrl = str($this->argument('url'))->replace(' ', '%20')->toString();
        $userInfo = "$baseUrl/player_api.php?username=$user&password=$password";
        $typeCategories = "$baseUrl/player_api.php?username=$user&password=$password&action=get_{$type}_categories";
        $typeStreams = "$baseUrl/player_api.php?username=$user&password=$password&action=get_{$type}_streams";

        $userAgent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13';
        $verify = true;

        $userInfoResponse = Http::withUserAgent($userAgent)
            ->withOptions(['verify' => $verify])
            ->timeout(30)
            ->throw()->get($userInfo);
        if ($userInfoResponse->ok()) {
            $userInfo = json_decode($userInfoResponse->body(), true);
            $this->info("User Info: " . json_encode($userInfo));
        } else {
            $this->error("Error fetching user info: {$userInfoResponse->body()}");
            return;
        }

        $categoriesResponse = Http::withUserAgent($userAgent)
            ->withOptions(['verify' => $verify])
            ->timeout(60 * 5) // set timeout to five minutes
            ->throw()->get($typeCategories);
        if ($categoriesResponse->ok()) {
            $categories = collect(json_decode($categoriesResponse->body(), true));
            $typeStreamsResponse = Http::withUserAgent($userAgent)
                ->withOptions(['verify' => $verify])
                ->timeout(60 * 10) // set timeout to ten minute
                ->throw()->get($typeStreams);
            if ($typeStreamsResponse->ok()) {
                $channelFields = [
                    'title' => null,
                    'name' => '',
                    'url' => null,
                    'logo' => null,
                    'group' => '',
                    'group_internal' => '',
                    'stream_id' => null,
                    'lang' => null,
                    'country' => null,
                ];
                $typeStreams = JsonParser::parse($typeStreamsResponse->body());
                $streamBaseUrl = "$baseUrl/$type/$user/$password";
                LazyCollection::make($typeStreams)->each(function ($item) use ($streamBaseUrl, $categories, $channelFields) {
                    $category = $categories->firstWhere('category_id', $item['category_id']);
                    $extension = $item['container_extension'] ?? "ts";
                    $channel = [
                        ...$channelFields,
                        'title' => $item['name'],
                        'name' => $item['name'],
                        'url' => "$streamBaseUrl/{$item['stream_id']}." . $extension,
                        'logo' => $item['stream_icon'],
                        'group' => $category['category_name'] ?? '',
                        'group_internal' => $category['category_name'] ?? '',
                        'stream_id' => $item['stream_id'],
                        'channel' => $item['num'] ?? null,
                    ];
                    $this->info(json_encode($channel));
                });
            } else {
                $this->error("Error fetching streams: {$typeStreamsResponse->body()}");
            }
        } else {
            $this->error("Error fetching categories: {$categoriesResponse->body()}");
        }
    }
}
