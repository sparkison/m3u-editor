<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (!$this->migrator->exists('general.emby_server_url')) {
            $this->migrator->add('general.emby_server_url', null);
        }
        if (!$this->migrator->exists('general.emby_api_key')) {
            $this->migrator->add('general.emby_api_key', null);
        }
    }
};