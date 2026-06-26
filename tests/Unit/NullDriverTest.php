<?php

use Vinit\LaravelAiTelemetry\Drivers\NullDriver;
use Vinit\LaravelAiTelemetry\SpanOperation;

describe('NullDriver', function () {
    test('openSpan does not throw', function () {
        $driver = new NullDriver;

        $driver->openSpan('key', SpanOperation::Agent, null, (int) (microtime(true) * 1_000_000_000), []);

        expect(true)->toBeTrue();
    });

    test('closeSpan does not throw', function () {
        $driver = new NullDriver;

        $driver->openSpan('key', SpanOperation::Agent, null, (int) (microtime(true) * 1_000_000_000), []);
        $driver->closeSpan('key', (int) (microtime(true) * 1_000_000_000), [], null);

        expect(true)->toBeTrue();
    });

    test('closeSpan for unknown key does not throw', function () {
        $driver = new NullDriver;

        $driver->closeSpan('does-not-exist', (int) (microtime(true) * 1_000_000_000), [], null);

        expect(true)->toBeTrue();
    });

    test('shutdown does not throw', function () {
        $driver = new NullDriver;

        $driver->shutdown();

        expect(true)->toBeTrue();
    });
});
