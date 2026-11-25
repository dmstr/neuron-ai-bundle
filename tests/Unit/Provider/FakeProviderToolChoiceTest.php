<?php

// file generated with AI assistance: Claude Code - 2026-10-02 05:54:24 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Provider;

use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Provider\ToolChoice;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Testing\RecordingFakeAIProvider;
use Dmstr\NeuronAiBundle\Testing\ToolChoiceRequestRecord;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use PHPUnit\Framework\TestCase;

/**
 * The bundle fakes as tool choice aware providers: records with the
 * choice at call time, declining (every choice, or only the forcing
 * ones), and the per-provider default of the fake factory.
 */
final class FakeProviderToolChoiceTest extends TestCase
{
    public function testChatAndStreamRecordTheChoiceAtCallTime(): void
    {
        $provider = new RecordingFakeAIProvider()->queueAnswer('one')->queueAnswer('two')->queueAnswer('three');

        $provider->chat(new UserMessage('a'));
        $provider->setToolChoice(ToolChoice::none())->chat(new UserMessage('b'));
        $provider->setToolChoice(ToolChoice::tool('search'));
        foreach ($provider->stream(new UserMessage('c')) as $chunk) {
        }

        $records = $provider->getRecorded();
        self::assertCount(3, $records);
        self::assertContainsOnlyInstancesOf(ToolChoiceRequestRecord::class, $records);
        self::assertSame(['chat', 'chat', 'stream'], array_map(static fn ($record): string => $record->method, $records));
        self::assertNull($records[0]->toolChoice);
        self::assertTrue(ToolChoice::none()->equals($records[1]->toolChoice));
        self::assertTrue(ToolChoice::tool('search')->equals($records[2]->toolChoice));
    }

    public function testFailedCallIsNotRecorded(): void
    {
        $provider = new RecordingFakeAIProvider()->queueFailure('down')->queueAnswer('ok');
        $provider->setToolChoice(ToolChoice::none());

        try {
            $provider->chat(new UserMessage('a'));
            self::fail('Expected a ProviderException.');
        } catch (ProviderException) {
        }
        $provider->chat(new UserMessage('b'));

        $provider->assertCallCount(1);
        self::assertInstanceOf(ToolChoiceRequestRecord::class, $provider->getRecorded()[0]);
        self::assertSame('b', $provider->getRecorded()[0]->messages[0]->getContent());
    }

    public function testDeclineFlipsSupport(): void
    {
        $provider = new RecordingFakeAIProvider();
        self::assertTrue($provider->supportsToolChoice(ToolChoice::any()));
        self::assertTrue($provider->supportsToolChoice(ToolChoice::none()));

        $provider->declineToolChoice();
        self::assertFalse($provider->supportsToolChoice(ToolChoice::any()));
        self::assertFalse($provider->supportsToolChoice(ToolChoice::none()));

        $provider->declineToolChoice(false);
        self::assertTrue($provider->supportsToolChoice(ToolChoice::auto()));
    }

    public function testDecliningForcingChoicesKeepsAutoAndNone(): void
    {
        $provider = new RecordingFakeAIProvider()->declineForcingChoices();
        self::assertTrue($provider->supportsToolChoice(ToolChoice::auto()));
        self::assertTrue($provider->supportsToolChoice(ToolChoice::none()));
        self::assertFalse($provider->supportsToolChoice(ToolChoice::any()));
        self::assertFalse($provider->supportsToolChoice(ToolChoice::tool('search')));

        // Declining every choice wins.
        $provider->declineToolChoice();
        self::assertFalse($provider->supportsToolChoice(ToolChoice::none()));
        $provider->declineToolChoice(false);

        $provider->declineForcingChoices(false);
        self::assertTrue($provider->supportsToolChoice(ToolChoice::tool('search')));
    }

    public function testFactoryDeclinesForcingChoicesOnOverride(): void
    {
        $factory = new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));

        $factory->declineForcingChoices();
        $anthropic = $factory->chatProvider(ProviderConnectionFixtures::anthropic(), FakeProviderFactory::ANTHROPIC_MODEL);
        self::assertInstanceOf(RecordingFakeAIProvider::class, $anthropic);
        self::assertTrue($anthropic->supportsToolChoice(ToolChoice::none()));
        self::assertFalse($anthropic->supportsToolChoice(ToolChoice::tool('search')));
        self::assertFalse($anthropic->supportsToolChoice(ToolChoice::any()));

        // Ollama still declines every choice.
        self::assertFalse($factory->chatProvider(ProviderConnectionFixtures::ollama(), FakeProviderFactory::OLLAMA_MODEL)->supportsToolChoice(ToolChoice::none()));

        $factory->declineForcingChoices(false);
        self::assertTrue($factory->chatProvider(ProviderConnectionFixtures::anthropic(), FakeProviderFactory::ANTHROPIC_MODEL)->supportsToolChoice(ToolChoice::tool('search')));
    }

    public function testFactoryDeclinesForOllamaAndOnOverride(): void
    {
        $factory = new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));

        $ollama = $factory->chatProvider(ProviderConnectionFixtures::ollama(), FakeProviderFactory::OLLAMA_MODEL);
        self::assertInstanceOf(RecordingFakeAIProvider::class, $ollama);
        self::assertFalse($ollama->supportsToolChoice(ToolChoice::none()));

        $anthropic = $factory->chatProvider(ProviderConnectionFixtures::anthropic(), FakeProviderFactory::ANTHROPIC_MODEL);
        self::assertSame($ollama, $anthropic);
        self::assertTrue($factory->getChatProvider()->supportsToolChoice(ToolChoice::none()));

        $factory->declineToolChoice();
        $factory->chatProvider(ProviderConnectionFixtures::anthropic(), FakeProviderFactory::ANTHROPIC_MODEL);
        self::assertFalse($factory->getChatProvider()->supportsToolChoice(ToolChoice::none()));

        $factory->declineToolChoice(false);
        $factory->chatProvider(ProviderConnectionFixtures::anthropic(), FakeProviderFactory::ANTHROPIC_MODEL);
        self::assertTrue($factory->getChatProvider()->supportsToolChoice(ToolChoice::any()));
    }
}
