<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telemetry Driver
    |--------------------------------------------------------------------------
    |
    | Which driver to use for emitting gen_ai.* OpenTelemetry spans. Three
    | drivers ship out of the box:
    |
    |   null  — discards all spans (safe default).
    |   log   — writes completed spans to a Laravel log channel. Useful for
    |            local debugging without any OTel infrastructure.
    |   otlp  — ships spans directly to an OTLP/HTTP endpoint (Grafana,
    |            Honeycomb, Datadog, Arize Phoenix, etc.) using its own
    |            TracerProvider. Requires open-telemetry/sdk and
    |            open-telemetry/exporter-otlp.
    |   otel  — emits spans into the application's existing TracerProvider,
    |            registered via \OpenTelemetry\API\Globals::registerInitializer.
    |            Zero-config if you already run an OTel agent. Requires only
    |            open-telemetry/api (already a dependency of this package).
    |
    */

    'enabled' => env('AI_TELEMETRY_ENABLED', false),

    'driver' => env('AI_TELEMETRY_DRIVER', 'null'),

    'drivers' => [
        'null' => [
            'driver' => 'null',
        ],

        'log' => [
            'driver' => 'log',
            'channel' => env('AI_TELEMETRY_LOG_CHANNEL'),
        ],

        'otlp' => [
            'driver' => 'otlp',
            'endpoint' => env('AI_TELEMETRY_OTLP_ENDPOINT', 'http://localhost:4318/v1/traces'),
            'headers' => [],
            'timeout' => 5.0,
        ],

        'otel' => [
            'driver' => 'otel',
        ],
    ],

];
