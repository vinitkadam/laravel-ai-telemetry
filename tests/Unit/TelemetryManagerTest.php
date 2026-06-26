<?php

use Vinit\LaravelAiTelemetry\Drivers\LogDriver;
use Vinit\LaravelAiTelemetry\Drivers\NullDriver;
use Vinit\LaravelAiTelemetry\Drivers\OtelDriver;
use Vinit\LaravelAiTelemetry\TelemetryManager;

function telemetryConfig(string $default = 'null', array $extra = []): array
{
    return ['driver' => $default, 'drivers' => array_merge([
        'null' => ['driver' => 'null'],
        'log' => ['driver' => 'log'],
        'otel' => ['driver' => 'otel'],
    ], $extra)];
}

describe('TelemetryManager', function () {
    test('resolves null driver by default', function () {
        $manager = new TelemetryManager(telemetryConfig('null'));

        expect($manager->driver())->toBeInstanceOf(NullDriver::class);
    });

    test('resolves log driver', function () {
        $manager = new TelemetryManager(telemetryConfig('log'));

        expect($manager->driver())->toBeInstanceOf(LogDriver::class);
    });

    test('resolves otel driver', function () {
        $manager = new TelemetryManager(telemetryConfig('otel'));

        expect($manager->driver())->toBeInstanceOf(OtelDriver::class);
    });

    test('explicit driver name overrides the default', function () {
        $manager = new TelemetryManager(telemetryConfig('null'));

        expect($manager->driver('log'))->toBeInstanceOf(LogDriver::class);
    });

    test('unconfigured driver name throws InvalidArgumentException with driver name in message', function () {
        $manager = new TelemetryManager(telemetryConfig('null'));

        expect(fn () => $manager->driver('missing-driver'))
            ->toThrow(InvalidArgumentException::class, 'missing-driver');
    });

    test('unknown driver type in config throws InvalidArgumentException', function () {
        $config = telemetryConfig('custom', [
            'custom' => ['driver' => 'not-a-real-driver'],
        ]);
        $manager = new TelemetryManager($config);

        expect(fn () => $manager->driver('custom'))
            ->toThrow(InvalidArgumentException::class, 'not-a-real-driver');
    });
});
