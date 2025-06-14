<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            // Changed 'ffmpeg_custom_command_template' to 'ffmpeg_custom_command_templates'
            // Changed default value from null to []
            $blueprint->add('ffmpeg_custom_command_templates', []);
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            // Changed 'ffmpeg_custom_command_template' to 'ffmpeg_custom_command_templates'
            $blueprint->delete('ffmpeg_custom_command_templates');
        });
    }
};
