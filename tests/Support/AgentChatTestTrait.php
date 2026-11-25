<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:45:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use NeuronAI\Testing\RequestRecord;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Requests and fake-provider reads of the agent chat tests
 * ({@see AgentChatTest}, {@see AgentChatCandidateTest}), for an
 * {@see \ApiPlatform\Symfony\Bundle\Test\ApiTestCase}.
 *
 * All requests run on the shared {@see self::$client}, which the using
 * class creates in setUp() with the reboot disabled, so the fake provider
 * keeps its queue and recordings between requests; agents are created
 * through the API on that client with the fixtures and the prefix of
 * {@see AgentFixtures} (its helpers would boot a new kernel and lose
 * the fake's state).
 */
trait AgentChatTestTrait
{
    private const string QUESTION = 'Wann muss die Spindel gewartet werden?';

    private Client $client;

    /**
     * @return list<array{role: string, content: string}>
     */
    private static function ask(): array
    {
        return [['role' => 'user', 'content' => self::QUESTION]];
    }

    /**
     * `POST /api/agents/{id}/chat` on the shared client, as `ROLE_USER` by default.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function chat(string $id, array $body, ?string $roles = AgentFixtures::USER, array $headers = []): ResponseInterface
    {
        $response = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS.'/'.$id.'/chat', $roles, $body, $headers);
        $response->getStatusCode();

        return $response;
    }

    /**
     * Creates an agent through the API on the shared client.
     *
     * @param array<string, mixed>|null $candidate null = a valid candidate
     *
     * @return string the agent id
     */
    private function createAgent(string $slug, ?array $candidate = null, ?string $name = null): string
    {
        AgentFixtures::providerConnections();
        $response = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS, AgentFixtures::ADMIN, [
            'name' => $name ?? 'Agent '.$slug,
            'slug' => AgentFixtures::PREFIX.$slug,
            'candidate' => $candidate ?? AgentFixtures::validCandidate(),
        ]);
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));

        return (string) $response->toArray()['id'];
    }

    /**
     * @return array<string, mixed> the published version
     */
    private function publish(string $id): array
    {
        $response = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS.'/'.$id.'/publish', AgentFixtures::ADMIN);
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));

        return $response->toArray();
    }

    private static function detail(ResponseInterface $response): string
    {
        return (string) ($response->toArray(false)['detail'] ?? '');
    }

    private function lastRequest(): RequestRecord
    {
        $records = $this->factory()->getChatProvider()->getRecorded();
        self::assertNotEmpty($records);
        $record = $records[array_key_last($records)];
        self::assertInstanceOf(RequestRecord::class, $record);

        return $record;
    }

    private function providerCalls(): int
    {
        return $this->factory()->getChatProvider()->getCallCount();
    }

    private function factory(): FakeProviderFactory
    {
        $factory = static::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);

        return $factory;
    }
}
