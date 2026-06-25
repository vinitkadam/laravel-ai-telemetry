<?php

namespace Vinit\LaravelAiTelemetry\Drivers;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use Throwable;
use Vinit\LaravelAiTelemetry\Contracts\TelemetryDriver;
use Vinit\LaravelAiTelemetry\SpanOperation;

/**
 * Emits gen_ai.* spans into the application's existing OpenTelemetry pipeline
 * via the global TracerProvider. Requires only open-telemetry/api — no SDK,
 * no exporter, no opinions on where spans go.
 *
 * The user configures a TracerProvider (with whatever processors and exporters
 * they need — Phoenix, Datadog, an OpenInference processor, etc.) and registers
 * it globally before the application handles requests:
 *
 *   \OpenTelemetry\API\Globals::registerInitializer(fn () => $yourTracerProvider);
 *
 * If no TracerProvider is registered, the OTel API's built-in NoopTracerProvider
 * is used and all calls are silently discarded.
 */
class OtelDriver implements TelemetryDriver
{
    /** @var array<string, SpanInterface> Open spans keyed by our span key. */
    private array $openSpans = [];

    private ?TracerInterface $tracer = null;

    public function __construct(?TracerInterface $tracer = null)
    {
        $this->tracer = $tracer;
    }

    public function openSpan(string $key, SpanOperation $operation, ?string $parentKey, int $startNano, array $attributes): void
    {
        $builder = $this->tracer()
            ->spanBuilder($operation->value)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($startNano);

        if ($parentKey !== null && isset($this->openSpans[$parentKey])) {
            $builder->setParent(
                Context::getCurrent()->withContextValue($this->openSpans[$parentKey])
            );
        }

        $span = $builder->startSpan();

        foreach ($attributes as $k => $v) {
            if ($v !== null && $k !== '') {
                $span->setAttribute($k, $v);
            }
        }

        $this->openSpans[$key] = $span;
    }

    public function closeSpan(string $key, int $endNano, array $attributes, ?Throwable $exception): void
    {
        $span = $this->openSpans[$key] ?? null;

        if ($span === null) {
            return;
        }

        unset($this->openSpans[$key]);

        foreach ($attributes as $k => $v) {
            if ($v !== null && $k !== '') {
                $span->setAttribute($k, $v);
            }
        }

        if ($exception !== null) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end($endNano);
    }

    public function shutdown(): void
    {
        foreach ($this->openSpans as $span) {
            $span->end();
        }

        $this->openSpans = [];
    }

    private function tracer(): TracerInterface
    {
        return $this->tracer ??= Globals::tracerProvider()->getTracer('laravel-ai');
    }
}
