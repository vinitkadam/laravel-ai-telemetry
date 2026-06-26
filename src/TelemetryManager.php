<?php

namespace Vinit\LaravelAiTelemetry;

use InvalidArgumentException;
use Vinit\LaravelAiTelemetry\Contracts\TelemetryDriver;
use Vinit\LaravelAiTelemetry\Drivers\LogDriver;
use Vinit\LaravelAiTelemetry\Drivers\NullDriver;
use Vinit\LaravelAiTelemetry\Drivers\OtelDriver;

class TelemetryManager
{
    public function __construct(private readonly array $config) {}

    public function driver(?string $name = null): TelemetryDriver
    {
        $name ??= $this->config['driver'] ?? 'null';

        $config = $this->config['drivers'][$name] ?? null;

        if ($config === null) {
            throw new InvalidArgumentException("Telemetry driver [{$name}] is not configured.");
        }

        return match ($config['driver']) {
            'null' => new NullDriver,
            'log' => new LogDriver($config['channel'] ?? null),
            'otel' => new OtelDriver,
            default => throw new InvalidArgumentException(
                "Unknown telemetry driver type [{$config['driver']}]."
            ),
        };
    }
}
