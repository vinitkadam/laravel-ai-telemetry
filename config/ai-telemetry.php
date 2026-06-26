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
    |   otel  — emits spans into the application's existing TracerProvider,
    |            registered via \OpenTelemetry\API\Globals::registerInitializer.
    |            Requires only open-telemetry/api (already a dependency of this
    |            package). Pair with vinitkadam03/laravel-ai-phoenix to route
    |            spans to Arize Phoenix, or wire any other TracerProvider.
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

        'otel' => [
            'driver' => 'otel',
        ],
    ],

];
