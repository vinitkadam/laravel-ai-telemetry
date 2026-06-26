<?php

use Illuminate\Support\Facades\Context;
use Vinit\LaravelAiTelemetry\Contracts\TelemetryDriver;
use Vinit\LaravelAiTelemetry\SpanCollector;
use Vinit\LaravelAiTelemetry\SpanOperation;

function recordingDriver(): array
{
    $driver = new class implements TelemetryDriver
    {
        public array $opened = [];

        public array $closed = [];

        public function openSpan(string $key, SpanOperation $op, ?string $parentKey, int $startNano, array $attrs): void
        {
            $this->opened[] = compact('key', 'op', 'parentKey', 'attrs');
        }

        public function closeSpan(string $key, int $endNano, array $attrs, ?Throwable $exception): void
        {
            $this->closed[] = compact('key', 'attrs', 'exception');
        }

        public function shutdown(): void {}
    };

    return [$driver, new SpanCollector($driver)];
}

describe('SpanCollector', function () {
    test('startSpan calls openSpan on the driver with the right key, operation, and parentKey', function () {
        [$driver, $collector] = recordingDriver();

        $event = new stdClass;
        $collector->startSpan('span-1', SpanOperation::Agent, 'parent-span', $event);

        expect($driver->opened)->toHaveCount(1);
        expect($driver->opened[0]['key'])->toBe('span-1');
        expect($driver->opened[0]['op'])->toBe(SpanOperation::Agent);
        expect($driver->opened[0]['parentKey'])->toBe('parent-span');
    });

    test('startSpan merges telemetryContext into attributes with gen_ai.metadata. prefix stripping leading ai.', function () {
        [$driver, $collector] = recordingDriver();

        $event = new stdClass;
        $collector->startSpan('span-2', SpanOperation::Step, null, $event, [
            'ai.user_id' => 'user-42',
            'ai.session_id' => 'sess-99',
        ]);

        expect($driver->opened[0]['attrs'])->toHaveKey('gen_ai.metadata.user_id', 'user-42');
        expect($driver->opened[0]['attrs'])->toHaveKey('gen_ai.metadata.session_id', 'sess-99');
    });

    test('endSpan calls closeSpan on the driver with null exception', function () {
        [$driver, $collector] = recordingDriver();

        $event = new stdClass;
        $collector->startSpan('span-3', SpanOperation::Tool, null, $event);
        $collector->endSpan('span-3');

        expect($driver->closed)->toHaveCount(1);
        expect($driver->closed[0]['key'])->toBe('span-3');
        expect($driver->closed[0]['exception'])->toBeNull();
    });

    test('failSpan calls closeSpan on the driver with the exception', function () {
        [$driver, $collector] = recordingDriver();

        $event = new stdClass;
        $exception = new RuntimeException('failure');
        $collector->startSpan('span-4', SpanOperation::Agent, null, $event);
        $collector->failSpan('span-4', $exception);

        expect($driver->closed)->toHaveCount(1);
        expect($driver->closed[0]['key'])->toBe('span-4');
        expect($driver->closed[0]['exception'])->toBe($exception);
    });

    test('setActiveStepSpanKey makes activeStepSpanKey return that key', function () {
        [, $collector] = recordingDriver();

        $collector->setActiveStepSpanKey('step-key-abc');

        expect($collector->activeStepSpanKey())->toBe('step-key-abc');
    });

    test('clearActiveStepSpanKey makes activeStepSpanKey return null', function () {
        [, $collector] = recordingDriver();

        $collector->setActiveStepSpanKey('step-key-abc');
        $collector->clearActiveStepSpanKey();

        expect($collector->activeStepSpanKey())->toBeNull();
    });

    test('activeStepSpanKey returns null when never set', function () {
        [, $collector] = recordingDriver();

        expect($collector->activeStepSpanKey())->toBeNull();
    });
});
