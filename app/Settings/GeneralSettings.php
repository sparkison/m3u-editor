<?php

namespace App\Settings;

use Filament\Support\Enums\MaxWidth;
use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public ?string $navigation_position = 'left';
    public ?bool $show_breadcrumbs = true;
    public ?bool $show_logs = false;
    public ?bool $show_api_docs = false;
    public ?bool $show_queue_manager = false;
    public ?bool $show_proxy_debug = false;
    public ?string $content_width = MaxWidth::ScreenExtraLarge->value;

    public static function group(): string
    {
        return 'general';
    }
}
