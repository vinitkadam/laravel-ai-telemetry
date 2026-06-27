<?php

namespace Vinit\LaravelAiTelemetry;

use Laravel\Ai\Contracts\Tool;
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
use Laravel\Ai\Events\StepFinished;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\StepStarted;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Events\TranscriptionGenerated;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
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
            $event instanceof StepFinished => self::stepEnd($event),
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
            'gen_ai.provider.name' => $event->prompt->provider->name(),
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

        if ($event->response->usage->reasoningTokens > 0) {
            $attrs['gen_ai.usage.reasoning_tokens'] = $event->response->usage->reasoningTokens;
        }
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
        $attrs = array_filter([
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => $event->model,
            'gen_ai.request.step_number' => $event->stepNumber,
            'gen_ai.request.max_tokens' => $event->options?->maxTokens,
            'gen_ai.request.temperature' => $event->options?->temperature,
            'gen_ai.request.top_p' => $event->options?->topP,
        ], fn ($v) => $v !== null);

        if (filled($event->messages)) {
            $attrs['gen_ai.input.messages'] = json_encode(
                array_map(fn ($m) => self::serializeMessage($m), $event->messages)
            );
        }

        if (filled($event->tools)) {
            $attrs['gen_ai.tool.definitions'] = json_encode(
                array_map(fn (Tool $t) => self::serializeTool($t), $event->tools)
            );
        }

        return $attrs;
    }

    private static function stepEnd(StepFinished $event): array
    {
        $step = $event->response;

        $attrs = [
            'gen_ai.provider.name' => $step->meta->provider,
            'gen_ai.system' => $step->meta->provider,
            'gen_ai.response.model' => $step->meta->model,
            'gen_ai.response.finish_reasons' => [$step->finishReason->value],
            'gen_ai.usage.input_tokens' => $step->usage->promptTokens,
            'gen_ai.usage.output_tokens' => $step->usage->completionTokens,
        ];

        if ($step->usage->reasoningTokens > 0) {
            $attrs['gen_ai.usage.reasoning_tokens'] = $step->usage->reasoningTokens;
        }
        if ($step->usage->cacheReadInputTokens > 0) {
            $attrs['gen_ai.usage.cache_read_input_tokens'] = $step->usage->cacheReadInputTokens;
        }
        if ($step->usage->cacheWriteInputTokens > 0) {
            $attrs['gen_ai.usage.cache_creation_input_tokens'] = $step->usage->cacheWriteInputTokens;
        }

        $outputMessage = ['role' => 'assistant'];

        if (filled($step->text)) {
            $outputMessage['content'] = $step->text;
        }

        if (filled($step->toolCalls)) {
            $outputMessage['tool_calls'] = array_map(fn ($tc) => [
                'id' => $tc->id,
                'name' => $tc->name,
                'arguments' => $tc->arguments,
            ], $step->toolCalls);
        }

        $attrs['gen_ai.output.messages'] = json_encode([$outputMessage]);

        return array_filter($attrs, fn ($v) => $v !== null);
    }

    // ── Tool ───────────────────────────────────────────────────────────────────

    private static function toolStart(InvokingTool $event): array
    {
        return array_filter([
            'gen_ai.operation.name' => 'execute_tool',
            'gen_ai.tool.name' => ToolNameResolver::resolve($event->tool),
            'gen_ai.tool.description' => (string) $event->tool->description(),
            'gen_ai.tool.call.id' => $event->toolInvocationId,
            'gen_ai.tool.call.arguments' => json_encode($event->arguments),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private static function toolEnd(ToolInvoked $event): array
    {
        return array_filter([
            'gen_ai.tool.call.result' => is_string($event->result)
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
            'gen_ai.provider.name' => $event->provider->name(),
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
            'gen_ai.provider.name' => $event->provider->name(),
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
            'gen_ai.provider.name' => $event->provider->name(),
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
            'gen_ai.provider.name' => $event->provider->name(),
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
            'gen_ai.provider.name' => $event->provider->name(),
            'gen_ai.system' => $event->provider->name(),
        ], fn ($v) => $v !== null);
    }

    private static function rerankingEnd(Reranked $event): array
    {
        return array_filter([
            'gen_ai.response.model' => $event->response->meta->model,
        ], fn ($v) => $v !== null);
    }

    // ── Serializers ────────────────────────────────────────────────────────────

    private static function serializeMessage(mixed $message): array
    {
        if ($message instanceof AssistantMessage) {
            $msg = ['role' => 'assistant', 'content' => $message->content ?? ''];

            if ($message->toolCalls->isNotEmpty()) {
                $msg['tool_calls'] = $message->toolCalls->map(fn ($tc) => [
                    'id' => $tc->id,
                    'name' => $tc->name,
                    'arguments' => $tc->arguments,
                ])->values()->all();
            }

            return $msg;
        }

        if ($message instanceof ToolResultMessage) {
            return [
                'role' => 'tool',
                'tool_results' => $message->toolResults->map(fn ($tr) => [
                    'id' => $tr->id,
                    'name' => $tr->name,
                    'result' => is_string($tr->result) ? $tr->result : json_encode($tr->result),
                ])->values()->all(),
            ];
        }

        return [
            'role' => $message->role->value,
            'content' => $message->content ?? '',
        ];
    }

    private static function serializeTool(Tool $tool): array
    {
        return array_filter([
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string) $tool->description(),
        ], fn ($v) => $v !== '');
    }
}
