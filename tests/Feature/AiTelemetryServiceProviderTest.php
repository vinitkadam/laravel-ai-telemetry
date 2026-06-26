<?php

use Illuminate\Contracts\Events\Dispatcher;
use Vinit\LaravelAiTelemetry\SpanCollector;
use Vinit\LaravelAiTelemetry\TelemetryListener;
use Vinit\LaravelAiTelemetry\TelemetryManager;

describe('AiTelemetryServiceProvider', function () {
    test('TelemetryManager is resolved as a singleton', function () {
        $first = app(TelemetryManager::class);
        $second = app(TelemetryManager::class);

        expect($first)->toBeInstanceOf(TelemetryManager::class);
        expect($first)->toBe($second);
    });

    test('SpanCollector is resolved as a singleton', function () {
        $first = app(SpanCollector::class);
        $second = app(SpanCollector::class);

        expect($first)->toBeInstanceOf(SpanCollector::class);
        expect($first)->toBe($second);
    });

    test('config is merged under ai-telemetry key with null as default driver', function () {
        expect(config('ai-telemetry.driver'))->toBe('null');
    });

    test('no listeners are registered when ai-telemetry.enabled is false', function () {
        config()->set('ai-telemetry.enabled', false);

        $dispatcher = app(Dispatcher::class);

        foreach (TelemetryListener::eventMappings() as $event => $method) {
            expect($dispatcher->getListeners($event))->toBeEmpty();
        }
    });

    test('all event mappings have listeners registered when ai-telemetry.enabled is true', function () {
        config()->set('ai-telemetry.enabled', true);

        // Re-run boot by re-registering the provider with fresh app
        $app = app();
        $provider = new \Vinit\LaravelAiTelemetry\AiTelemetryServiceProvider($app);
        $provider->boot();

        $dispatcher = app(Dispatcher::class);

        foreach (TelemetryListener::eventMappings() as $event => $method) {
            expect($dispatcher->getListeners($event))
                ->not->toBeEmpty("Expected listeners for {$event}");
        }
    });

    test('terminating callback calls SpanCollector shutdown', function () {
        $collector = Mockery::mock(SpanCollector::class);
        $collector->shouldReceive('shutdown')->once();

        app()->instance(SpanCollector::class, $collector);

        config()->set('ai-telemetry.enabled', true);

        $app = app();
        $provider = new \Vinit\LaravelAiTelemetry\AiTelemetryServiceProvider($app);
        $provider->boot();

        // Trigger all registered terminating callbacks
        $app->terminate();
    });
});
