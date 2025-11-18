<?php

namespace App\Traits;

trait HasCustomHeaders
{
    /**
     * Check if custom headers are enabled and have at least one valid header
     * 
     * @return bool
     */
    public function hasValidCustomHeaders(): bool
    {
        if (!$this->enable_custom_headers) {
            return false;
        }

        $headers = $this->custom_headers ?? [];
        
        if (empty($headers)) {
            return false;
        }

        // Check if at least one header has both a header name and value
        foreach ($headers as $header) {
            if (is_array($header) && 
                !empty($header['header']) && 
                !empty($header['value'])) {
                return true;
            }
        }

        return false;
    }
}

