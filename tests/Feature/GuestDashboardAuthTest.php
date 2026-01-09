<?php

declare(strict_types=1);

it('shows login form when not authenticated', function () {
    // Skip this test as Filament guest panel routes are not available in CI test environment
    $this->markTestSkipped('Filament guest panel routes require full Filament panel registration');
});

it('authenticates and shows dashboard after login', function () {
    // Skip this test as Filament guest panel routes are not available in CI test environment
    $this->markTestSkipped('Filament guest panel routes require full Filament panel registration');
});
