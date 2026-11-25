<?php

// file generated with AI assistance: Claude Code - 2026-11-20 14:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Controller;

use Dmstr\NeuronAiBundle\Chat\AgentChat;
use Dmstr\NeuronAiBundle\Chat\AgentChatResult;
use Dmstr\NeuronAiBundle\Chat\CitedSources;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * OpenAI-compatible chat API of the published agents, so a client that
 * speaks only OpenAI (an SDK, Open WebUI, an existing chat front end) can
 * use the agents by configuration: base URL, model, token.
 *
 * - `GET /api/v1/models`: one entry per enabled agent with a published
 *   version, `id` = agent slug, `owned_by` = the bundle option
 *   `openai_compatible.owned_by`;
 * - `POST /api/v1/chat/completions`: one stateless turn on the published
 *   version of the agent named by `model` (slug or id), as
 *   `POST /api/agents/{id}/chat` ({@see AgentChat}).
 *
 * Both need `neuronai:agent:chat`. Plain `application/json`, not JSON-LD;
 * every error is an OpenAI error object `{"error": {message, type, param,
 * code}}`: an unknown, disabled or unpublished agent is 404
 * `model_not_found`, a failed turn 500 `server_error` with a generic
 * message (the cause goes to the log). `finish_reason` is always `stop`:
 * an answer forced at the tool-call bound is complete, and a turn cut at
 * the token bound fails with a 500 instead of answering.
 *
 * The answer is `choices[0].message.content` (with the version's `frame`;
 * a schema answer as a JSON string, `message.refusal` when it did not
 * validate) and carries two extensions: `sources` (only the
 * cited ones, {@see CitedSources}, the `[n]` of the content, or of the
 * string values of a schema answer, renumbered) and
 * `follow_up_questions` (the version's `followUps`, or the `followUps` of a
 * schema answer). `stream: true` answers the finished turn as server-sent
 * `chat.completion.chunk` events (the content, then a closing chunk with
 * `finish_reason`, `usage`, `sources` and `follow_up_questions`, then
 * `data: [DONE]`); the turn itself is not streamed token by token.
 *
 * `system` and `developer` messages are skipped: the agent version brings
 * its own instructions. Sampling and length parameters (`temperature`,
 * `max_tokens`, ...) are ignored, the version fixes them. `tools`,
 * `functions`, `tool_choice`, `function_call`, `n` > 1, `logprobs`, audio
 * and `tool`/`function` messages answer 400. A `response_format` must match
 * the version: `text` for a version without `responseSchema`, `json_object`
 * or a `json_schema` with the version's schema for one with it.
 *
 * Plain Symfony routes (bundle `config/routes.php`), not API Platform
 * operations: they do not appear in the OpenAPI document.
 */
final class OpenAiChatController
{
    /** Request keys that need features the agent turn does not offer. */
    private const array UNSUPPORTED_KEYS = ['tools', 'functions', 'tool_choice', 'function_call', 'audio', 'prediction', 'web_search_options'];

    private const string OBJECT_COMPLETION = 'chat.completion';
    private const string OBJECT_CHUNK = 'chat.completion.chunk';

    private const string SCHEMA_REFUSAL = 'The answer did not match the response schema of the agent.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly AgentChat $agentChat,
        private readonly LoggerInterface $logger,
        private readonly string $ownedBy = 'dmstr',
    ) {
    }

    public function models(): Response
    {
        if (!$this->authorizationChecker->isGranted(Permissions::AGENT_CHAT)) {
            return self::error(Response::HTTP_FORBIDDEN, \sprintf('Listing the agents requires the permission %s.', Permissions::AGENT_CHAT));
        }

        /** @var list<Agent> $agents */
        $agents = $this->entityManager->getRepository(Agent::class)->findBy(['enabled' => true], ['slug' => 'ASC']);
        $data = [];
        foreach ($agents as $agent) {
            $version = $agent->getCurrentVersion();
            if (null === $version) {
                continue;
            }
            $data[] = [
                'id' => $agent->getSlug(),
                'object' => 'model',
                'created' => $version->getPublishedAt()->getTimestamp(),
                'owned_by' => $this->ownedBy,
                'name' => $agent->getName(),
            ];
        }

        return new JsonResponse(['object' => 'list', 'data' => $data]);
    }

    public function completions(Request $request): Response
    {
        try {
            if (!$this->authorizationChecker->isGranted(Permissions::AGENT_CHAT)) {
                return self::error(Response::HTTP_FORBIDDEN, \sprintf('Chatting with an agent requires the permission %s.', Permissions::AGENT_CHAT));
            }

            $body = json_decode($request->getContent(), true);
            if (!\is_array($body) || array_is_list($body)) {
                return self::error(Response::HTTP_BAD_REQUEST, 'The request body must be a JSON object.');
            }
            if (null !== $problem = self::unsupported($body)) {
                return $problem;
            }

            $model = $body['model'] ?? null;
            if (!\is_string($model) || '' === $model) {
                return self::error(Response::HTTP_BAD_REQUEST, 'The model is required: the slug or id of an agent.', 'model');
            }
            $agent = $this->agent($model);
            // Disabled and unpublished agents are not listed in /v1/models either; a 404 does not reveal them.
            if (null === $agent || !$agent->isEnabled() || null === $agent->getCurrentVersion()) {
                return self::error(Response::HTTP_NOT_FOUND, \sprintf('The model "%s" does not exist.', $model), 'model', 'model_not_found');
            }

            $messages = self::messages($body['messages'] ?? null);
            if (!\is_array($messages)) {
                return $messages;
            }

            $schema = $agent->getCurrentVersion()?->getConfiguration()['responseSchema'] ?? null;
            if (null !== $problem = self::responseFormat($body['response_format'] ?? null, $schema)) {
                return $problem;
            }

            try {
                $input = $this->inputBodyValidator->validate(Agent::OPERATION_CHAT, json_encode(['messages' => $messages], \JSON_THROW_ON_ERROR));
            } catch (UnprocessableEntityHttpException $e) {
                return self::error(Response::HTTP_BAD_REQUEST, $e->getMessage(), 'messages');
            }
            $result = $this->agentChat->chat($agent, $input);
        } catch (AccessDeniedException $e) {
            return self::error(Response::HTTP_FORBIDDEN, $e->getMessage());
        } catch (HttpExceptionInterface $e) {
            return $e->getStatusCode() >= 500 ? $this->serverError($e, $e->getStatusCode(), $body ?? null) : self::error($e->getStatusCode(), $e->getMessage());
        } catch (\Throwable $e) {
            // Provider and turn failures (maxTokens before an answer, a run without a final answer).
            return $this->serverError($e, Response::HTTP_INTERNAL_SERVER_ERROR, $body ?? null);
        }

        $completion = self::completion($agent->getSlug(), $result);

        return true === ($body['stream'] ?? false) ? self::stream($completion) : new JsonResponse($completion);
    }

    /**
     * A server-side failure: its message may carry internals (an upstream
     * host, a provider error), so the client gets a generic one and the log
     * the cause.
     */
    private function serverError(\Throwable $e, int $status, mixed $body): JsonResponse
    {
        $this->logger->error('OpenAI chat completion failed: {message}', ['message' => $e->getMessage(), 'exception' => $e, 'model' => \is_array($body) ? ($body['model'] ?? null) : null]);

        return self::error($status, 'The agent could not answer. Please try again later.');
    }

    /**
     * The agent named by `model`: its slug, or its id.
     */
    private function agent(string $model): ?Agent
    {
        $repository = $this->entityManager->getRepository(Agent::class);

        return $repository->findOneBy(['slug' => $model])
            ?? (Uuid::isValid($model) ? $repository->find(Uuid::fromString($model)) : null);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function unsupported(array $body): ?Response
    {
        foreach (self::UNSUPPORTED_KEYS as $key) {
            if (isset($body[$key]) && [] !== $body[$key]) {
                return self::error(Response::HTTP_BAD_REQUEST, \sprintf('"%s" is not supported: the agent version decides its tools.', $key), $key, 'unsupported_parameter');
            }
        }
        if (isset($body['n']) && 1 !== $body['n']) {
            return self::error(Response::HTTP_BAD_REQUEST, 'Only one choice (n = 1) is supported.', 'n', 'unsupported_parameter');
        }
        if (true === ($body['logprobs'] ?? false)) {
            return self::error(Response::HTTP_BAD_REQUEST, '"logprobs" is not supported.', 'logprobs', 'unsupported_parameter');
        }
        if (isset($body['stream']) && !\is_bool($body['stream'])) {
            return self::error(Response::HTTP_BAD_REQUEST, '"stream" must be a boolean.', 'stream');
        }

        return null;
    }

    /**
     * The OpenAI messages as the agent chat input: `user` and `assistant`
     * with their text, `system` and `developer` skipped; a content part
     * list is joined from its `text` parts.
     *
     * @return list<array{role: string, content: string}>|Response
     */
    private static function messages(mixed $messages): array|Response
    {
        if (!\is_array($messages) || [] === $messages || !array_is_list($messages)) {
            return self::error(Response::HTTP_BAD_REQUEST, '"messages" must be a non-empty list.', 'messages');
        }

        $turn = [];
        foreach ($messages as $index => $message) {
            $role = \is_array($message) ? ($message['role'] ?? null) : null;
            if (\in_array($role, ['system', 'developer'], true)) {
                continue;
            }
            if (!\in_array($role, ['user', 'assistant'], true)) {
                return self::error(Response::HTTP_BAD_REQUEST, \sprintf('Message %d: only the roles user, assistant, system and developer are supported.', $index), 'messages', 'unsupported_parameter');
            }
            $content = self::text($message['content'] ?? null);
            if (null === $content) {
                return self::error(Response::HTTP_BAD_REQUEST, \sprintf('Message %d: the content must be a string or a list of text parts.', $index), 'messages');
            }
            $turn[] = ['role' => $role, 'content' => $content];
        }
        $last = [] !== $turn ? $turn[array_key_last($turn)] : null;
        if ('user' !== ($last['role'] ?? null) || '' === trim($last['content'])) {
            return self::error(Response::HTTP_BAD_REQUEST, 'The last message must be a non-empty user message.', 'messages');
        }

        return $turn;
    }

    private static function text(mixed $content): ?string
    {
        if (\is_string($content)) {
            return $content;
        }
        if (!\is_array($content) || !array_is_list($content)) {
            return null;
        }
        $texts = [];
        foreach ($content as $part) {
            if (!\is_array($part) || 'text' !== ($part['type'] ?? null) || !\is_string($part['text'] ?? null)) {
                return null;
            }
            $texts[] = $part['text'];
        }

        return implode("\n", $texts);
    }

    /**
     * Null when the `response_format` fits the version's response schema.
     *
     * @param array<string, mixed>|null $schema
     */
    private static function responseFormat(mixed $format, ?array $schema): ?Response
    {
        if (null === $format) {
            return null;
        }
        $type = \is_array($format) ? ($format['type'] ?? null) : null;
        $fits = match ($type) {
            'text' => null === $schema,
            'json_object' => null !== $schema,
            'json_schema' => null !== $schema && self::sameJson($format['json_schema']['schema'] ?? null, $schema),
            default => false,
        };

        return $fits ? null : self::error(Response::HTTP_BAD_REQUEST, null === $schema
            ? 'The agent answers in text; only the response_format "text" is accepted.'
            : 'The agent answers with its own response schema; only "json_object" or a "json_schema" with that schema is accepted.', 'response_format', 'response_format_mismatch');
    }

    private static function sameJson(mixed $a, mixed $b): bool
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!\is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalize, $value);
        };

        return $normalize($a) === $normalize($b);
    }

    /**
     * @return array<string, mixed>
     */
    private static function completion(string $model, AgentChatResult $result): array
    {
        [$content, $refusal, $sources, $structured] = [null, null, [], null];
        if (null !== $result->schemaErrors) {
            $refusal = self::SCHEMA_REFUSAL;
        } elseif (null !== $result->structured) {
            // Only the string values are renumbered: numbers in JSON arrays are no citations.
            [$structured, $sources] = CitedSources::ofStructured($result->structured, $result->sources);
            $content = json_encode($structured, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        } else {
            [$content, $sources] = CitedSources::of(($result->frame['prefix'] ?? '').$result->answer.($result->frame['suffix'] ?? ''), $result->sources);
        }

        $usage = $result->usage ?? [];
        $prompt = (int) ($usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['output_tokens'] ?? 0);

        return [
            'id' => 'chatcmpl-'.str_replace('-', '', Uuid::v4()->toRfc4122()),
            'object' => self::OBJECT_COMPLETION,
            'created' => time(),
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content, 'refusal' => $refusal],
                // Always complete: a forced answer at the tool-call bound is a full answer (clients read `length` as cut off), a token cut throws.
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => $prompt + $completion,
                'prompt_tokens_details' => ['cached_tokens' => (int) ($usage['cached_input_tokens'] ?? 0)],
            ],
            'sources' => $sources,
            'follow_up_questions' => self::followUps($result, $structured),
            'agent_version' => $result->agentVersion,
        ];
    }

    /**
     * The version's follow-up suggestions, or the `followUps` list of a
     * schema answer, renumbered like its `content`.
     *
     * @return list<string>
     */
    private static function followUps(AgentChatResult $result, mixed $structured): array
    {
        $followUps = $result->followUps ?? (\is_array($structured) ? ($structured['followUps'] ?? null) : null);

        return \is_array($followUps) ? array_values(array_filter($followUps, 'is_string')) : [];
    }

    /**
     * The finished completion as OpenAI stream chunks.
     *
     * @param array<string, mixed> $completion
     */
    private static function stream(array $completion): Response
    {
        $chunk = static fn (array $choice, array $extra = []): string => 'data: '.json_encode([
            'id' => $completion['id'],
            'object' => self::OBJECT_CHUNK,
            'created' => $completion['created'],
            'model' => $completion['model'],
            'choices' => [['index' => 0] + $choice],
        ] + $extra, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n\n";

        $message = $completion['choices'][0]['message'];
        $delta = ['role' => 'assistant', 'content' => $message['content']] + (null !== $message['refusal'] ? ['refusal' => $message['refusal']] : []);

        $body = $chunk(['delta' => $delta, 'finish_reason' => null])
            .$chunk(['delta' => (object) [], 'finish_reason' => $completion['choices'][0]['finish_reason']], [
                'usage' => $completion['usage'],
                'sources' => $completion['sources'],
                'follow_up_questions' => $completion['follow_up_questions'],
                'agent_version' => $completion['agent_version'],
            ])
            ."data: [DONE]\n\n";

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'private, no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private static function error(int $status, string $message, ?string $param = null, ?string $code = null): JsonResponse
    {
        $type = match (true) {
            Response::HTTP_UNAUTHORIZED === $status => 'authentication_error',
            Response::HTTP_FORBIDDEN === $status => 'permission_error',
            $status >= 500 => 'server_error',
            default => 'invalid_request_error',
        };

        return new JsonResponse(['error' => ['message' => $message, 'type' => $type, 'param' => $param, 'code' => $code]], $status);
    }
}

// - revised 2026-11-24
// - revised 2026-11-24
// - revised 2026-11-24
