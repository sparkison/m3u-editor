<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ShowConfig extends Command
{
    protected $signature = 'config:show {key? : Only get this one key}';

    protected $description = 'Shows the config';

    public function handle()
    {
        if ($specifiedKey = $this->argument('key')) {
        $loop = [$specifiedKey => config($specifiedKey)];
        } else {
        $loop = config()->all();
        }

        foreach ($loop as $key => $config) {
        $this->info($key);
        dump($config);
        $this->newLine();
        }

        return Command::SUCCESS;
    }
}