<?php

// file generated with AI assistance: Claude Code - 2026-11-20 14:25:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Integration\Conversation\FollowUpsTurnTest;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentChatTestTrait;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The OpenAI-compatible chat API `GET /api/v1/models` and
 * `POST /api/v1/chat/completions`: the models are the enabled agents with
 * a published version; a completion runs one turn on the agent named by
 * `model` (slug or id) and answers in the OpenAI shape with the version's
 * frame, OpenAI usage, the extensions `sources` and `follow_up_questions`
 * known from the chat API of RAG services, a schema answer as a JSON
 * string; `stream: true`
 * sends the same answer as chunks. Unsupported parameters, a foreign
 * `response_format` and an unknown model answer an OpenAI error object
 * before any provider call; a user without `neuronai:agent:chat` gets 403.
 *
 * Shares the client and fixtures of {@see AgentChatTest}.
 */
#[Group('database')]
final class OpenAiChatTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    use AgentChatTestTrait;

    private const string MODELS = '/api/v1/models';
    private const string COMPLETIONS = '/api/v1/chat/completions';

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        AgentFixtures::cleanUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        AgentFixtures::cleanUp();
        parent::tearDown();
    }

    public function testModelsListsEnabledPublishedAgents(): void
    {
        $this->publishedAgent('listed');
        $this->createAgent('unpublished');

        $response = $this->openAi('GET', self::MODELS);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $data = $response->toArray();
        self::assertSame('list', $data['object']);
        $ids = array_column($data['data'], 'id');
        self::assertContains(AgentFixtures::PREFIX.'listed', $ids);
        self::assertNotContains(AgentFixtures::PREFIX.'unpublished', $ids);
        $entry = $data['data'][array_search(AgentFixtures::PREFIX.'listed', $ids, true)];
        self::assertSame(['model', 'dmstr'], [$entry['object'], $entry['owned_by']]);
    }

    public function testCompletionAnswersInTheOpenAiShape(): void
    {
        $id = $this->publishedAgent('text', [
            'followUps' => true,
            'templates' => ['frame' => ['de' => 'Bot: {{answer}}']],
        ]);
        $this->factory()->getChatProvider()->addResponses(new AssistantMessage('Alle 500 Stunden.')->setUsage(new Usage(50, 10)));
        FollowUpsTurnTest::queueFollowUps($this->factory());

        // System messages are skipped, content parts are joined; the model may be the id.
        $response = $this->openAi('POST', self::COMPLETIONS, [
            'model' => $id,
            'temperature' => 0.2,
            'messages' => [
                ['role' => 'system', 'content' => 'Ignored.'],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => self::QUESTION]]],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $data = $response->toArray();
        self::assertSame('chat.completion', $data['object']);
        self::assertStringStartsWith('chatcmpl-', $data['id']);
        self::assertSame(AgentFixtures::PREFIX.'text', $data['model']);
        self::assertSame(['role' => 'assistant', 'content' => 'Bot: Alle 500 Stunden.', 'refusal' => null], $data['choices'][0]['message']);
        self::assertSame('stop', $data['choices'][0]['finish_reason']);
        self::assertSame([90, 18, 108], [$data['usage']['prompt_tokens'], $data['usage']['completion_tokens'], $data['usage']['total_tokens']]);
        self::assertSame([], $data['sources']);
        self::assertSame(FollowUpsTurnTest::FOLLOW_UPS, $data['follow_up_questions']);
        self::assertStringStartsWith('/api/agent_versions/', $data['agent_version']);

        $contents = array_map(static fn (object $m): string => (string) $m->getContent(), $this->providerRequests()[0]->messages);
        self::assertSame([self::QUESTION], $contents);
    }

    public function testSchemaAnswerIsAJsonStringWithItsFollowUps(): void
    {
        $schema = ['type' => 'object', 'properties' => ['message' => ['type' => 'string'], 'pages' => ['type' => 'array', 'items' => ['type' => 'integer']], 'followUps' => ['type' => 'array', 'items' => ['type' => 'string']]]];
        $this->publishedAgent('schema', ['responseSchema' => $schema]);
        // Numbers in a JSON array are no citations: "pages": [1] stays as it is.
        $this->factory()->queueAnswer('{"message": "Blau", "pages": [1], "followUps": ["Und rot?"]}');

        $data = $this->openAi('POST', self::COMPLETIONS, [
            'model' => AgentFixtures::PREFIX.'schema',
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'answer', 'schema' => $schema]],
            'messages' => self::ask(),
        ])->toArray();

        self::assertSame(['message' => 'Blau', 'pages' => [1], 'followUps' => ['Und rot?']], json_decode((string) $data['choices'][0]['message']['content'], true));
        self::assertSame([], $data['sources']);
        self::assertSame(['Und rot?'], $data['follow_up_questions']);
    }

    public function testStreamSendsTheAnswerAsChunks(): void
    {
        $this->publishedAgent('stream');
        $this->factory()->queueAnswer('Alle 500 Stunden.');

        $response = $this->openAi('POST', self::COMPLETIONS, ['model' => AgentFixtures::PREFIX.'stream', 'stream' => true, 'messages' => self::ask()]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertStringStartsWith('text/event-stream', $response->getHeaders()['content-type'][0]);
        $events = array_values(array_filter(array_map(static fn (string $line): string => substr($line, 6), explode("\n", $response->getContent())), static fn (string $data): bool => '' !== $data));
        self::assertCount(3, $events);
        self::assertSame('[DONE]', $events[2]);
        $first = json_decode($events[0], true);
        $last = json_decode($events[1], true);
        self::assertSame('chat.completion.chunk', $first['object']);
        self::assertSame(['role' => 'assistant', 'content' => 'Alle 500 Stunden.'], $first['choices'][0]['delta']);
        self::assertSame('stop', $last['choices'][0]['finish_reason']);
        self::assertArrayHasKey('usage', $last);
        self::assertSame([], $last['sources']);
        self::assertSame([], $last['follow_up_questions']);
    }

    public function testInvalidRequestsAnswerOpenAiErrorsWithoutProviderCall(): void
    {
        $id = $this->publishedAgent('errors');
        $model = AgentFixtures::PREFIX.'errors';
        $calls = $this->providerCalls();

        $cases = [
            [404, 'model_not_found', ['model' => 'missing', 'messages' => self::ask()]],
            [400, null, ['messages' => self::ask()]],
            [400, 'unsupported_parameter', ['model' => $model, 'tools' => [['type' => 'function']], 'messages' => self::ask()]],
            [400, 'unsupported_parameter', ['model' => $model, 'n' => 2, 'messages' => self::ask()]],
            [400, 'unsupported_parameter', ['model' => $model, 'messages' => [['role' => 'tool', 'content' => 'x']]]],
            [400, null, ['model' => $model, 'messages' => [['role' => 'assistant', 'content' => 'x']]]],
            [400, null, ['model' => $model, 'messages' => [['role' => 'user', 'content' => '']]]],
            [400, 'response_format_mismatch', ['model' => $model, 'response_format' => ['type' => 'json_object'], 'messages' => self::ask()]],
        ];
        foreach ($cases as [$status, $code, $body]) {
            $response = $this->openAi('POST', self::COMPLETIONS, $body);
            self::assertSame($status, $response->getStatusCode(), json_encode($body).' '.$response->getContent(false));
            $error = $response->toArray(false)['error'] ?? [];
            self::assertSame('invalid_request_error', $error['type'] ?? null);
            self::assertSame($code, $error['code'] ?? null);
            self::assertNotSame('', $error['message'] ?? '');
        }

        // Disabled and unpublished agents are unknown models, as in /v1/models.
        $this->createAgent('draft');
        $patched = AgentFixtures::send($this->client, 'PATCH', AgentFixtures::AGENTS.'/'.$id, AgentFixtures::ADMIN, ['enabled' => false]);
        self::assertSame(200, $patched->getStatusCode(), $patched->getContent(false));
        foreach ([$model, AgentFixtures::PREFIX.'draft'] as $hidden) {
            $response = $this->openAi('POST', self::COMPLETIONS, ['model' => $hidden, 'messages' => self::ask()]);
            self::assertSame(404, $response->getStatusCode(), $response->getContent(false));
            self::assertSame('model_not_found', $response->toArray(false)['error']['code']);
        }

        self::assertSame($calls, $this->providerCalls());
    }

    public function testAFailedTurnIsAGenericServerError(): void
    {
        $this->publishedAgent('failing');
        $this->factory()->queueThinkingCutOff('Ich überlege …');

        $response = $this->openAi('POST', self::COMPLETIONS, ['model' => AgentFixtures::PREFIX.'failing', 'messages' => self::ask()]);

        self::assertSame(500, $response->getStatusCode(), $response->getContent(false));
        $error = $response->toArray(false)['error'];
        self::assertSame('server_error', $error['type']);
        self::assertStringNotContainsString('maxTokens', $error['message']);
    }

    public function testAccess(): void
    {
        $this->publishedAgent('access');
        $calls = $this->providerCalls();

        $anonymous = $this->openAi('POST', self::COMPLETIONS, ['model' => AgentFixtures::PREFIX.'access', 'messages' => self::ask()], null, [TestHeaderAuthenticator::HEADER_AUTH => 'anonymous']);
        self::assertSame(401, $anonymous->getStatusCode(), $anonymous->getContent(false));

        self::assertSame($calls, $this->providerCalls());
    }

    /**
     * A plain-JSON request on the shared client, as `ROLE_USER` by default.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    private function openAi(string $method, string $uri, ?array $body = null, ?string $roles = AgentFixtures::USER, array $headers = []): ResponseInterface
    {
        $options = ['headers' => ['Accept' => 'application/json'] + AgentFixtures::identity($roles) + $headers];
        if (null !== $body) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body, \JSON_THROW_ON_ERROR);
        }
        $response = $this->client->request($method, $uri, $options);
        $response->getStatusCode();

        return $response;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function publishedAgent(string $slug, array $overrides = []): string
    {
        $id = $this->createAgent($slug, AgentFixtures::validCandidate($overrides));
        $this->publish($id);

        return $id;
    }

    /**
     * @return list<\NeuronAI\Testing\RequestRecord>
     */
    private function providerRequests(): array
    {
        return array_values(array_filter($this->factory()->getChatProvider()->getRecorded(), static fn (object $record): bool => $record instanceof \NeuronAI\Testing\RequestRecord));
    }
}
