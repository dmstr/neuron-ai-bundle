<?php

// file generated with AI assistance: Claude Code - 2026-10-05 19:33:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ProviderConnection;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\ProviderModelFixtureTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The delete guard for conversations: deleting a provider
 * connection that conversations run on answers 409 naming them by IRI
 * (never title or owner), through the API and through the EntityManager;
 * with more than ten conversations nine are listed and the rest counted;
 * once the conversations are gone the connection can be deleted (204). A
 * conversation is found through its model catalogue entry
 * (`provider_model_id`, NOT NULL, which the delete would cascade away
 * against the conversation's key: the guard answers 409 before the
 * database could report a foreign key violation) as well as through
 * `provider_connection_id`. Every conversation has an entry of its
 * connection.
 *
 * Needs the migrated test database (CI only). Connections carry the name
 * prefix `test-guard-conv-`, conversations the owner prefix
 * `test-guard-conv-`; conversations are removed before the connections.
 */
#[Group('database')]
final class ProviderConnectionDeleteGuardConversationTest extends ApiTestCase
{
    use ProviderModelFixtureTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-guard-conv-';

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public function testConnectionOfAConversationIsNotDeletedThroughTheApi(): void
    {
        $client = static::createClient();
        $connection = ProviderConnectionFixtures::ollama(self::PREFIX.'local');
        ProviderConnectionFixtures::persist(self::entityManager(), $connection);
        $conversation = self::conversation($connection);

        $response = $client->request('DELETE', '/api/admin/api_configurations/'.$connection->getId()->toRfc4122(), ['headers' => ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(409);
        $detail = (string) ($response->toArray(false)['detail'] ?? '');
        self::assertStringContainsString('Provider connection "'.self::PREFIX.'local" is still referenced by conversation /api/conversations/'.$conversation->getId()->toRfc4122(), $detail);
        self::assertStringNotContainsString(self::PREFIX.'owner', $detail, 'no owner in the message');
        self::assertSame(1, self::connectionCount());

        // Without the conversation the connection goes.
        self::dbal()->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $conversation->getId()->toRfc4122()]);
        $client->request('DELETE', '/api/admin/api_configurations/'.$connection->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, self::connectionCount());
    }

    public function testConversationIsFoundThroughItsCatalogueEntry(): void
    {
        $client = static::createClient();
        $connection = ProviderConnectionFixtures::ollama(self::PREFIX.'entry');
        ProviderConnectionFixtures::persist(self::entityManager(), $connection);
        $entry = self::catalogueModel(self::entityManager(), $connection, FakeProviderFactory::OLLAMA_MODEL);
        $conversation = new Conversation()
            ->setOwnerSubject(self::PREFIX.'owner')
            ->setProviderModel($entry)
            ->setRetrievalDepth(4);
        self::entityManager()->persist($conversation);
        self::entityManager()->flush();
        $id = $conversation->getId()->toRfc4122();
        self::assertSame($entry->getId()->toRfc4122(), (string) self::dbal()->fetchOne('SELECT provider_model_id FROM neuron_conversation WHERE id = :id', ['id' => $id]));
        // Only the entry references the connection now.
        self::dbal()->executeStatement('UPDATE neuron_conversation SET provider_connection_id = NULL WHERE id = :id', ['id' => $id]);

        $response = $client->request('DELETE', '/api/admin/api_configurations/'.$connection->getId()->toRfc4122(), ['headers' => ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('is still referenced by conversation /api/conversations/'.$id, (string) ($response->toArray(false)['detail'] ?? ''));
        self::assertSame(1, self::connectionCount());

        // Without the conversation the connection goes, and its entry with it.
        self::dbal()->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $id]);
        $client->request('DELETE', '/api/admin/api_configurations/'.$connection->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, self::connectionCount());
        self::assertSame(0, (int) self::dbal()->fetchOne('SELECT COUNT(*) FROM neuron_provider_model WHERE id = :id', ['id' => $entry->getId()->toRfc4122()]));
    }

    public function testEntityManagerRemovalListsNineConversationsAndCountsTheRest(): void
    {
        static::bootKernel();
        $connection = ProviderConnectionFixtures::anthropic(self::PREFIX.'claude');
        ProviderConnectionFixtures::persist(self::entityManager(), $connection);
        for ($i = 0; $i < 12; ++$i) {
            self::conversation($connection);
        }

        try {
            self::entityManager()->remove($connection);
            self::fail('Expected a ConflictHttpException.');
        } catch (ConflictHttpException $e) {
            self::assertSame(9, substr_count($e->getMessage(), 'conversation /api/conversations/'));
            self::assertStringContainsString('; 3 more conversations.', $e->getMessage());
        }

        self::entityManager()->clear();
        self::assertSame(1, self::connectionCount());
    }

    private static function conversation(ApiConfiguration $connection): Conversation
    {
        $conversation = new Conversation()
            ->setOwnerSubject(self::PREFIX.'owner')
            ->setTitle('Vertraulicher Titel')
            ->setProviderModel(self::catalogueModel(self::entityManager(), $connection, FakeProviderFactory::OLLAMA_MODEL))
            ->setRetrievalDepth(4);
        self::entityManager()->persist($conversation);
        self::entityManager()->flush();

        return $conversation;
    }

    private static function connectionCount(): int
    {
        return (int) self::dbal()->fetchOne('SELECT COUNT(*) FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    private static function cleanUp(): void
    {
        $database = self::dbal();
        $prefix = ['prefix' => self::PREFIX.'%'];
        $database->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id IN (SELECT CAST(id AS TEXT) FROM neuron_conversation WHERE owner_subject LIKE :prefix)', ['class' => Conversation::class] + $prefix);
        $database->executeStatement('DELETE FROM neuron_conversation WHERE owner_subject LIKE :prefix', $prefix);
        $database->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', $prefix);
        static::ensureKernelShutdown();
    }

    private static function entityManager(): EntityManagerInterface
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private static function dbal(): Connection
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
