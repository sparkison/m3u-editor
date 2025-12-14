<?php

namespace App\Traits;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Log;

trait ProviderRequestDelay
{
    /**
     * Apply delay before making a request to the provider.
     * This can help avoid rate limiting by providers.
     */
    protected function applyProviderRequestDelay(): void
    {
        $settings = app(GeneralSettings::class);

        if ($settings->enable_provider_request_delay && $settings->provider_request_delay_ms > 0) {
            $delayMs = $settings->provider_request_delay_ms;

            Log::debug("Applying provider request delay: {$delayMs}ms");

            // Convert milliseconds to microseconds for usleep
            usleep($delayMs * 1000);
        }
    }
}
