<?php

use Illuminate\Support\Facades\Log;
use Vinit\LaravelAiTelemetry\Drivers\LogDriver;
use Vinit\LaravelAiTelemetry\SpanOperation;

function nanoNow(): int
{
    return (int) (microtime(true) * 1_000_000_000);
}

describe('LogDriver', function () {
    test('closeSpan logs at info level with operation, parent_key, duration_ms, and merged attributes', function () {
        Log::spy();

        $driver = new LogDriver;
        $start = nanoNow();
        $driver->openSpan('span-1', SpanOperation::Step, 'parent-key', $start, ['gen_ai.request.model' => 'gpt-4o']);
        $driver->closeSpan('span-1', nanoNow(), ['gen_ai.response.model' => 'gpt-4o-mini'], null);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'laravel-ai span'
                    && $context['operation'] === SpanOperation::Step->value
                    && $context['parent_key'] === 'parent-key'
                    && $context['duration_ms'] >= 0
                    && isset($context['attributes']['gen_ai.request.model'])
                    && $context['attributes']['gen_ai.request.model'] === 'gpt-4o'
                    && isset($context['attributes']['gen_ai.response.model'])
                    && $context['attributes']['gen_ai.response.model'] === 'gpt-4o-mini';
            });
    });

    test('closeSpan with exception logs at error level with error and error_class keys', function () {
        Log::spy();

        $driver = new LogDriver;
        $driver->openSpan('span-err', SpanOperation::Agent, null, nanoNow(), []);
        $exception = new RuntimeException('something went wrong');
        $driver->closeSpan('span-err', nanoNow(), [], $exception);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($exception) {
                return $message === 'laravel-ai span'
                    && $context['error'] === 'something went wrong'
                    && $context['error_class'] === RuntimeException::class;
            });
    });

    test('closeSpan for unknown key does not throw and logs nothing', function () {
        Log::spy();

        $driver = new LogDriver;
        $driver->closeSpan('does-not-exist', nanoNow(), [], null);

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    });

    test('shutdown clears pending spans', function () {
        Log::spy();

        $driver = new LogDriver;
        $driver->openSpan('span-a', SpanOperation::Tool, null, nanoNow(), []);
        $driver->shutdown();
        $driver->closeSpan('span-a', nanoNow(), [], null);

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    });

    test('duration_ms is non-negative', function () {
        Log::spy();

        $driver = new LogDriver;
        $driver->openSpan('span-time', SpanOperation::Embedding, null, nanoNow(), []);
        $driver->closeSpan('span-time', nanoNow(), [], null);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['duration_ms'] >= 0;
            });
    });
});
