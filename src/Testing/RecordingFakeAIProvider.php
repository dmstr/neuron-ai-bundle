<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use Dmstr\NeuronAiBundle\Provider\ToolChoice;
use Dmstr\NeuronAiBundle\Provider\ToolChoiceAwareProviderInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;

/**
 * FakeAIProvider with a script that can also request tool calls and fail.
 *
 * Every entry of the script answers one provider call, in queue order:
 *
 * - a prepared Message (queueAnswer(), queueReasoning(), addResponses());
 * - a batch of tool calls (consecutive queueToolCall() calls): resolved at
 *   call time by name against the tools the agent passed to the provider,
 *   the way real providers map a model's tool request (newToolCall() throws
 *   for an unknown name), and answered as one ToolCallMessage with parallel
 *   calls (`ToolCall` entries); the
 *   response may carry a reasoning block and a text (the text a model
 *   writes before it calls tools), as content blocks
 *   `[ReasoningContent?, TextContent?]` before the tools;
 * - a failure (queueFailure()): a ProviderException at call time;
 * - a mid-stream failure (queueStreamFailure()): a streamed answer whose
 *   chunk generator throws a ProviderException after the given number of
 *   chunks (the call itself succeeds and is recorded).
 *
 * chat(), structured() and the generator of stream() return a
 * ProviderResponse with the scripted message (neuron-ai 4.x).
 *
 * Streaming (stream()) yields the reasoning blocks of a response as
 * ReasoningChunks, then its text as TextChunks (for a tool-call response
 * before the agent's tool node runs, so they precede its tool events), all
 * carrying the response's message id and
 * of the chunk size
 * (setChunkSize(), default 5 characters). setChunkDelayMs() sleeps before
 * every chunk (the first one included); setChunkHook() runs a closure
 * `(int $index, StreamChunk $chunk)` before each chunk is yielded, the
 * index counting from 0 per stream() call.
 *
 * With echo on (setEcho()) an empty script answers with the text of the
 * last user message instead of throwing; off (the default), an empty
 * script throws as before.
 *
 * Recording (getRecorded(), assertions) works as in the parent class; a
 * failed call is not recorded, like a failing real provider. Every record
 * is a {@see ToolChoiceRequestRecord} carrying the tool choice set at call
 * time, so a test can assert that a turn forced or forbade tool use.
 *
 * Tool choice ({@see ToolChoiceAwareProviderInterface}): the fake accepts
 * every choice, standing in for a capable provider; declineToolChoice()
 * makes it decline every choice, standing in for one without support
 * (e.g. Ollama), so callers take their fallback path.
 * declineForcingChoices() declines only the forcing choices `any` and
 * `tool` and accepts `auto` and `none`, standing in for
 * AnthropicWithToolChoice on the model lines that reject forcing (5.x).
 * The choice only affects the records, not the scripted answers.
 *
 * Fixture mode (useFixtures()): an additional, order-independent way to
 * answer chat() and stream() calls, for batch runs that interleave many
 * question turns. A fixture is keyed by a phrase; it answers a call when
 * the content of the last user message (tool results excluded) contains
 * the key (case-sensitive substring; the longest matching key wins). A
 * call without a matching fixture falls through to the script and then to
 * the echo fallback or the empty-script exception, as before. A fixture is
 * a string (the answer) or an array with
 *
 * - `answer` (string): the answer text;
 * - `failure` (string): the call throws a ProviderException, like a
 *   queueFailure() entry;
 * - `toolCalls` (list of `{name, inputs?, callId?}`): the first call for
 *   the key answers with these tool calls (one response, parallel calls,
 *   resolved like queueToolCall()), the next call for the same key with
 *   `answer` (or `failure`), as queueToolCall() followed by queueAnswer()
 *   does; the call after that starts with the tool calls again;
 * - `reasoning` (string, optional): a reasoning block on the answer.
 *
 * Fixtures are persistent (not consumed); getFixtureHits() lists the keys
 * that answered, in call order. structured() never consults them.
 */
final class RecordingFakeAIProvider extends FakeAIProvider implements ToolChoiceAwareProviderInterface
{
    /**
     * @var list<array{type: 'message', message: Message}|array{type: 'tools', calls: list<array{name: string, inputs: array<string, mixed>, callId: string}>, reasoning: ?ReasoningContent, text: ?string}|array{type: 'failure', message: string}|array{type: 'stream_failure', message: string, afterChunks: int, text: ?string}>
     */
    private array $script = [];

    private int $callIds = 0;

    private int $chunkDelayMs = 0;

    /** @var (\Closure(int, StreamChunk): void)|null */
    private ?\Closure $chunkHook = null;

    private bool $echo = false;

    /** @var list<Message> messages of the current call (for the echo answer) */
    private array $lastMessages = [];

    /** @var array{message: string, afterChunks: int, text: ?string}|null set by nextResponse() for the stream it answers */
    private ?array $streamFailure = null;

    private ?ToolChoice $toolChoice = null;

    private bool $declineToolChoice = false;

    private bool $declineForcingChoices = false;

    /**
     * @var array<string, array{answer: ?string, failure: ?string, toolCalls: ?list<array{name: string, inputs: array<string, mixed>, callId: ?string}>, reasoning: ?string}>
     */
    private array $fixtures = [];

    /** @var array<string, true> keys whose tool calls were answered and whose next call gets the answer */
    private array $fixturePending = [];

    /** @var list<string> */
    private array $fixtureHits = [];

    private bool $inStructured = false;

    public function __construct(Message ...$responses)
    {
        parent::__construct();
        $this->addResponses(...$responses);
    }

    public function addResponses(Message ...$responses): self
    {
        foreach ($responses as $response) {
            $this->script[] = ['type' => 'message', 'message' => $response];
        }

        return $this;
    }

    public function queueAnswer(string $text): self
    {
        return $this->addResponses(new AssistantMessage($text));
    }

    /**
     * A response cut off by `max_tokens` while the model was still thinking:
     * a reasoning block, no text, stop reason `max_tokens`.
     */
    public function queueThinkingCutOff(string $reasoning): self
    {
        return $this->addResponses(new AssistantMessage([new ReasoningContent($reasoning, 'sig')])->setStopReason('max_tokens'));
    }

    /**
     * An answer with a reasoning block; $signature becomes the block id
     * (Anthropic's thinking signature).
     */
    public function queueReasoning(string $reasoning, string $answer, string $signature = 'sig'): self
    {
        return $this->addResponses(new AssistantMessage([
            new ReasoningContent($reasoning, $signature),
            new TextContent($answer),
        ]));
    }

    /**
     * Queues a tool call. Consecutive calls form one provider response with
     * parallel tool calls; an answer or failure queued in between starts a
     * new response. $reasoning adds a reasoning block to that response,
     * $text the text the model writes before its tool calls; for both a
     * later non-null value in the same response wins.
     *
     * @param array<string, mixed> $inputs
     * @param string|null          $callId null = a unique id from a counter
     */
    public function queueToolCall(string $name, array $inputs = [], ?string $callId = null, ?string $reasoning = null, string $signature = 'sig', ?string $text = null): self
    {
        $call = ['name' => $name, 'inputs' => $inputs, 'callId' => $callId ?? sprintf('call_%d', ++$this->callIds)];
        $last = array_key_last($this->script);

        if (null !== $last && 'tools' === $this->script[$last]['type']) {
            $this->script[$last]['calls'][] = $call;
            if (null !== $reasoning) {
                $this->script[$last]['reasoning'] = new ReasoningContent($reasoning, $signature);
            }
            if (null !== $text) {
                $this->script[$last]['text'] = $text;
            }

            return $this;
        }

        $this->script[] = [
            'type' => 'tools',
            'calls' => [$call],
            'reasoning' => null !== $reasoning ? new ReasoningContent($reasoning, $signature) : null,
            'text' => $text,
        ];

        return $this;
    }

    /**
     * The next provider call throws a ProviderException with $message.
     */
    public function queueFailure(string $message): self
    {
        $this->script[] = ['type' => 'failure', 'message' => $message];

        return $this;
    }

    /**
     * The next call streams $text (default: filler text longer than
     * $afterChunks chunks) and its generator throws a ProviderException
     * with $message after yielding $afterChunks chunks. A chat() call
     * answering this entry throws at call time.
     */
    public function queueStreamFailure(string $message, int $afterChunks, ?string $text = null): self
    {
        $this->script[] = ['type' => 'stream_failure', 'message' => $message, 'afterChunks' => max(0, $afterChunks), 'text' => $text];

        return $this;
    }

    /**
     * Sleep before every streamed chunk, in milliseconds (0 = none).
     */
    public function setChunkDelayMs(int $ms): self
    {
        $this->chunkDelayMs = max(0, $ms);

        return $this;
    }

    /**
     * Characters per streamed chunk.
     */
    public function setChunkSize(int $chars): self
    {
        $this->setStreamChunkSize(max(1, $chars));

        return $this;
    }

    /**
     * @param (\Closure(int, StreamChunk): void)|null $hook called before each streamed chunk is yielded
     */
    public function setChunkHook(?\Closure $hook): self
    {
        $this->chunkHook = $hook;

        return $this;
    }

    /**
     * With an empty script, answer with the text of the last user message
     * instead of throwing.
     */
    public function setEcho(bool $echo): self
    {
        $this->echo = $echo;

        return $this;
    }

    /**
     * Decline every tool choice (supportsToolChoice() returns false), as a
     * provider without tool choice support does.
     */
    public function declineToolChoice(bool $decline = true): self
    {
        $this->declineToolChoice = $decline;

        return $this;
    }

    /**
     * Decline the forcing choices `any` and `tool` (supportsToolChoice()
     * returns false for them) while `auto` and `none` stay supported, as
     * Anthropic's 5.x model lines do. declineToolChoice() wins over it.
     */
    public function declineForcingChoices(bool $decline = true): self
    {
        $this->declineForcingChoices = $decline;

        return $this;
    }

    public function supportsToolChoice(ToolChoice $choice): bool
    {
        if ($this->declineToolChoice) {
            return false;
        }

        return !($this->declineForcingChoices && ($choice->isAny() || $choice->isTool()));
    }

    public function setToolChoice(?ToolChoice $choice): static
    {
        $this->toolChoice = $choice;

        return $this;
    }

    public function getToolChoice(): ?ToolChoice
    {
        return $this->toolChoice;
    }

    /**
     * Replaces the fixture set (see the class docblock for the format); the
     * script queue is untouched.
     *
     * @param array<string, string|array<string, mixed>> $byKey key phrase => fixture
     *
     * @throws \InvalidArgumentException on an empty key or a malformed fixture
     */
    public function useFixtures(array $byKey): self
    {
        $fixtures = [];
        foreach ($byKey as $key => $fixture) {
            $key = (string) $key;
            $fixtures[$key] = self::normalizeFixture($key, $fixture);
        }

        $this->fixtures = $fixtures;
        $this->fixturePending = [];

        return $this;
    }

    /**
     * Empties the fixture set; the script queue is untouched.
     */
    public function clearFixtures(): self
    {
        $this->fixtures = [];
        $this->fixturePending = [];

        return $this;
    }

    /**
     * @return list<string> the fixture keys that answered a call, in call order
     */
    public function getFixtureHits(): array
    {
        return $this->fixtureHits;
    }

    public function hasQueuedResponses(): bool
    {
        return [] !== $this->script;
    }

    public function chat(Message ...$messages): ProviderResponse
    {
        $this->lastMessages = array_values($messages);
        if (null === $this->matchFixture() && 'stream_failure' === ($this->script[0]['type'] ?? null)) {
            $entry = array_shift($this->script);

            throw new ProviderException($entry['message']);
        }

        $response = parent::chat(...$messages);
        $this->enrichLastRecord();

        return $response;
    }

    /**
     * @param Message|Message[]    $messages
     * @param array<string, mixed> $response_schema
     */
    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        $this->inStructured = true;
        try {
            $response = parent::structured($messages, $class, $response_schema);
        } finally {
            $this->inStructured = false;
        }
        $this->enrichLastRecord();

        return $response;
    }

    /**
     * Like the parent (the script entry is taken at call time, a queued
     * failure throws here), but the chunks come from
     * {@see streamResponse()}: reasoning first, pacing, hook and a
     * mid-stream failure.
     *
     * @return \Generator<int, StreamChunk, mixed, ProviderResponse>
     */
    public function stream(Message ...$messages): \Generator
    {
        $this->lastMessages = array_values($messages);
        $response = $this->nextResponse();
        $failure = $this->streamFailure;
        $this->streamFailure = null;

        $this->recorded[] = new ToolChoiceRequestRecord(
            method: 'stream',
            messages: $messages,
            systemPrompt: $this->systemPrompt,
            tools: $this->tools,
            toolChoice: $this->toolChoice,
        );

        return $this->streamResponse($response, $failure);
    }

    /**
     * @return \Generator<int, StreamChunk, mixed, ProviderResponse>
     */
    protected function streamChunks(Message $response): \Generator
    {
        return $this->streamResponse($response, null);
    }

    protected function nextResponse(): Message
    {
        $key = $this->inStructured ? null : $this->matchFixture();
        if (null !== $key) {
            return $this->fixtureResponse($key);
        }

        $entry = array_shift($this->script);
        if (null === $entry) {
            if ($this->echo) {
                return new AssistantMessage($this->lastUserText());
            }

            throw new ProviderException('RecordingFakeAIProvider: the response queue is empty; queue answers, tool calls or failures first.');
        }

        if ('message' === $entry['type']) {
            return $entry['message'];
        }

        if ('failure' === $entry['type']) {
            throw new ProviderException($entry['message']);
        }

        if ('stream_failure' === $entry['type']) {
            // Taken by stream(); chat() throws before it gets here.
            $this->streamFailure = ['message' => $entry['message'], 'afterChunks' => $entry['afterChunks'], 'text' => $entry['text']];

            return new AssistantMessage($entry['text'] ?? '');
        }

        return $this->toolCallMessage($entry['calls'], $entry['reasoning'], $entry['text']);
    }

    /**
     * @param list<array{name: string, inputs: array<string, mixed>, callId: string}> $calls
     */
    private function toolCallMessage(array $calls, ?ReasoningContent $reasoning, ?string $text): ToolCallMessage
    {
        $tools = [];
        foreach ($calls as $call) {
            $tools[] = $this->newToolCall($call['name'], $call['callId'], $call['inputs']);
        }

        $blocks = [];
        if (null !== $reasoning) {
            $blocks[] = $reasoning;
        }
        if (null !== $text) {
            $blocks[] = new TextContent($text);
        }

        return new ToolCallMessage([] !== $blocks ? $blocks : null, $tools);
    }

    /**
     * The key of the fixture answering the current call: the longest key
     * contained in the last user message, null when none matches.
     */
    private function matchFixture(): ?string
    {
        if ([] === $this->fixtures) {
            return null;
        }

        $text = $this->lastUserText();
        $match = null;
        foreach (array_keys($this->fixtures) as $key) {
            $key = (string) $key;
            if (str_contains($text, $key) && (null === $match || mb_strlen($key) > mb_strlen($match))) {
                $match = $key;
            }
        }

        return $match;
    }

    /**
     * @throws ProviderException for a failure fixture
     */
    private function fixtureResponse(string $key): Message
    {
        $this->fixtureHits[] = $key;
        $fixture = $this->fixtures[$key];

        if (null !== $fixture['toolCalls'] && !isset($this->fixturePending[$key])) {
            $calls = [];
            foreach ($fixture['toolCalls'] as $call) {
                $calls[] = ['name' => $call['name'], 'inputs' => $call['inputs'], 'callId' => $call['callId'] ?? \sprintf('call_%d', ++$this->callIds)];
            }
            $message = $this->toolCallMessage($calls, null, null);
            $this->fixturePending[$key] = true;

            return $message;
        }
        unset($this->fixturePending[$key]);

        if (null !== $fixture['failure']) {
            throw new ProviderException($fixture['failure']);
        }

        $answer = (string) $fixture['answer'];
        if (null !== $fixture['reasoning']) {
            return new AssistantMessage([new ReasoningContent($fixture['reasoning'], 'sig'), new TextContent($answer)]);
        }

        return new AssistantMessage($answer);
    }

    /**
     * @return array{answer: ?string, failure: ?string, toolCalls: ?list<array{name: string, inputs: array<string, mixed>, callId: ?string}>, reasoning: ?string}
     *
     * @throws \InvalidArgumentException
     */
    private static function normalizeFixture(string $key, mixed $fixture): array
    {
        if ('' === $key) {
            throw new \InvalidArgumentException('RecordingFakeAIProvider: a fixture key must not be empty.');
        }
        if (\is_string($fixture)) {
            return ['answer' => $fixture, 'failure' => null, 'toolCalls' => null, 'reasoning' => null];
        }
        $invalid = static fn (string $why): \InvalidArgumentException => new \InvalidArgumentException(\sprintf('RecordingFakeAIProvider: fixture "%s" %s.', $key, $why));
        if (!\is_array($fixture)) {
            throw $invalid('must be a string or an array');
        }

        $unknown = array_diff(array_keys($fixture), ['answer', 'failure', 'toolCalls', 'reasoning']);
        if ([] !== $unknown) {
            throw $invalid(\sprintf('has unknown fields: %s', implode(', ', $unknown)));
        }
        foreach (['answer', 'failure', 'reasoning'] as $field) {
            if (isset($fixture[$field]) && !\is_string($fixture[$field])) {
                throw $invalid(\sprintf('field "%s" must be a string', $field));
            }
        }
        if (isset($fixture['answer']) === isset($fixture['failure'])) {
            throw $invalid('needs exactly one of "answer" and "failure"');
        }

        $toolCalls = null;
        if (isset($fixture['toolCalls'])) {
            if (!\is_array($fixture['toolCalls']) || [] === $fixture['toolCalls'] || !array_is_list($fixture['toolCalls'])) {
                throw $invalid('field "toolCalls" must be a non-empty list');
            }
            $toolCalls = [];
            foreach ($fixture['toolCalls'] as $call) {
                if (!\is_array($call) || !\is_string($call['name'] ?? null) || '' === $call['name']
                    || !\is_array($call['inputs'] ?? []) || !(null === ($call['callId'] ?? null) || \is_string($call['callId']))) {
                    throw $invalid('has a tool call without a "name" string, or with non-array "inputs" or a non-string "callId"');
                }
                $toolCalls[] = ['name' => $call['name'], 'inputs' => $call['inputs'] ?? [], 'callId' => $call['callId'] ?? null];
            }
        }

        return [
            'answer' => $fixture['answer'] ?? null,
            'failure' => $fixture['failure'] ?? null,
            'toolCalls' => $toolCalls,
            'reasoning' => $fixture['reasoning'] ?? null,
        ];
    }

    /**
     * @param array{message: string, afterChunks: int, text: ?string}|null $failure
     *
     * @return \Generator<int, StreamChunk, mixed, ProviderResponse>
     */
    private function streamResponse(Message $response, ?array $failure): \Generator
    {
        // Every chunk carries the id of the message the stream returns, as
        // a neuron-ai 4.x provider's chunks do.
        $messageId = $response->getId();
        $size = max(1, $this->streamChunkSize);

        $chunks = [];
        foreach ($response->getContentBlocks() as $block) {
            if ($block instanceof ReasoningContent) {
                foreach (self::splitText($block->content, $size) as $piece) {
                    $chunks[] = new ReasoningChunk($messageId, $piece);
                }
            }
        }
        $text = $response->getContent() ?? '';
        if (null !== $failure && null === $failure['text']) {
            $text = mb_substr(str_repeat('Partial answer ', ($failure['afterChunks'] + 1) * $size), 0, ($failure['afterChunks'] + 1) * $size);
        }
        foreach (self::splitText($text, $size) as $piece) {
            $chunks[] = new TextChunk($messageId, $piece);
        }

        foreach ($chunks as $index => $chunk) {
            if (null !== $failure && $index >= $failure['afterChunks']) {
                throw new ProviderException($failure['message']);
            }
            if ($this->chunkDelayMs > 0) {
                usleep($this->chunkDelayMs * 1000);
            }
            if (null !== $this->chunkHook) {
                ($this->chunkHook)($index, $chunk);
            }

            yield $chunk;
        }

        if (null !== $failure) {
            throw new ProviderException($failure['message']);
        }

        return new ProviderResponse(message: $response);
    }

    /**
     * Replaces the plain record the parent appended for the call that just
     * succeeded by one carrying the current tool choice.
     */
    private function enrichLastRecord(): void
    {
        $last = array_key_last($this->recorded);
        if (null === $last) {
            return;
        }

        $record = $this->recorded[$last];
        $this->recorded[$last] = new ToolChoiceRequestRecord(
            method: $record->method,
            messages: $record->messages,
            systemPrompt: $record->systemPrompt,
            tools: $record->tools,
            structuredClass: $record->structuredClass,
            structuredSchema: $record->structuredSchema,
            toolChoice: $this->toolChoice,
        );
    }

    /**
     * @return list<string>
     */
    private static function splitText(string $text, int $size): array
    {
        $pieces = [];
        for ($offset = 0, $length = mb_strlen($text); $offset < $length; $offset += $size) {
            $pieces[] = mb_substr($text, $offset, $size);
        }

        return $pieces;
    }

    private function lastUserText(): string
    {
        foreach (array_reverse($this->lastMessages) as $message) {
            if ($message instanceof UserMessage && !$message instanceof ToolResultMessage && null !== $message->getContent()) {
                return $message->getContent();
            }
        }

        return '';
    }
}

// - revised 2026-10-14
// - revised 2026-11-18
