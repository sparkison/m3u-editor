<?php

use Filament\Support\Enums\MaxWidth;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.navigation_position', 'left');
        $this->migrator->add('general.show_breadcrumbs', true);
        $this->migrator->add('general.content_width', MaxWidth::ScreenLarge);
    }
};
