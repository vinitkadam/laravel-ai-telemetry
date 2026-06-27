<?php

namespace Vinit\LaravelAiTelemetry;

use Illuminate\Support\Facades\Context;
use Throwable;
use Vinit\LaravelAiTelemetry\Contracts\TelemetryDriver;

class SpanCollector
{
    private const CONTEXT_KEY = 'laravel_ai_step_span_key';

    public function __construct(private readonly TelemetryDriver $driver) {}

    public function startSpan(
        string $key,
        SpanOperation $operation,
        ?string $parentKey,
        object $startEvent,
        array $telemetryContext = [],
    ): void {
        $attributes = array_merge(
            GenAiAttributes::fromStart($startEvent),
            $telemetryContext,
        );

        $this->driver->openSpan($key, $operation, $parentKey, $this->nanoTime(), $attributes);
    }

    public function endSpan(string $key, ?object $endEvent = null): void
    {
        $attributes = $endEvent !== null ? GenAiAttributes::fromEnd($endEvent) : [];

        $this->driver->closeSpan($key, $this->nanoTime(), $attributes, null);
    }

    public function failSpan(string $key, Throwable $exception): void
    {
        $this->driver->closeSpan($key, $this->nanoTime(), [], $exception);
    }

    /** Store the active step span key so tool events can resolve their parent. */
    public function setActiveStepSpanKey(string $stepKey): void
    {
        Context::addHidden(self::CONTEXT_KEY, $stepKey);
    }

    public function clearActiveStepSpanKey(): void
    {
        Context::addHidden(self::CONTEXT_KEY, null);
    }

    public function activeStepSpanKey(): ?string
    {
        return Context::getHidden(self::CONTEXT_KEY);
    }

    public function shutdown(): void
    {
        $this->driver->shutdown();
    }

    private function nanoTime(): int
    {
        return (int) (microtime(true) * 1_000_000_000);
    }


}
