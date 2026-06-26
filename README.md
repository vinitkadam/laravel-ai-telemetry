# Laravel AI Telemetry

<p>
<a href="https://packagist.org/packages/vinitkadam/laravel-ai-telemetry"><img src="https://img.shields.io/packagist/dt/vinitkadam/laravel-ai-telemetry" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/vinitkadam/laravel-ai-telemetry"><img src="https://img.shields.io/packagist/v/vinitkadam/laravel-ai-telemetry" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/vinitkadam/laravel-ai-telemetry"><img src="https://img.shields.io/packagist/l/vinitkadam/laravel-ai-telemetry" alt="License"></a>
</p>

## Introduction

Laravel AI Telemetry bridges [laravel/ai](https://github.com/laravel/ai) lifecycle events into [OpenTelemetry](https://opentelemetry.io) spans following the [`gen_ai.*` semantic conventions](https://opentelemetry.io/docs/specs/semconv/gen-ai/). It listens to the events already dispatched by the AI SDK — no changes to your agent code required.

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

## Spans and Attributes

Every AI operation produces a span hierarchy. Span names follow the format `{operation} {model}` — for example `chat gpt-4o`, `execute_tool weather_lookup`, `embeddings text-embedding-3-small`.

---

### Invocation span

One per `prompt()` or `stream()` call. Parent of all step and tool spans for that invocation.

**On request:**

| Attribute | Value |
|---|---|
| `gen_ai.operation.name` | `"chat"` |
| `gen_ai.request.model` | Requested model name |
| `gen_ai.provider.name` | Provider name (e.g. `"openai"`, `"anthropic"`) |
| `gen_ai.system` | Provider name _(transitional alias for `gen_ai.provider.name`)_ |

**On response:**

| Attribute | Value |
|---|---|
| `gen_ai.response.model` | Actual model used by the provider |
| `gen_ai.usage.input_tokens` | Total prompt tokens across all steps |
| `gen_ai.usage.output_tokens` | Total completion tokens across all steps |
| `gen_ai.usage.reasoning_tokens` | Reasoning tokens _(o-series / extended thinking models, omitted when zero)_ |
| `gen_ai.usage.cache_read_input_tokens` | Tokens served from the provider cache _(omitted when zero)_ |
| `gen_ai.usage.cache_creation_input_tokens` | Tokens written to the provider cache _(omitted when zero)_ |

---

### Step span

One per LLM API call within the invocation loop. A single-turn invocation has one step; tool-use loops produce one step per round-trip. Child of the invocation span.

**On request:**

| Attribute | Value |
|---|---|
| `gen_ai.operation.name` | `"chat"` |
| `gen_ai.request.model` | Requested model name |
| `gen_ai.request.step_number` | Step index within the invocation (0-based) |
| `gen_ai.request.max_tokens` | `maxTokens` option _(omitted when not set)_ |
| `gen_ai.request.temperature` | `temperature` option _(omitted when not set)_ |
| `gen_ai.request.top_p` | `topP` option _(omitted when not set)_ |

**On response:**

| Attribute | Value |
|---|---|
| `gen_ai.provider.name` | Provider name |
| `gen_ai.system` | Provider name _(transitional alias for `gen_ai.provider.name`)_ |
| `gen_ai.response.model` | Actual model used by the provider |
| `gen_ai.response.finish_reasons` | e.g. `["stop"]`, `["tool_calls"]`, `["length"]` |
| `gen_ai.usage.input_tokens` | Prompt tokens for this step |
| `gen_ai.usage.output_tokens` | Completion tokens for this step |
| `gen_ai.usage.reasoning_tokens` | Reasoning tokens _(omitted when zero)_ |
| `gen_ai.usage.cache_read_input_tokens` | Tokens served from cache _(omitted when zero)_ |
| `gen_ai.usage.cache_creation_input_tokens` | Tokens written to cache _(omitted when zero)_ |

---

### Tool span

One per tool invocation. Child of the step span that triggered the tool call.

**On request:**

| Attribute | Value |
|---|---|
| `gen_ai.operation.name` | `"execute_tool"` |
| `gen_ai.tool.name` | Tool name |
| `gen_ai.tool.description` | Tool description |
| `gen_ai.tool.call.id` | Tool call ID assigned by the model |
| `gen_ai.tool.call.arguments` | JSON-encoded arguments passed to the tool |

**On response:**

| Attribute | Value |
|---|---|
| `gen_ai.tool.call.result` | Tool result (string, or JSON-encoded if non-string) |

---

### Embeddings span

One per `generateEmbeddings()` call.

**On request:**

| Attribute | Value |
|---|---|
| `gen_ai.operation.name` | `"embeddings"` |
| `gen_ai.request.model` | Requested model name |
| `gen_ai.provider.name` | Provider name |
| `gen_ai.system` | Provider name _(transitional alias)_ |

**On response:**

| Attribute | Value |
|---|---|
| `gen_ai.response.model` | Actual model used |
| `gen_ai.usage.input_tokens` | Tokens consumed |

---

### Image span

One per `generateImage()` call.

**On request:**

| Attribute | Value |
|---|---|
| `gen_ai.operation.name` | `"image_generation"` |
| `gen_ai.request.model` | Requested model name |
| `gen_ai.provider.name` | Provider name |
| `gen_ai.system` | Provider name _(transitional alias)_ |

**On response:**

| Attribute | Value |
|---|---|
| `gen_ai.response.model` | Actual model used |

---

### Audio span

One per `generateSpeech()` call.

**On request:**

| Attribute | Value |
|---|---|
| `gen_ai.operation.name` | `"text_to_speech"` |
| `gen_ai.request.model` | Requested model name |
| `gen_ai.provider.name` | Provider name |
| `gen_ai.system` | Provider name _(transitional alias)_ |

**On response:**

| Attribute | Value |
|---|---|
| `gen_ai.response.model` | Actual model used |

---

### Transcription span

One per `transcribeAudio()` call.

**On request:**

| Attribute | Value |
|---|---|
| `gen_ai.operation.name` | `"transcription"` |
| `gen_ai.request.model` | Requested model name |
| `gen_ai.provider.name` | Provider name |
| `gen_ai.system` | Provider name _(transitional alias)_ |

**On response:**

| Attribute | Value |
|---|---|
| `gen_ai.response.model` | Actual model used |

---

### Reranking span

One per `rerank()` call.

**On request:**

| Attribute | Value |
|---|---|
| `gen_ai.operation.name` | `"reranking"` |
| `gen_ai.request.model` | Requested model name |
| `gen_ai.provider.name` | Provider name |
| `gen_ai.system` | Provider name _(transitional alias)_ |

**On response:**

| Attribute | Value |
|---|---|
| `gen_ai.response.model` | Actual model used |

---

### Custom context attributes

Any attributes added via Laravel's `Context` facade under the `ai.*` namespace are attached to agent, embedding, image, audio, transcription, and reranking spans with a `gen_ai.metadata.` prefix:

```php
Context::add('ai.user_id', auth()->id());
Context::add('ai.session_id', session()->getId());
```

Agents implementing `HasMetadata` can return additional attributes directly:

```php
public function metadata(): array
{
    return ['ai.feature' => 'support-chat'];
}
```

---

### Not yet supported

The following standard `gen_ai.*` attributes are not emitted because the data is not available in the current `laravel/ai` events or `TextGenerationOptions`:

| Attribute | Reason |
|---|---|
| `gen_ai.request.top_k` | Not in `TextGenerationOptions` |
| `gen_ai.request.stop_sequences` | Not in `TextGenerationOptions` |
| `gen_ai.request.frequency_penalty` | Not in `TextGenerationOptions` |
| `gen_ai.request.presence_penalty` | Not in `TextGenerationOptions` |
| `gen_ai.request.seed` | Not in `TextGenerationOptions` |
| `gen_ai.response.id` | Not exposed in `Meta` |
| `gen_ai.agent.name` | No `name()` method on the `Agent` contract |
| `gen_ai.conversation.id` | Not available at event dispatch time |
| `gen_ai.provider.name` on step request | Provider not included in `StepStarted` event |

## License

Laravel AI Telemetry is open-sourced software licensed under the [MIT license](LICENSE.md).
