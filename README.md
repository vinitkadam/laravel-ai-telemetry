# Laravel AI Telemetry

<p>
<a href="https://packagist.org/packages/vinitkadam/laravel-ai-telemetry"><img src="https://img.shields.io/packagist/dt/vinitkadam/laravel-ai-telemetry" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/vinitkadam/laravel-ai-telemetry"><img src="https://img.shields.io/packagist/v/vinitkadam/laravel-ai-telemetry" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/vinitkadam/laravel-ai-telemetry"><img src="https://img.shields.io/packagist/l/vinitkadam/laravel-ai-telemetry" alt="License"></a>
</p>

## Introduction

Laravel AI Telemetry bridges [laravel/ai](https://github.com/laravel/ai) lifecycle events into [OpenTelemetry](https://opentelemetry.io) spans following the [`gen_ai.*` semantic conventions](https://opentelemetry.io/docs/specs/semconv/gen-ai/). It listens to the events already dispatched by the AI SDK — no changes to your agent code required.

Every AI invocation produces a span hierarchy:

```
[Invocation span]
  └── [Step span]           gen_ai.request.model, gen_ai.request.max_tokens, ...
        └── [Tool span]     gen_ai.tool.name, tool arguments and results
```

## Requirements

- PHP 8.3+
- Laravel 12+
- [laravel/ai](https://github.com/laravel/ai) ^0.8.1

## Installation

```bash
composer require vinitkadam/laravel-ai-telemetry
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=ai-telemetry-config
```

## Configuration

Enable telemetry and choose a driver in your `.env`:

```env
AI_TELEMETRY_ENABLED=true
AI_TELEMETRY_DRIVER=otel
```

### Drivers

**`null`** (default) — discards all spans. Safe for production until you wire up a backend.

**`log`** — writes completed spans to a Laravel log channel. Useful for local debugging without any OTel infrastructure.

```env
AI_TELEMETRY_DRIVER=log
AI_TELEMETRY_LOG_CHANNEL=stack
```

**`otel`** — emits spans into the application's registered `TracerProvider` via `Globals::tracerProvider()`. Requires only `open-telemetry/api` (already a dependency of this package). AI spans nest naturally inside existing HTTP and database traces from any OTel-instrumented framework.

```env
AI_TELEMETRY_DRIVER=otel
```

You must register a `TracerProvider` before the `otel` driver resolves — typically via a service provider that calls `Globals::registerInitializer(...)`. See [laravel-ai-phoenix](https://github.com/vinitkadam/laravel-ai-phoenix) for a ready-made provider targeting Arize Phoenix, or wire any other backend (Datadog, Honeycomb, Jaeger) the same way.

## Span Attributes

The following `gen_ai.*` attributes are set on each span:

| Attribute | Source |
|---|---|
| `gen_ai.operation.name` | `"chat"` |
| `gen_ai.request.model` | Model name passed to the provider |
| `gen_ai.request.step_number` | Step index within the invocation |
| `gen_ai.request.max_tokens` | `TextGenerationOptions::$maxTokens` |
| `gen_ai.request.temperature` | `TextGenerationOptions::$temperature` |
| `gen_ai.request.top_p` | `TextGenerationOptions::$topP` |
| `gen_ai.usage.input_tokens` | Prompt token count from the provider |
| `gen_ai.usage.output_tokens` | Completion token count from the provider |
| `gen_ai.tool.name` | Tool class name |

## License

Laravel AI Telemetry is open-sourced software licensed under the [MIT license](LICENSE.md).
