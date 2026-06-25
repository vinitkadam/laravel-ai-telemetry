<?php

namespace Vinit\LaravelAiTelemetry\Drivers;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Throwable;
use Vinit\LaravelAiTelemetry\Contracts\TelemetryDriver;
use Vinit\LaravelAiTelemetry\SpanOperation;

class OtlpDriver implements TelemetryDriver
{
    /** @var array<string, SpanInterface> Open OTel spans keyed by our span key. */
    private array $openSpans = [];

    private ?TracerProvider $provider = null;

    public function __construct(
        private readonly string $endpoint,
        private readonly array $headers = [],
        private readonly float $timeout = 5.0,
    ) {}

    public function openSpan(string $key, SpanOperation $operation, ?string $parentKey, int $startNano, array $attributes): void
    {
        $spanBuilder = $this->provider()->getTracer('laravel-ai')
            ->spanBuilder($operation->value)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($startNano);

        if ($parentKey !== null && isset($this->openSpans[$parentKey])) {
            $spanBuilder->setParent(
                Context::getCurrent()->withContextValue($this->openSpans[$parentKey])
            );
        }

        $span = $spanBuilder->startSpan();

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
        $this->provider?->shutdown();
        $this->provider = null;
    }

    private function provider(): TracerProvider
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        return $this->provider = new TracerProvider(
            spanProcessors: [new BatchSpanProcessor($this->createExporter(), Clock::getDefault())],
            sampler: new AlwaysOnSampler,
            resource: ResourceInfo::create(Attributes::create([
                'service.name' => $this->resolveServiceName(),
                'telemetry.sdk.name' => 'laravel-ai',
                'telemetry.sdk.language' => 'php',
            ])),
        );
    }

    protected function createExporter(): SpanExporterInterface
    {
        $transport = (new OtlpHttpTransportFactory)->create(
            endpoint: $this->endpoint,
            contentType: ContentTypes::JSON,
            headers: $this->headers,
            timeout: $this->timeout,
        );

        return new SpanExporter($transport);
    }

    private function resolveServiceName(): string
    {
        try {
            return config('app.name', 'laravel');
        } catch (\Throwable) {
            return 'laravel';
        }
    }
}
