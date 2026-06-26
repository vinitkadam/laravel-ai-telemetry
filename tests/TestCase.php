<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Vinit\LaravelAiTelemetry\AiTelemetryServiceProvider;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiTelemetryServiceProvider::class];
    }
}
