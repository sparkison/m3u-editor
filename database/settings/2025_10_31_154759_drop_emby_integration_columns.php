<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if ($this->migrator->exists('general.emby_server_url')) {
            $this->migrator->delete('general.emby_server_url');
        }
        if ($this->migrator->exists('general.emby_api_key')) {
            $this->migrator->delete('general.emby_api_key');
        }
        if ($this->migrator->exists('general.emby_import_groups_categories')) {
            $this->migrator->delete('general.emby_import_groups_categories');
        }
        if ($this->migrator->exists('general.emby_genre_handling')) {
            $this->migrator->delete('general.emby_genre_handling');
        }
    }
};
