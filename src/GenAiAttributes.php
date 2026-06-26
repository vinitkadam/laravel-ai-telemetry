<?php

namespace Vinit\LaravelAiTelemetry;

use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingAudio;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\GeneratingTranscription;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\Reranking;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\StepStarted;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Events\TranscriptionGenerated;
use Laravel\Ai\Tools\ToolNameResolver;

class GenAiAttributes
{
    /**
     * Extract gen_ai.* attributes from a span's opening event.
     *
     * @return array<string, mixed>
     */
    public static function fromStart(object $event): array
    {
        return match (true) {
            $event instanceof PromptingAgent,
            $event instanceof StreamingAgent => self::agentStart($event),
            $event instanceof StepStarted => self::stepStart($event),
            $event instanceof InvokingTool => self::toolStart($event),
            $event instanceof GeneratingEmbeddings => self::embeddingStart($event),
            $event instanceof GeneratingImage => self::imageStart($event),
            $event instanceof GeneratingAudio => self::audioStart($event),
            $event instanceof GeneratingTranscription => self::transcriptionStart($event),
            $event instanceof Reranking => self::rerankingStart($event),
            default => [],
        };
    }

    /**
     * Extract gen_ai.* attributes from a span's closing event.
     *
     * @return array<string, mixed>
     */
    public static function fromEnd(object $event): array
    {
        return match (true) {
            $event instanceof AgentPrompted,
            $event instanceof AgentStreamed => self::agentEnd($event),
            $event instanceof StepCompleted => self::stepEnd($event),
            $event instanceof ToolInvoked => self::toolEnd($event),
            $event instanceof EmbeddingsGenerated => self::embeddingEnd($event),
            $event instanceof ImageGenerated => self::imageEnd($event),
            $event instanceof AudioGenerated => self::audioEnd($event),
            $event instanceof TranscriptionGenerated => self::transcriptionEnd($event),
            $event instanceof Reranked => self::rerankingEnd($event),
            $event instanceof AgentFailed,
            $event instanceof StepFailed => [],
            default => [],
        };
    }

    // ── Agent ──────────────────────────────────────────────────────────────────

    private static function agentStart(PromptingAgent $event): array
    {
        return array_filter([
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => $event->prompt->model,
            'gen_ai.system' => $event->prompt->provider->name(),
        ], fn ($v) => $v !== null);
    }

    private static function agentEnd(AgentPrompted $event): array
    {
        $attrs = [
            'gen_ai.response.model' => $event->response->meta->model,
            'gen_ai.usage.input_tokens' => $event->response->usage->promptTokens,
            'gen_ai.usage.output_tokens' => $event->response->usage->completionTokens,
        ];

        if ($event->response->usage->cacheReadInputTokens > 0) {
            $attrs['gen_ai.usage.cache_read_input_tokens'] = $event->response->usage->cacheReadInputTokens;
        }
        if ($event->response->usage->cacheWriteInputTokens > 0) {
            $attrs['gen_ai.usage.cache_creation_input_tokens'] = $event->response->usage->cacheWriteInputTokens;
        }

        return array_filter($attrs, fn ($v) => $v !== null);
    }

    // ── Step ───────────────────────────────────────────────────────────────────

    private static function stepStart(StepStarted $event): array
    {
        return array_filter([
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => $event->model,
            'gen_ai.request.step_number' => $event->stepNumber,
            'gen_ai.request.max_tokens' => $event->options?->maxTokens,
            'gen_ai.request.temperature' => $event->options?->temperature,
            'gen_ai.request.top_p' => $event->options?->topP,
        ], fn ($v) => $v !== null);
    }

    private static function stepEnd(StepCompleted $event): array
    {
        $step = $event->response;

        $attrs = [
            'gen_ai.response.model' => $step->meta->model,
            'gen_ai.system' => $step->meta->provider,
            'gen_ai.response.finish_reasons' => [$step->finishReason->value],
            'gen_ai.usage.input_tokens' => $step->usage->promptTokens,
            'gen_ai.usage.output_tokens' => $step->usage->completionTokens,
        ];

        if ($step->usage->cacheReadInputTokens > 0) {
            $attrs['gen_ai.usage.cache_read_input_tokens'] = $step->usage->cacheReadInputTokens;
        }
        if ($step->usage->cacheWriteInputTokens > 0) {
            $attrs['gen_ai.usage.cache_creation_input_tokens'] = $step->usage->cacheWriteInputTokens;
        }

        return array_filter($attrs, fn ($v) => $v !== null);
    }

    // ── Tool ───────────────────────────────────────────────────────────────────

    private static function toolStart(InvokingTool $event): array
    {
        return array_filter([
            'gen_ai.operation.name' => 'execute_tool',
            'gen_ai.tool.name' => ToolNameResolver::resolve($event->tool),
            'gen_ai.tool.call.id' => $event->toolInvocationId,
            'gen_ai.tool.arguments' => json_encode($event->arguments),
        ], fn ($v) => $v !== null);
    }

    private static function toolEnd(ToolInvoked $event): array
    {
        return array_filter([
            'gen_ai.tool.result' => is_string($event->result)
                ? $event->result
                : json_encode($event->result),
        ], fn ($v) => $v !== null);
    }

    // ── Embeddings ─────────────────────────────────────────────────────────────

    private static function embeddingStart(GeneratingEmbeddings $event): array
    {
        return array_filter([
            'gen_ai.operation.name' => 'embeddings',
            'gen_ai.request.model' => $event->model,
            'gen_ai.system' => $event->provider->name(),
        ], fn ($v) => $v !== null);
    }

    private static function embeddingEnd(EmbeddingsGenerated $event): array
    {
        return array_filter([
            'gen_ai.usage.input_tokens' => $event->response->tokens,
            'gen_ai.response.model' => $event->response->meta->model,
        ], fn ($v) => $v !== null);
    }

    // ── Image ──────────────────────────────────────────────────────────────────

    private static function imageStart(GeneratingImage $event): array
    {
        return array_filter([
            'gen_ai.operation.name' => 'image_generation',
            'gen_ai.request.model' => $event->model,
            'gen_ai.system' => $event->provider->name(),
        ], fn ($v) => $v !== null);
    }

    private static function imageEnd(ImageGenerated $event): array
    {
        return array_filter([
            'gen_ai.response.model' => $event->response->meta->model,
        ], fn ($v) => $v !== null);
    }

    // ── Audio ──────────────────────────────────────────────────────────────────

    private static function audioStart(GeneratingAudio $event): array
    {
        return array_filter([
            'gen_ai.operation.name' => 'text_to_speech',
            'gen_ai.request.model' => $event->model,
            'gen_ai.system' => $event->provider->name(),
        ], fn ($v) => $v !== null);
    }

    private static function audioEnd(AudioGenerated $event): array
    {
        return array_filter([
            'gen_ai.response.model' => $event->response->meta->model,
        ], fn ($v) => $v !== null);
    }

    // ── Transcription ──────────────────────────────────────────────────────────

    private static function transcriptionStart(GeneratingTranscription $event): array
    {
        return array_filter([
            'gen_ai.operation.name' => 'transcription',
            'gen_ai.request.model' => $event->model,
            'gen_ai.system' => $event->provider->name(),
        ], fn ($v) => $v !== null);
    }

    private static function transcriptionEnd(TranscriptionGenerated $event): array
    {
        return array_filter([
            'gen_ai.response.model' => $event->response->meta->model,
        ], fn ($v) => $v !== null);
    }

    // ── Reranking ──────────────────────────────────────────────────────────────

    private static function rerankingStart(Reranking $event): array
    {
        return array_filter([
            'gen_ai.operation.name' => 'reranking',
            'gen_ai.request.model' => $event->model,
            'gen_ai.system' => $event->provider->name(),
        ], fn ($v) => $v !== null);
    }

    private static function rerankingEnd(Reranked $event): array
    {
        return array_filter([
            'gen_ai.response.model' => $event->response->meta->model,
        ], fn ($v) => $v !== null);
    }
}
