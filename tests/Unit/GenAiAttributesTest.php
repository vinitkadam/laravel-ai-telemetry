<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StepFinished;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\StepStarted;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Mockery\MockInterface;
use Vinit\LaravelAiTelemetry\GenAiAttributes;

function fakeStepResponse(
    string $model = 'gpt-4o',
    string $provider = 'openai',
    int $promptTokens = 100,
    int $completionTokens = 50,
    FinishReason $finishReason = FinishReason::Stop,
    int $cacheRead = 0,
    int $cacheWrite = 0,
): StepResponse {
    return new StepResponse(
        text: 'response text',
        toolCalls: [],
        finishReason: $finishReason,
        usage: new Usage(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            cacheWriteInputTokens: $cacheWrite,
            cacheReadInputTokens: $cacheRead,
        ),
        meta: new Meta(provider: $provider, model: $model),
    );
}

function fakeAgentPrompt(string $model = 'gpt-4o', string $providerName = 'openai'): AgentPrompt
{
    $agent = Mockery::mock(Agent::class);
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('name')->andReturn($providerName);

    return new AgentPrompt(
        agent: $agent,
        prompt: 'test prompt',
        attachments: [],
        provider: $provider,
        model: $model,
    );
}

describe('GenAiAttributes::fromStart', function () {
    test('StepStarted returns model, step_number, max_tokens, temperature, top_p', function () {
        $event = new StepStarted(
            invocationId: 'inv-1',
            stepId: 'step-1',
            stepNumber: 2,
            model: 'gpt-4o',
            options: new TextGenerationOptions(
                maxTokens: 512,
                temperature: 0.7,
                topP: 0.9,
            ),
        );

        $attrs = GenAiAttributes::fromStart($event);

        expect($attrs['gen_ai.operation.name'])->toBe('chat');
        expect($attrs['gen_ai.request.model'])->toBe('gpt-4o');
        expect($attrs['gen_ai.request.step_number'])->toBe(2);
        expect($attrs['gen_ai.request.max_tokens'])->toBe(512);
        expect($attrs['gen_ai.request.temperature'])->toBe(0.7);
        expect($attrs['gen_ai.request.top_p'])->toBe(0.9);
    });

    test('StepStarted with null options omits max_tokens, temperature, top_p', function () {
        $event = new StepStarted(
            invocationId: 'inv-1',
            stepId: 'step-1',
            stepNumber: 1,
            model: 'gpt-4o',
            options: null,
        );

        $attrs = GenAiAttributes::fromStart($event);

        expect($attrs)->toHaveKey('gen_ai.request.model');
        expect($attrs)->not->toHaveKey('gen_ai.request.max_tokens');
        expect($attrs)->not->toHaveKey('gen_ai.request.temperature');
        expect($attrs)->not->toHaveKey('gen_ai.request.top_p');
    });

    test('PromptingAgent returns operation name, model, and provider system', function () {
        $prompt = fakeAgentPrompt('claude-3-5-sonnet-20241022', 'anthropic');
        $event = new PromptingAgent(invocationId: 'inv-1', prompt: $prompt);

        $attrs = GenAiAttributes::fromStart($event);

        expect($attrs['gen_ai.operation.name'])->toBe('chat');
        expect($attrs['gen_ai.request.model'])->toBe('claude-3-5-sonnet-20241022');
        expect($attrs['gen_ai.system'])->toBe('anthropic');
    });

    test('InvokingTool returns execute_tool operation, tool name, call id, and json arguments', function () {
        $agent = Mockery::mock(Agent::class);
        $tool = Mockery::mock(Tool::class);
        $tool->shouldReceive('name')->andReturn('search_web');
        $tool->shouldReceive('description')->andReturn('Search the web for information');

        $event = new InvokingTool(
            invocationId: 'inv-1',
            toolInvocationId: 'tool-call-abc',
            agent: $agent,
            tool: $tool,
            arguments: ['query' => 'laravel testing'],
        );

        $attrs = GenAiAttributes::fromStart($event);

        expect($attrs['gen_ai.operation.name'])->toBe('execute_tool');
        expect($attrs['gen_ai.tool.name'])->toBe('search_web');
        expect($attrs['gen_ai.tool.call.id'])->toBe('tool-call-abc');
        expect($attrs['gen_ai.tool.call.arguments'])->toBe(json_encode(['query' => 'laravel testing']));
    });

    test('unrecognised event returns empty array', function () {
        $attrs = GenAiAttributes::fromStart(new stdClass);

        expect($attrs)->toBe([]);
    });
});

describe('GenAiAttributes::fromEnd', function () {
    test('StepFinished returns response model, provider, finish_reasons, and usage tokens', function () {
        $response = fakeStepResponse('gpt-4o', 'openai', 100, 50, FinishReason::Stop);
        $event = new StepFinished(
            invocationId: 'inv-1',
            stepId: 'step-1',
            stepNumber: 1,
            response: $response,
        );

        $attrs = GenAiAttributes::fromEnd($event);

        expect($attrs['gen_ai.response.model'])->toBe('gpt-4o');
        expect($attrs['gen_ai.system'])->toBe('openai');
        expect($attrs['gen_ai.response.finish_reasons'])->toBe(['stop']);
        expect($attrs['gen_ai.usage.input_tokens'])->toBe(100);
        expect($attrs['gen_ai.usage.output_tokens'])->toBe(50);
    });

    test('StepFinished includes cache_read_input_tokens when greater than zero', function () {
        $response = fakeStepResponse(cacheRead: 25, cacheWrite: 0);
        $event = new StepFinished('inv-1', 'step-1', 1, $response);

        $attrs = GenAiAttributes::fromEnd($event);

        expect($attrs)->toHaveKey('gen_ai.usage.cache_read_input_tokens', 25);
        expect($attrs)->not->toHaveKey('gen_ai.usage.cache_creation_input_tokens');
    });

    test('StepFinished includes cache_creation_input_tokens when greater than zero', function () {
        $response = fakeStepResponse(cacheRead: 0, cacheWrite: 10);
        $event = new StepFinished('inv-1', 'step-1', 1, $response);

        $attrs = GenAiAttributes::fromEnd($event);

        expect($attrs)->toHaveKey('gen_ai.usage.cache_creation_input_tokens', 10);
        expect($attrs)->not->toHaveKey('gen_ai.usage.cache_read_input_tokens');
    });

    test('StepFailed returns empty array', function () {
        $event = new StepFailed(
            invocationId: 'inv-1',
            stepId: 'step-1',
            stepNumber: 1,
            exception: new RuntimeException('model timed out'),
        );

        expect(GenAiAttributes::fromEnd($event))->toBe([]);
    });

    test('AgentFailed returns empty array', function () {
        $prompt = fakeAgentPrompt();
        $event = new AgentFailed(
            invocationId: 'inv-1',
            prompt: $prompt,
            exception: new RuntimeException('failed'),
        );

        expect(GenAiAttributes::fromEnd($event))->toBe([]);
    });

    test('ToolInvoked with string result returns that string as tool.result', function () {
        $agent = Mockery::mock(Agent::class);
        $tool = Mockery::mock(Tool::class);

        $event = new ToolInvoked(
            invocationId: 'inv-1',
            toolInvocationId: 'tool-call-1',
            agent: $agent,
            tool: $tool,
            arguments: [],
            result: 'the answer is 42',
        );

        $attrs = GenAiAttributes::fromEnd($event);

        expect($attrs['gen_ai.tool.call.result'])->toBe('the answer is 42');
    });

    test('ToolInvoked with array result returns json-encoded string as tool.result', function () {
        $agent = Mockery::mock(Agent::class);
        $tool = Mockery::mock(Tool::class);

        $event = new ToolInvoked(
            invocationId: 'inv-1',
            toolInvocationId: 'tool-call-2',
            agent: $agent,
            tool: $tool,
            arguments: [],
            result: ['status' => 'ok', 'count' => 3],
        );

        $attrs = GenAiAttributes::fromEnd($event);

        expect($attrs['gen_ai.tool.call.result'])->toBe(json_encode(['status' => 'ok', 'count' => 3]));
    });

    test('unrecognised event returns empty array', function () {
        expect(GenAiAttributes::fromEnd(new stdClass))->toBe([]);
    });
});

describe('gen_ai.input.messages serialization', function () {
    test('user message is serialized with role and content', function () {
        $event = new StepStarted(
            invocationId: 'inv-1',
            stepId: 'step-1',
            stepNumber: 0,
            model: 'gpt-4o',
            messages: [new Message(MessageRole::User, 'What is the weather?')],
        );

        $attrs = GenAiAttributes::fromStart($event);

        expect($attrs)->toHaveKey('gen_ai.input.messages');
        $decoded = json_decode($attrs['gen_ai.input.messages'], true);
        expect($decoded)->toHaveCount(1)
            ->and($decoded[0]['role'])->toBe('user')
            ->and($decoded[0]['content'])->toBe('What is the weather?');
    });

    test('assistant message without tool calls is serialized with content only', function () {
        $event = new StepStarted(
            invocationId: 'inv-1',
            stepId: 'step-1',
            stepNumber: 1,
            model: 'gpt-4o',
            messages: [new AssistantMessage('The weather is sunny.')],
        );

        $attrs = GenAiAttributes::fromStart($event);

        $decoded = json_decode($attrs['gen_ai.input.messages'], true);
        expect($decoded[0]['role'])->toBe('assistant')
            ->and($decoded[0]['content'])->toBe('The weather is sunny.')
            ->and($decoded[0])->not->toHaveKey('tool_calls');
    });

    test('assistant message with tool calls includes tool_calls array', function () {
        $toolCall = new ToolCall('tc-1', 'get_weather', ['location' => 'Paris']);
        $event = new StepStarted(
            invocationId: 'inv-1',
            stepId: 'step-1',
            stepNumber: 1,
            model: 'gpt-4o',
            messages: [new AssistantMessage('', new Collection([$toolCall]))],
        );

        $attrs = GenAiAttributes::fromStart($event);

        $decoded = json_decode($attrs['gen_ai.input.messages'], true);
        expect($decoded[0]['role'])->toBe('assistant')
            ->and($decoded[0]['tool_calls'])->toHaveCount(1)
            ->and($decoded[0]['tool_calls'][0]['id'])->toBe('tc-1')
            ->and($decoded[0]['tool_calls'][0]['name'])->toBe('get_weather')
            ->and($decoded[0]['tool_calls'][0]['arguments'])->toBe(['location' => 'Paris']);
    });

    test('tool result message is serialized with role tool and tool_results', function () {
        $toolResult = new ToolResult('tc-1', 'get_weather', [], 'sunny and 22°C', 'tr-1');
        $event = new StepStarted(
            invocationId: 'inv-1',
            stepId: 'step-1',
            stepNumber: 1,
            model: 'gpt-4o',
            messages: [new ToolResultMessage(new Collection([$toolResult]))],
        );

        $attrs = GenAiAttributes::fromStart($event);

        $decoded = json_decode($attrs['gen_ai.input.messages'], true);
        expect($decoded[0]['role'])->toBe('tool')
            ->and($decoded[0]['tool_results'][0]['id'])->toBe('tc-1')
            ->and($decoded[0]['tool_results'][0]['name'])->toBe('get_weather')
            ->and($decoded[0]['tool_results'][0]['result'])->toBe('sunny and 22°C');
    });

    test('array tool result is json-encoded inside tool_results', function () {
        $toolResult = new ToolResult('tc-2', 'search', [], ['hits' => 3, 'results' => ['a', 'b']], null);
        $event = new StepStarted(
            invocationId: 'inv-1',
            stepId: 'step-1',
            stepNumber: 1,
            model: 'gpt-4o',
            messages: [new ToolResultMessage(new Collection([$toolResult]))],
        );

        $attrs = GenAiAttributes::fromStart($event);

        $decoded = json_decode($attrs['gen_ai.input.messages'], true);
        expect($decoded[0]['tool_results'][0]['result'])->toBe(json_encode(['hits' => 3, 'results' => ['a', 'b']]));
    });

    test('empty messages omits gen_ai.input.messages', function () {
        $event = new StepStarted('inv-1', 'step-1', 0, 'gpt-4o');

        $attrs = GenAiAttributes::fromStart($event);

        expect($attrs)->not->toHaveKey('gen_ai.input.messages');
    });
});

describe('gen_ai.tool.definitions serialization', function () {
    test('tools are serialized with name and description', function () {
        $tool = Mockery::mock(Tool::class);
        $tool->shouldReceive('name')->andReturn('get_weather');
        $tool->shouldReceive('description')->andReturn('Get weather for a location');

        $event = new StepStarted('inv-1', 'step-1', 0, 'gpt-4o', [], [$tool]);

        $attrs = GenAiAttributes::fromStart($event);

        expect($attrs)->toHaveKey('gen_ai.tool.definitions');
        $decoded = json_decode($attrs['gen_ai.tool.definitions'], true);
        expect($decoded)->toHaveCount(1)
            ->and($decoded[0]['name'])->toBe('get_weather')
            ->and($decoded[0]['description'])->toBe('Get weather for a location');
    });

    test('empty tools omits gen_ai.tool.definitions', function () {
        $event = new StepStarted('inv-1', 'step-1', 0, 'gpt-4o');

        $attrs = GenAiAttributes::fromStart($event);

        expect($attrs)->not->toHaveKey('gen_ai.tool.definitions');
    });
});

describe('gen_ai.output.messages serialization', function () {
    test('StepFinished with text response emits output message with content', function () {
        $response = fakeStepResponse();
        $event = new StepFinished('inv-1', 'step-1', 0, $response);

        $attrs = GenAiAttributes::fromEnd($event);

        expect($attrs)->toHaveKey('gen_ai.output.messages');
        $decoded = json_decode($attrs['gen_ai.output.messages'], true);
        expect($decoded)->toHaveCount(1)
            ->and($decoded[0]['role'])->toBe('assistant')
            ->and($decoded[0]['content'])->toBe('response text');
    });

    test('StepFinished with tool calls emits output message with tool_calls', function () {
        $step = new StepResponse(
            text: '',
            toolCalls: [new ToolCall('tc-1', 'get_weather', ['city' => 'Paris'])],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage(100, 20),
            meta: new Meta('openai', 'gpt-4o'),
        );
        $event = new StepFinished('inv-1', 'step-1', 0, $step);

        $attrs = GenAiAttributes::fromEnd($event);

        $decoded = json_decode($attrs['gen_ai.output.messages'], true);
        expect($decoded[0]['role'])->toBe('assistant')
            ->and($decoded[0])->not->toHaveKey('content')
            ->and($decoded[0]['tool_calls'])->toHaveCount(1)
            ->and($decoded[0]['tool_calls'][0]['id'])->toBe('tc-1')
            ->and($decoded[0]['tool_calls'][0]['name'])->toBe('get_weather')
            ->and($decoded[0]['tool_calls'][0]['arguments'])->toBe(['city' => 'Paris']);
    });

    test('StepFinished always emits gen_ai.output.messages even when text and tool calls are empty', function () {
        $step = new StepResponse(
            text: '',
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new Usage,
            meta: new Meta('openai', 'gpt-4o'),
        );
        $event = new StepFinished('inv-1', 'step-1', 0, $step);

        $attrs = GenAiAttributes::fromEnd($event);

        expect($attrs)->toHaveKey('gen_ai.output.messages');
        $decoded = json_decode($attrs['gen_ai.output.messages'], true);
        expect($decoded[0]['role'])->toBe('assistant');
    });
});
