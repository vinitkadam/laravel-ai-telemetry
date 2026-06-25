<?php

use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Vinit\LaravelAiTelemetry\Drivers\OtelDriver;
use Vinit\LaravelAiTelemetry\SpanOperation;

function makeOtelDriver(): array
{
    $exporter = new InMemoryExporter;
    $provider = new TracerProvider(
        spanProcessors: [new SimpleSpanProcessor($exporter)],
        sampler: new AlwaysOnSampler,
        resource: ResourceInfo::create(Attributes::create(['service.name' => 'test'])),
    );

    return [new OtelDriver($provider->getTracer('laravel-ai')), $exporter];
}

describe('OtelDriver', function () {
    test('exports a span with the operation value as its name', function () {
        [$driver, $exporter] = makeOtelDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, otelNanoNow(), []);
        $driver->closeSpan('root', otelNanoNow(), [], null);
        $driver->shutdown();

        expect($exporter->getSpans())->toHaveCount(1);
        expect($exporter->getSpans()[0]->getName())->toBe('agent');
    });

    test('sets attributes from openSpan on the exported span', function () {
        [$driver, $exporter] = makeOtelDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, otelNanoNow(), [
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => 'gpt-4o',
            'gen_ai.system' => 'openai',
        ]);
        $driver->closeSpan('root', otelNanoNow(), [], null);
        $driver->shutdown();

        $attrs = $exporter->getSpans()[0]->getAttributes();
        expect($attrs->get('gen_ai.operation.name'))->toBe('chat');
        expect($attrs->get('gen_ai.request.model'))->toBe('gpt-4o');
        expect($attrs->get('gen_ai.system'))->toBe('openai');
    });

    test('sets attributes from closeSpan on the exported span', function () {
        [$driver, $exporter] = makeOtelDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, otelNanoNow(), []);
        $driver->closeSpan('root', otelNanoNow(), [
            'gen_ai.usage.input_tokens' => 100,
            'gen_ai.usage.output_tokens' => 50,
        ], null);
        $driver->shutdown();

        $attrs = $exporter->getSpans()[0]->getAttributes();
        expect($attrs->get('gen_ai.usage.input_tokens'))->toBe(100);
        expect($attrs->get('gen_ai.usage.output_tokens'))->toBe(50);
    });

    test('links a child span to its parent via live span reference', function () {
        [$driver, $exporter] = makeOtelDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, otelNanoNow(), []);
        $driver->openSpan('step-1', SpanOperation::Step, 'root', otelNanoNow(), []);
        $driver->closeSpan('step-1', otelNanoNow(), [], null);
        $driver->closeSpan('root', otelNanoNow(), [], null);
        $driver->shutdown();

        $spans = collect($exporter->getSpans());
        $root = $spans->first(fn ($s) => $s->getName() === 'agent');
        $child = $spans->first(fn ($s) => $s->getName() === 'step');

        expect($child->getParentSpanId())->toBe($root->getSpanId());
    });

    test('sets STATUS_ERROR and an exception event on a failed span', function () {
        [$driver, $exporter] = makeOtelDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, otelNanoNow(), []);
        $driver->closeSpan('root', otelNanoNow(), [], new RuntimeException('model timed out'));
        $driver->shutdown();

        $span = $exporter->getSpans()[0];
        expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR);
        expect($span->getEvents())->toHaveCount(1);
        expect($span->getEvents()[0]->getName())->toBe('exception');
    });

    test('closes any unclosed spans on shutdown', function () {
        [$driver, $exporter] = makeOtelDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, otelNanoNow(), []);
        $driver->shutdown();

        expect($exporter->getSpans())->toHaveCount(1);
    });

    test('exports multiple spans in one batch', function () {
        [$driver, $exporter] = makeOtelDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, otelNanoNow(), []);
        $driver->openSpan('step-1', SpanOperation::Step, 'root', otelNanoNow(), []);
        $driver->openSpan('tool-1', SpanOperation::Tool, 'step-1', otelNanoNow(), []);
        $driver->closeSpan('tool-1', otelNanoNow(), [], null);
        $driver->closeSpan('step-1', otelNanoNow(), [], null);
        $driver->closeSpan('root', otelNanoNow(), [], null);
        $driver->shutdown();

        expect($exporter->getSpans())->toHaveCount(3);
    });

    test('ignores closeSpan calls for unknown keys without throwing', function () {
        [$driver, $exporter] = makeOtelDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, otelNanoNow(), []);
        $driver->closeSpan('root', otelNanoNow(), [], null);
        $driver->closeSpan('does-not-exist', otelNanoNow(), [], null);
        $driver->shutdown();

        expect($exporter->getSpans())->toHaveCount(1);
    });
});

function otelNanoNow(): int
{
    return (int) (microtime(true) * 1_000_000_000);
}
