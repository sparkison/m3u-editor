<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

// Use the CreatesApplication trait defined within the Tests namespace
use Tests\CreatesApplication;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
