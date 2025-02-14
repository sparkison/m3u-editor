<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $navigation_position;
    public bool $show_breadcrumbs;
    public string $content_width;

    public static function group(): string
    {
        return 'general';
    }
}
