<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class ShowConfig extends Command
{
    protected $signature = 'config:show {keys?* : One or more config keys (dot notation allowed)}';
    protected $description = 'Shows selected or all config values as a JSON blob.';

    public function handle()
    {
        $keys = $this->argument('keys');

        $output = [];

        if (empty($keys)) {
            $output = config()->all();
        } else {
            foreach ($keys as $key) {
                $value = config($key);

                if (is_null($value)) {
                    $this->warn("Key not found: $key");
                    continue;
                }

                Arr::set($output, $key, $value);
            }
        }

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }
}
