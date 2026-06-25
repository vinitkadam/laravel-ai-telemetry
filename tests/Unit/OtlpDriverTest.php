<?php

use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Vinit\LaravelAiTelemetry\Drivers\OtlpDriver;
use Vinit\LaravelAiTelemetry\SpanOperation;

class TestableOtlpDriver extends OtlpDriver
{
    public InMemoryExporter $exporter;

    protected function createExporter(): SpanExporterInterface
    {
        return $this->exporter = new InMemoryExporter;
    }
}

function makeOtlpDriver(): TestableOtlpDriver
{
    return new TestableOtlpDriver('http://localhost:4318/v1/traces');
}

describe('OtlpDriver', function () {
    test('exports a span with the operation value as its name', function () {
        $driver = makeOtlpDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, nanoNow(), []);
        $driver->closeSpan('root', nanoNow(), [], null);
        $driver->shutdown();

        expect($driver->exporter->getSpans())->toHaveCount(1);
        expect($driver->exporter->getSpans()[0]->getName())->toBe('agent');
    });

    test('sets attributes from openSpan on the exported span', function () {
        $driver = makeOtlpDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, nanoNow(), [
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => 'gpt-4o',
            'gen_ai.system' => 'openai',
        ]);
        $driver->closeSpan('root', nanoNow(), [], null);
        $driver->shutdown();

        $attrs = $driver->exporter->getSpans()[0]->getAttributes();
        expect($attrs->get('gen_ai.operation.name'))->toBe('chat');
        expect($attrs->get('gen_ai.request.model'))->toBe('gpt-4o');
        expect($attrs->get('gen_ai.system'))->toBe('openai');
    });

    test('sets attributes from closeSpan on the exported span', function () {
        $driver = makeOtlpDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, nanoNow(), []);
        $driver->closeSpan('root', nanoNow(), [
            'gen_ai.usage.input_tokens' => 100,
            'gen_ai.usage.output_tokens' => 50,
        ], null);
        $driver->shutdown();

        $attrs = $driver->exporter->getSpans()[0]->getAttributes();
        expect($attrs->get('gen_ai.usage.input_tokens'))->toBe(100);
        expect($attrs->get('gen_ai.usage.output_tokens'))->toBe(50);
    });

    test('links a child span to its parent via live span reference', function () {
        $driver = makeOtlpDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, nanoNow(), []);
        $driver->openSpan('step-1', SpanOperation::Step, 'root', nanoNow(), []);
        $driver->closeSpan('step-1', nanoNow(), [], null);
        $driver->closeSpan('root', nanoNow(), [], null);
        $driver->shutdown();

        $spans = collect($driver->exporter->getSpans());
        $root = $spans->first(fn ($s) => $s->getName() === 'agent');
        $child = $spans->first(fn ($s) => $s->getName() === 'step');

        expect($child->getParentSpanId())->toBe($root->getSpanId());
    });

    test('sets STATUS_ERROR and an exception event on a failed span', function () {
        $driver = makeOtlpDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, nanoNow(), []);
        $driver->closeSpan('root', nanoNow(), [], new RuntimeException('model timed out'));
        $driver->shutdown();

        $span = $driver->exporter->getSpans()[0];
        expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR);
        expect($span->getEvents())->toHaveCount(1);
        expect($span->getEvents()[0]->getName())->toBe('exception');
    });

    test('closes any unclosed spans on shutdown', function () {
        $driver = makeOtlpDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, nanoNow(), []);
        $driver->shutdown();

        expect($driver->exporter->getSpans())->toHaveCount(1);
    });

    test('exports multiple spans in one batch', function () {
        $driver = makeOtlpDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, nanoNow(), []);
        $driver->openSpan('step-1', SpanOperation::Step, 'root', nanoNow(), []);
        $driver->openSpan('tool-1', SpanOperation::Tool, 'step-1', nanoNow(), []);
        $driver->closeSpan('tool-1', nanoNow(), [], null);
        $driver->closeSpan('step-1', nanoNow(), [], null);
        $driver->closeSpan('root', nanoNow(), [], null);
        $driver->shutdown();

        expect($driver->exporter->getSpans())->toHaveCount(3);
    });

    test('ignores closeSpan calls for unknown keys without throwing', function () {
        $driver = makeOtlpDriver();
        $driver->openSpan('root', SpanOperation::Agent, null, nanoNow(), []);
        $driver->closeSpan('root', nanoNow(), [], null);
        $driver->closeSpan('does-not-exist', nanoNow(), [], null);
        $driver->shutdown();

        expect($driver->exporter->getSpans())->toHaveCount(1);
    });
});

function nanoNow(): int
{
    return (int) (microtime(true) * 1_000_000_000);
}
