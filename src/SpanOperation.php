<?php

namespace Vinit\LaravelAiTelemetry;

enum SpanOperation: string
{
    case Agent = 'agent';
    case Step = 'step';
    case Tool = 'tool';
    case Embedding = 'embedding';
    case Image = 'image';
    case Audio = 'audio';
    case Transcription = 'transcription';
    case Reranking = 'reranking';
}
