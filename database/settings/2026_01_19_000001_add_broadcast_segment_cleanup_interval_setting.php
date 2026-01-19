<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.broadcast_segment_cleanup_interval')) {
            $this->migrator->add('general.broadcast_segment_cleanup_interval', 5);
        }
    }
};
