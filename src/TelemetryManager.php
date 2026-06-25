<?php

namespace Vinit\LaravelAiTelemetry;

use InvalidArgumentException;
use RuntimeException;
use Vinit\LaravelAiTelemetry\Contracts\TelemetryDriver;
use Vinit\LaravelAiTelemetry\Drivers\LogDriver;
use Vinit\LaravelAiTelemetry\Drivers\NullDriver;
use Vinit\LaravelAiTelemetry\Drivers\OtelDriver;
use Vinit\LaravelAiTelemetry\Drivers\OtlpDriver;

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
            'otlp' => $this->buildOtlpDriver($config),
            'otel' => new OtelDriver,
            default => throw new InvalidArgumentException(
                "Unknown telemetry driver type [{$config['driver']}]."
            ),
        };
    }

    private function buildOtlpDriver(array $config): OtlpDriver
    {
        if (! class_exists(\OpenTelemetry\SDK\Trace\TracerProvider::class)) {
            throw new RuntimeException(
                'The [open-telemetry/sdk] and [open-telemetry/exporter-otlp] packages are required to use the OTLP telemetry driver. '.
                'Install them with: composer require open-telemetry/sdk open-telemetry/exporter-otlp'
            );
        }

        $endpoint = $config['endpoint'] ?? 'http://localhost:4318/v1/traces';

        $headers = $config['headers'] ?? [];

        if (isset($config['api_key'])) {
            $headers['Authorization'] = 'Bearer '.$config['api_key'];
        }

        return new OtlpDriver(
            endpoint: $endpoint,
            headers: $headers,
            timeout: (float) ($config['timeout'] ?? 5.0),
        );
    }
}
