<?php

namespace Vinit\LaravelAiTelemetry\Drivers;

use Throwable;
use Vinit\LaravelAiTelemetry\Contracts\TelemetryDriver;
use Vinit\LaravelAiTelemetry\SpanOperation;

class NullDriver implements TelemetryDriver
{
    public function openSpan(string $key, SpanOperation $operation, ?string $parentKey, int $startNano, array $attributes): void {}

    public function closeSpan(string $key, int $endNano, array $attributes, ?Throwable $exception): void {}

    public function shutdown(): void {}
}
