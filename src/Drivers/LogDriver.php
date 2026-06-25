<?php

namespace Vinit\LaravelAiTelemetry\Drivers;

use Illuminate\Support\Facades\Log;
use Throwable;
use Vinit\LaravelAiTelemetry\Contracts\TelemetryDriver;
use Vinit\LaravelAiTelemetry\SpanOperation;

class LogDriver implements TelemetryDriver
{
    /**
     * @var array<string, array{operation: SpanOperation, parentKey: ?string, startNano: int, attributes: array<string, mixed>}>
     */
    private array $pending = [];

    public function __construct(private readonly ?string $channel = null) {}

    public function openSpan(string $key, SpanOperation $operation, ?string $parentKey, int $startNano, array $attributes): void
    {
        $this->pending[$key] = [
            'operation' => $operation,
            'parentKey' => $parentKey,
            'startNano' => $startNano,
            'attributes' => $attributes,
        ];
    }

    public function closeSpan(string $key, int $endNano, array $attributes, ?Throwable $exception): void
    {
        $pending = $this->pending[$key] ?? null;

        if ($pending === null) {
            return;
        }

        unset($this->pending[$key]);

        $logger = $this->channel ? Log::channel($this->channel) : Log::getFacadeRoot();

        $context = [
            'operation' => $pending['operation']->value,
            'parent_key' => $pending['parentKey'],
            'duration_ms' => round(($endNano - $pending['startNano']) / 1_000_000, 2),
            'attributes' => array_merge($pending['attributes'], $attributes),
        ];

        if ($exception !== null) {
            $logger->error('laravel-ai span', array_merge($context, [
                'error' => $exception->getMessage(),
                'error_class' => $exception::class,
            ]));
        } else {
            $logger->info('laravel-ai span', $context);
        }
    }

    public function shutdown(): void
    {
        $this->pending = [];
    }
}
