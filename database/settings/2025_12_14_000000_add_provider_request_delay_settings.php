<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.enable_provider_request_delay', false);
        $this->migrator->add('general.provider_request_delay_ms', 500);
    }

    public function down(): void
    {
        $this->migrator->delete('general.enable_provider_request_delay');
        $this->migrator->delete('general.provider_request_delay_ms');
    }
};
