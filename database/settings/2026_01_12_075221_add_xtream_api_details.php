<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Series NFO generation setting
        if (! $this->migrator->exists('general.xtream_api_details')) {
            $this->migrator->add('general.xtream_api_details', null);
        }

    }
};
