<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.m3u_proxy_public_url_auto_resolve')) {
            $this->migrator->add('general.m3u_proxy_public_url_auto_resolve', false);
        }
    }
};
