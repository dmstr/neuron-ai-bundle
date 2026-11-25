<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The provider payload of a message (group `message:payload`) is visible on
 * `GET /messages/{id}` to holders of `neuronai:conversation:read_payload` only
 * (default `ROLE_ADMIN`), never in a list and never in the turn response,
 * also not for a holder.
 *
 * Relies on `X-Test-User` and the firewall rows like {@see AccessTest},
 * whose fixtures and helpers it uses. Needs the migrated test database;
 * runs in CI.
 */
#[Group('database')]
final class PayloadPermissionTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        AccessTest::cleanUp();
    }

    protected function tearDown(): void
    {
        AccessTest::cleanUp();
        parent::tearDown();
    }

    public function testOwnerDoesNotSeeThePayload(): void
    {
        $conversation = AccessTest::conversation(AccessTest::USER_A);
        $message = AccessTest::message($conversation, 1);

        $data = $this->get('/api/messages/'.$message->getId(), AccessTest::USER_A)->toArray();

        self::assertSame('Nachricht 1', $data['content']);
        self::assertArrayNotHasKey('providerPayload', $data);
    }

    public function testAdminWithoutThePermissionDoesNotSeeThePayload(): void
    {
        $conversation = AccessTest::conversation(AccessTest::STAFF);
        $message = AccessTest::message($conversation, 1);

        $data = $this->get('/api/messages/'.$message->getId(), AccessTest::STAFF)->toArray();

        self::assertArrayNotHasKey('providerPayload', $data);
    }

    public function testHolderSeesThePayloadOnTheItem(): void
    {
        $conversation = AccessTest::conversation(AccessTest::USER_A);
        $message = AccessTest::message($conversation, 1);

        $data = $this->get('/api/messages/'.$message->getId(), AccessTest::ADMIN)->toArray();

        self::assertSame(['role' => Message::SPEAKER_USER, 'content' => [['type' => 'text', 'text' => 'Nachricht 1']]], $data['providerPayload'] ?? null);
    }

    public function testHolderDoesNotSeeThePayloadInLists(): void
    {
        $conversation = AccessTest::conversation(AccessTest::USER_A);
        AccessTest::message($conversation, 1);
        AccessTest::message($conversation, 2, Message::SPEAKER_ASSISTANT);

        foreach ([
            '/api/messages?conversation='.urlencode(AccessTest::iri($conversation)),
            AccessTest::iri($conversation).'/messages',
        ] as $uri) {
            $members = $this->get($uri, AccessTest::ADMIN)->toArray()['hydra:member'] ?? [];
            self::assertCount(2, $members, $uri);
            foreach ($members as $member) {
                self::assertArrayNotHasKey('providerPayload', $member, $uri);
            }
        }
    }

    public function testHolderDoesNotSeeThePayloadInTheTurnResponse(): void
    {
        $conversation = AccessTest::conversation(AccessTest::ADMIN);

        $client = static::createClient();
        $client->disableReboot();
        AccessTest::providerFactory()->queueAnswer('Hallo zurück');
        $response = AccessTest::send($client, 'POST', AccessTest::iri($conversation).'/messages', AccessTest::ADMIN, ['content' => 'Hallo']);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame('Hallo zurück', $data['content']);
        self::assertArrayNotHasKey('providerPayload', $data);
    }

    private function get(string $uri, string $identifier): ResponseInterface
    {
        $response = AccessTest::send(static::createClient(), 'GET', $uri, $identifier);
        self::assertResponseIsSuccessful();

        return $response;
    }
}
