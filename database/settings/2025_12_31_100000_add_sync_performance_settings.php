<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.sync_performance_profile', 'auto');
        $this->migrator->add('general.sync_custom_metadata_chunk_size', 0);
        $this->migrator->add('general.sync_custom_strm_chunk_size', 0);
        $this->migrator->add('general.sync_custom_cleanup_chunk_size', 0);
    }

    public function down(): void
    {
        $this->migrator->delete('general.sync_performance_profile');
        $this->migrator->delete('general.sync_custom_metadata_chunk_size');
        $this->migrator->delete('general.sync_custom_strm_chunk_size');
        $this->migrator->delete('general.sync_custom_cleanup_chunk_size');
    }
};
