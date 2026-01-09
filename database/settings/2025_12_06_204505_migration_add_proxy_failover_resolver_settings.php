<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.enable_failover_resolver')) {
            $this->migrator->add('general.enable_failover_resolver', false);
        }
        if (! $this->migrator->exists('general.failover_resolver_url')) {
            $this->migrator->add('general.failover_resolver_url', '');
        }
    }
};
