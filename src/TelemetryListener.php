<?php

namespace Vinit\LaravelAiTelemetry;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\HasMetadata;
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

class TelemetryListener
{
    public function __construct(private readonly SpanCollector $collector) {}

    // ── Agent (root span) ──────────────────────────────────────────────────

    public function handlePromptingAgent(PromptingAgent $event): void
    {
        $this->collector->startSpan(
            key: $event->invocationId,
            operation: SpanOperation::Agent,
            parentKey: null,
            startEvent: $event,
            telemetryContext: $this->resolveTelemetryContext($event),
        );
    }

    public function handleStreamingAgent(StreamingAgent $event): void
    {
        $this->handlePromptingAgent($event);
    }

    public function handleAgentPrompted(AgentPrompted $event): void
    {
        $this->collector->endSpan($event->invocationId, $event);
    }

    public function handleAgentStreamed(AgentStreamed $event): void
    {
        $this->handleAgentPrompted($event);
    }

    public function handleAgentFailed(AgentFailed $event): void
    {
        $this->collector->failSpan($event->invocationId, $event->exception);
    }

    // ── Steps ──────────────────────────────────────────────────────────────

    public function handleStepStarted(StepStarted $event): void
    {
        $this->collector->startSpan(
            key: $event->stepId,
            operation: SpanOperation::Step,
            parentKey: $event->invocationId,
            startEvent: $event,
        );

        $this->collector->setActiveStepSpanKey($event->stepId);
    }

    public function handleStepFinished(StepFinished $event): void
    {
        $this->collector->endSpan($event->stepId, $event);
        $this->collector->clearActiveStepSpanKey();
    }

    public function handleStepFailed(StepFailed $event): void
    {
        $this->collector->failSpan($event->stepId, $event->exception);
        $this->collector->clearActiveStepSpanKey();
    }

    // ── Tool invocations ───────────────────────────────────────────────────

    public function handleInvokingTool(InvokingTool $event): void
    {
        $this->collector->startSpan(
            key: $event->toolInvocationId,
            operation: SpanOperation::Tool,
            parentKey: $this->collector->activeStepSpanKey() ?? $event->invocationId,
            startEvent: $event,
        );
    }

    public function handleToolInvoked(ToolInvoked $event): void
    {
        $this->collector->endSpan($event->toolInvocationId, $event);
    }

    // ── Embeddings ─────────────────────────────────────────────────────────

    public function handleGeneratingEmbeddings(GeneratingEmbeddings $event): void
    {
        $this->collector->startSpan(
            key: $event->invocationId,
            operation: SpanOperation::Embedding,
            parentKey: null,
            startEvent: $event,
            telemetryContext: $this->resolveContextMetadata(),
        );
    }

    public function handleEmbeddingsGenerated(EmbeddingsGenerated $event): void
    {
        $this->collector->endSpan($event->invocationId, $event);
    }

    // ── Image ──────────────────────────────────────────────────────────────

    public function handleGeneratingImage(GeneratingImage $event): void
    {
        $this->collector->startSpan(
            key: $event->invocationId,
            operation: SpanOperation::Image,
            parentKey: null,
            startEvent: $event,
            telemetryContext: $this->resolveContextMetadata(),
        );
    }

    public function handleImageGenerated(ImageGenerated $event): void
    {
        $this->collector->endSpan($event->invocationId, $event);
    }

    // ── Audio ──────────────────────────────────────────────────────────────

    public function handleGeneratingAudio(GeneratingAudio $event): void
    {
        $this->collector->startSpan(
            key: $event->invocationId,
            operation: SpanOperation::Audio,
            parentKey: null,
            startEvent: $event,
            telemetryContext: $this->resolveContextMetadata(),
        );
    }

    public function handleAudioGenerated(AudioGenerated $event): void
    {
        $this->collector->endSpan($event->invocationId, $event);
    }

    // ── Transcription ──────────────────────────────────────────────────────

    public function handleGeneratingTranscription(GeneratingTranscription $event): void
    {
        $this->collector->startSpan(
            key: $event->invocationId,
            operation: SpanOperation::Transcription,
            parentKey: null,
            startEvent: $event,
            telemetryContext: $this->resolveContextMetadata(),
        );
    }

    public function handleTranscriptionGenerated(TranscriptionGenerated $event): void
    {
        $this->collector->endSpan($event->invocationId, $event);
    }

    // ── Reranking ──────────────────────────────────────────────────────────

    public function handleReranking(Reranking $event): void
    {
        $this->collector->startSpan(
            key: $event->invocationId,
            operation: SpanOperation::Reranking,
            parentKey: null,
            startEvent: $event,
            telemetryContext: $this->resolveContextMetadata(),
        );
    }

    public function handleReranked(Reranked $event): void
    {
        $this->collector->endSpan($event->invocationId, $event);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function resolveTelemetryContext(PromptingAgent $event): array
    {
        $agentContext = $event->prompt->agent instanceof HasMetadata
            ? $event->prompt->agent->metadata()
            : [];

        return array_merge($this->resolveContextMetadata(), $agentContext);
    }

    private function resolveContextMetadata(): array
    {
        $metadata = [];

        foreach (['ai.user_id', 'ai.session_id', 'ai.tags'] as $key) {
            $value = Context::get($key);
            if ($value !== null) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /**
     * @return array<class-string, string>
     */
    public static function eventMappings(): array
    {
        return [
            PromptingAgent::class => 'handlePromptingAgent',
            StreamingAgent::class => 'handleStreamingAgent',
            AgentPrompted::class => 'handleAgentPrompted',
            AgentStreamed::class => 'handleAgentStreamed',
            AgentFailed::class => 'handleAgentFailed',
            StepStarted::class => 'handleStepStarted',
            StepFinished::class => 'handleStepFinished',
            StepFailed::class => 'handleStepFailed',
            InvokingTool::class => 'handleInvokingTool',
            ToolInvoked::class => 'handleToolInvoked',
            GeneratingEmbeddings::class => 'handleGeneratingEmbeddings',
            EmbeddingsGenerated::class => 'handleEmbeddingsGenerated',
            GeneratingImage::class => 'handleGeneratingImage',
            ImageGenerated::class => 'handleImageGenerated',
            GeneratingAudio::class => 'handleGeneratingAudio',
            AudioGenerated::class => 'handleAudioGenerated',
            GeneratingTranscription::class => 'handleGeneratingTranscription',
            TranscriptionGenerated::class => 'handleTranscriptionGenerated',
            Reranking::class => 'handleReranking',
            Reranked::class => 'handleReranked',
        ];
    }
}
