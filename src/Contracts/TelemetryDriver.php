<?php

namespace Vinit\LaravelAiTelemetry\Contracts;

use Throwable;
use Vinit\LaravelAiTelemetry\SpanOperation;

interface TelemetryDriver
{
    /**
     * Open a new span. Called the moment the operation begins.
     *
     * @param  array<string, mixed>  $attributes  Initial gen_ai.* attributes derived from the start event.
     */
    public function openSpan(
        string $key,
        SpanOperation $operation,
        ?string $parentKey,
        int $startNano,
        array $attributes,
    ): void;

    /**
     * Close an open span. Called when the operation finishes (success or failure).
     *
     * @param  array<string, mixed>  $attributes  Final gen_ai.* attributes derived from the end event.
     */
    public function closeSpan(
        string $key,
        int $endNano,
        array $attributes,
        ?Throwable $exception,
    ): void;

    public function shutdown(): void;
}
