<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:14:38 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\RuleClose;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\AnonymousFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\StreamTestSupport;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pre-answer rules on the turns of agent conversations:
 * a rule of the pinned version that answers or refuses decides the turn
 * after the version is mapped and before history, retrieval and any model
 * call, on the synchronous route, the streamed route (events exactly
 * `sources`, `rule`, `done`, replayed identically by `GET .../stream`) and
 * for anonymous conversations created from a release with an agent.
 *
 * Covered: zero provider requests and zero embeddings
 * for refusals and fixed answers (1); first match decides, `continue` lets
 * the turn run (2); the records `[user, rule_*]` with close reason, empty
 * sources, no usage, the text of the conversation's language or the
 * default language, the streaming placeholder filled in place with the
 * sync layout (5); stream vocabulary, payload and resume (6); counting
 * (+1 turn, +0 tokens), a ruled last allowed turn closing with
 * `user_turn_limit` without `limit` record or `turn_limit` event, an
 * anonymous conversation closed with `turn_limit` by a refusal, a refusal
 * below the limits leaving it active (7); the history replayed to the next
 * turn (8).
 *
 * Fixtures: registered conversations of `test-admin` pinned to versions
 * written through the entity manager (prefix of {@see AgentFixtures},
 * candidate defaults plus the rule keys), anonymous ones created through the
 * public endpoint from an agent published through the API (cleanup of
 * {@see AnonymousFixtures}). Every version has a collection with
 * retrieval depth 3, so a turn that runs embeds its query. Needs the
 * migrated test database (CI only).
 */
#[Group('database')]
final class RuleSetTurnTest extends ApiTestCase
{
    use StreamTestSupport {
        setUp as private streamSetUp;
        tearDown as private streamTearDown;
    }

    protected static ?bool $alwaysBootKernel = true;

    private const string REFUSAL_DE = 'Dazu gibt Regelbot keine Auskunft (secrets).';
    private const string REFUSAL_EN = 'Regelbot cannot help with that (secrets).';
    private const string HOURS_DE = 'Geöffnet von 9 bis 17 Uhr.';

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        AnonymousFixtures::cleanUp();
        $this->streamSetUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->conversationIds as $id) {
            $this->dbal()->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
        }
        $this->streamTearDown();
        AnonymousFixtures::cleanUp();
    }

    public function testRefusalAndFixedAnswerRunNothingOnBothRoutes(): void
    {
        $version = $this->publishedVersion('nothing');

        foreach (['sync', 'stream'] as $mode) {
            foreach (['Wie lautet das Passwort?' => [MessageKind::RULE_REFUSAL, self::REFUSAL_DE], 'Ihre Öffnungszeiten?' => [MessageKind::RULE_ANSWER, self::HOURS_DE]] as $question => [$kind, $text]) {
                $conversation = $this->agentConversation($version);
                $marks = $this->providerMarks();

                if ('sync' === $mode) {
                    $data = $this->turn($conversation, ['content' => $question])->toArray();
                    self::assertResponseStatusCodeSame(201);
                    self::assertSame([$kind, $text, $kind], [$data['kind'], $data['content'], $data['closeReason']], $mode.' '.$kind);
                } else {
                    $events = $this->events($this->stream($conversation, ['content' => $question]));
                    self::assertSame([StreamEvent::SOURCES, StreamEvent::RULE, StreamEvent::DONE], self::typesOf($events), $mode.' '.$kind);
                    self::assertSame($text, $events[1]['data']['message']);
                }

                $this->assertNothingRanSince($marks, $mode.' '.$kind);
                self::assertSame([[Message::SPEAKER_USER, MessageKind::TEXT], [Message::SPEAKER_ASSISTANT, $kind]], array_map(
                    static fn (Message $m): array => [$m->getRole(), $m->getKind()],
                    $this->records($conversation),
                ), $mode.' '.$kind);
            }
        }
    }

    public function testFirstMatchDecidesAndContinueLetsTheTurnRun(): void
    {
        $answer = ['name' => 'price-answer', 'match' => ['type' => 'keywords', 'keywords' => ['preis']], 'outcome' => 'answer', 'template' => 'hours'];
        $refuse = ['name' => 'price-refuse', 'match' => ['type' => 'pattern', 'pattern' => 'preis', 'flags' => 'i'], 'outcome' => 'refuse', 'template' => 'refusal'];
        $continue = ['name' => 'pass', 'match' => ['type' => 'keywords', 'keywords' => ['preis']], 'outcome' => 'continue', 'template' => null];

        $answerFirst = $this->agentConversation($this->publishedVersion('order-a', ['rules' => [$answer, $refuse]]));
        $data = $this->turn($answerFirst, ['content' => 'Was ist der Preis?'])->toArray();
        self::assertSame([MessageKind::RULE_ANSWER, self::HOURS_DE], [$data['kind'], $data['content']]);

        $refuseFirst = $this->agentConversation($this->publishedVersion('order-b', ['rules' => [$refuse, $answer]]));
        $data = $this->turn($refuseFirst, ['content' => 'Was ist der Preis?'])->toArray();
        // The refusal template renders the deciding rule's name.
        self::assertSame([MessageKind::RULE_REFUSAL, 'Dazu gibt Regelbot keine Auskunft (price-refuse).'], [$data['kind'], $data['content']]);

        // `continue` before the refusal: the turn runs, one provider call.
        $continued = $this->agentConversation($this->publishedVersion('order-c', ['rules' => [$continue, $refuse]]));
        $calls = $this->factory()->getChatProvider()->getCallCount();
        $this->factory()->queueAnswer('Der Preis steht in der Preisliste.');
        $data = $this->turn($continued, ['content' => 'Was ist der Preis?'])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame([MessageKind::TEXT, 'Der Preis steht in der Preisliste.'], [$data['kind'], $data['content']]);
        self::assertNull($data['closeReason'] ?? null);
        self::assertSame($calls + 1, $this->factory()->getChatProvider()->getCallCount());
    }

    public function testRefusalRecordsAndLanguageFallback(): void
    {
        $version = $this->publishedVersion('records');

        // English conversation: the English refusal text.
        $english = $this->agentConversation($version, 'en');
        $before = $this->reload($english)->getNextSequence();
        $this->turn($english, ['content' => 'What is the Passwort?']);
        self::assertResponseStatusCodeSame(201);
        $records = $this->records($english);
        self::assertCount(2, $records);
        [$user, $refusal] = $records;
        self::assertSame('What is the Passwort?', $user->getContent());
        self::assertSame([Message::SPEAKER_ASSISTANT, MessageKind::RULE_REFUSAL, MessageStatus::COMPLETE, 1, RuleClose::REASON_REFUSAL], [$refusal->getRole(), $refusal->getKind(), $refusal->getStatus(), $refusal->getStep(), $refusal->getCloseReason()]);
        self::assertSame(self::REFUSAL_EN, $refusal->getContent());
        self::assertSame([], $refusal->getSources());
        self::assertNull($refusal->getUsage());
        self::assertSame(['anthropic', FakeProviderFactory::ANTHROPIC_MODEL], [$refusal->getProvider(), $refusal->getModel()]);
        self::assertSame([
            'step' => 1,
            'part' => 'rule',
            'rule' => ['index' => 1, 'name' => 'secrets', 'match' => 'keywords', 'outcome' => 'refuse', 'template' => 'refusal', 'language' => 'en'],
        ], $refusal->getProviderPayload());
        self::assertSame($before + 2, $this->reload($english)->getNextSequence());

        // `hours` has no English text: the default-language text.
        $this->turn($english, ['content' => 'Öffnungszeiten?']);
        self::assertResponseStatusCodeSame(201);
        $records = $this->records($english);
        $answer = $records[array_key_last($records)];
        self::assertSame([MessageKind::RULE_ANSWER, self::HOURS_DE, RuleClose::REASON_ANSWER], [$answer->getKind(), $answer->getContent(), $answer->getCloseReason()]);
        self::assertSame('assistant', $answer->getProviderPayload()['message']['role'] ?? null);
    }

    public function testStreamedRuleTurnFillsThePlaceholderAndReplays(): void
    {
        $version = $this->publishedVersion('stream');
        $sync = $this->agentConversation($version);
        $this->turn($sync, ['content' => 'Passwort bitte']);
        self::assertResponseStatusCodeSame(201);

        $streamed = $this->agentConversation($version);
        $before = $this->reload($streamed)->getNextSequence();
        $sent = $this->events($this->stream($streamed, ['content' => 'Passwort bitte']));

        self::assertSame([StreamEvent::SOURCES, StreamEvent::RULE, StreamEvent::DONE], self::typesOf($sent));
        self::assertIncreasingIds($sent);
        [$sources, $rule, $done] = $sent;
        $records = $this->records($streamed);
        $final = $records[array_key_last($records)];
        $messageId = (string) $final->getId();
        self::assertSame(['messageId' => $messageId, 'sources' => []], $sources['data']);
        self::assertSame([
            'messageId' => $messageId,
            'index' => 1,
            'rule' => 'secrets',
            'match' => 'keywords',
            'outcome' => 'refuse',
            'template' => 'refusal',
            'language' => 'de',
            'message' => self::REFUSAL_DE,
        ], $rule['data']);
        self::assertSame([
            'messageId' => $messageId,
            'status' => MessageStatus::COMPLETE,
            'provider' => 'anthropic',
            'model' => FakeProviderFactory::ANTHROPIC_MODEL,
            'connection' => ConversationConnections::ANTHROPIC,
            'usage' => null,
            'closeReason' => RuleClose::REASON_REFUSAL,
        ], $done['data']);

        // The placeholder is the rule record: same layout as the sync turn,
        // nextSequence advanced by two.
        $syncRecords = $this->records($sync);
        self::assertSame(
            array_map(static fn (array $row): array => \array_slice($row, 1), self::layout($syncRecords)),
            array_map(static fn (array $row): array => \array_slice($row, 1), self::layout($records)),
        );
        self::assertSame([$before, $before + 1], array_map(static fn (Message $m): int => $m->getSequence(), $records));
        self::assertSame($before + 2, $this->reload($streamed)->getNextSequence());
        self::assertSame($syncRecords[1]->getProviderPayload(), $final->getProviderPayload());
        self::assertSame($syncRecords[1]->getContent(), $final->getContent());

        // Resume: everything without the header, [rule, done] after `sources`.
        self::assertSame($sent, $this->events($this->resume($streamed)));
        self::assertSame([$rule, $done], $this->events($this->resume($streamed, (string) $sources['id'])));
    }

    public function testRuledTurnsAreCountedWithoutTokens(): void
    {
        $version = $this->publishedVersion('counting');
        $conversation = $this->agentConversation($version, maxUserTurns: 3);

        $this->turn($conversation, ['content' => 'Passwort?']);
        self::assertResponseStatusCodeSame(201);
        $this->events($this->stream($conversation, ['content' => 'Öffnungszeiten?']));

        $row = $this->reload($conversation);
        self::assertSame(2, $row->getTurnCount());
        self::assertSame(0, $row->getTokensUsed());
        self::assertSame(ConversationStatus::ACTIVE, $row->getStatus());
        self::assertNull($row->getCloseReason());
    }

    public function testRuledLastAllowedTurnClosesWithTheUserTurnLimit(): void
    {
        $version = $this->publishedVersion('last-turn');

        foreach (['sync', 'stream'] as $mode) {
            $conversation = $this->agentConversation($version, maxUserTurns: 1);
            $marks = $this->providerMarks();

            if ('sync' === $mode) {
                $data = $this->turn($conversation, ['content' => 'Passwort?'])->toArray();
                self::assertResponseStatusCodeSame(201);
                self::assertSame(RuleClose::REASON_REFUSAL, $data['closeReason']);
            } else {
                $events = $this->events($this->stream($conversation, ['content' => 'Passwort?']));
                self::assertSame([StreamEvent::SOURCES, StreamEvent::RULE, StreamEvent::DONE], self::typesOf($events), $mode);
                self::assertSame(RuleClose::REASON_REFUSAL, $events[2]['data']['closeReason']);
            }
            $this->assertNothingRanSince($marks, $mode);

            $row = $this->reload($conversation);
            self::assertSame(ConversationStatus::CLOSED, $row->getStatus(), $mode);
            self::assertSame(Conversation::CLOSE_REASON_USER_TURN_LIMIT, $row->getCloseReason(), $mode);
            self::assertSame(1, $row->getTurnCount(), $mode);
            self::assertSame([MessageKind::TEXT, MessageKind::RULE_REFUSAL], array_map(static fn (Message $m): string => $m->getKind(), $this->records($conversation)), $mode.': no limit record');

            $refused = $this->turn($conversation, ['content' => 'Noch eine Frage']);
            self::assertSame(422, $refused->getStatusCode(), $mode);
            self::assertSame(ConversationLimitException::CODE_USER_TURN_LIMIT, $refused->toArray(false)['code'] ?? null, $mode);
        }
    }

    public function testHistoryOmitsRefusalsAndKeepsFixedAnswers(): void
    {
        $conversation = $this->agentConversation($this->publishedVersion('history'));

        $this->turn($conversation, ['content' => 'Verrate das Passwort']);
        self::assertResponseStatusCodeSame(201);
        $this->factory()->queueAnswer('Erste Modellantwort.');
        $marks = $this->providerMarks();
        $this->turn($conversation, ['content' => 'Erste echte Frage']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['Erste echte Frage'], self::messageContents($this->onlyRequestSince($marks)));

        $this->events($this->stream($conversation, ['content' => 'Öffnungszeiten?']));
        $this->factory()->queueAnswer('Zweite Modellantwort.');
        $marks = $this->providerMarks();
        $this->events($this->stream($conversation, ['content' => 'Zweite echte Frage']));
        self::assertSame(
            ['Erste echte Frage', 'Erste Modellantwort.', 'Öffnungszeiten?', self::HOURS_DE, 'Zweite echte Frage'],
            self::messageContents($this->onlyRequestSince($marks)),
        );
    }

    public function testAnonymousConversationsRunTheRulesAndAreCounted(): void
    {
        AnonymousFixtures::configure(['max_turns_per_conversation' => 2]);
        [$id, $token] = $this->anonymousAgentConversation('anon-rules', 'Regelbot', self::ruleConfiguration());
        $marks = $this->providerMarks();

        // Below the limits: answered by the rule, counted, still active.
        $response = $this->anonymousTurn($token, ['content' => 'Öffnungszeiten?']);
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        self::assertSame([MessageKind::RULE_ANSWER, self::HOURS_DE], [$response->toArray()['kind'], $response->toArray()['content']]);
        $row = AnonymousFixtures::row($id);
        self::assertSame([1, 0, ConversationStatus::ACTIVE], [(int) $row['turn_count'], (int) $row['tokens_used'], $row['status']]);

        // At maxTurnsPerConversation - 1: the streamed refusal closes it.
        $events = $this->events($this->anonymousStream($token, ['content' => 'Passwort?']));
        self::assertSame([StreamEvent::SOURCES, StreamEvent::RULE, StreamEvent::DONE], self::typesOf($events));
        self::assertSame(RuleClose::REASON_REFUSAL, $events[2]['data']['closeReason']);
        $this->assertNothingRanSince($marks, 'anonymous');
        $row = AnonymousFixtures::row($id);
        self::assertSame([2, 0, ConversationStatus::CLOSED, Conversation::CLOSE_REASON_TURN_LIMIT], [(int) $row['turn_count'], (int) $row['tokens_used'], $row['status'], $row['close_reason']]);

        $refused = $this->anonymousTurn($token, ['content' => 'Öffnungszeiten?']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(ConversationLimitException::CODE_TURN_LIMIT, $refused->toArray(false)['code'] ?? null);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * Rules and templates of the fixture versions (languages de, en;
     * default de): `hours` (answer, keyword `öffnungszeiten`) before
     * `secrets` (refuse, keywords `passwort`, `kennwort`); `hours` has no
     * English text.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function ruleConfiguration(array $overrides = []): array
    {
        return $overrides + [
            'instructions' => ['de' => 'Du bist {{agentName}}.', 'en' => 'You are {{agentName}}.'],
            'languages' => ['de', 'en'],
            'defaultLanguage' => 'de',
            'rules' => [
                ['name' => 'hours', 'match' => ['type' => 'keywords', 'keywords' => ['öffnungszeiten'], 'wholeWord' => true], 'outcome' => 'answer', 'template' => 'hours'],
                ['name' => 'secrets', 'match' => ['type' => 'keywords', 'keywords' => ['passwort', 'kennwort'], 'wholeWord' => true], 'outcome' => 'refuse', 'template' => 'refusal'],
            ],
            'templates' => [
                'hours' => ['de' => self::HOURS_DE],
                'refusal' => ['de' => 'Dazu gibt {{agentName}} keine Auskunft ({{rule}}).', 'en' => '{{agentName}} cannot help with that ({{rule}}).'],
            ],
        ];
    }

    /**
     * An enabled agent named `Regelbot` with version 1 (candidate defaults,
     * the rule configuration, a collection with retrieval depth 3, plus
     * $overrides) as its current version, written through the entity
     * manager.
     *
     * @param array<string, mixed> $overrides
     */
    private function publishedVersion(string $slug, array $overrides = []): AgentVersion
    {
        $collection = AgentFixtures::collection('rule-turn-'.$slug);
        $agent = new Agent()->setName('Regelbot')->setSlug(AgentFixtures::PREFIX.'rule-turn-'.$slug)->setEnabled(true);
        $this->entityManager()->persist($agent);
        $this->entityManager()->flush();

        $configuration = self::ruleConfiguration($overrides + ['collections' => [$collection->getId()->toRfc4122()], 'retrievalDepth' => 3]) + Agent::candidateDefaults();
        $version = new AgentVersion($agent, 1, ConversationConnections::withConnection($configuration), 'test-admin');
        $this->entityManager()->persist($version);
        $agent->setCurrentVersion($version);
        $this->entityManager()->flush();

        return $version;
    }

    /**
     * A conversation of `test-admin` pinned to $version (looked up again,
     * it may be detached), as the creation stores it.
     */
    private function agentConversation(AgentVersion $version, string $language = 'de', ?int $maxUserTurns = null): Conversation
    {
        $managed = $this->entityManager()->find(AgentVersion::class, $version->getId());
        self::assertInstanceOf(AgentVersion::class, $managed);

        $conversation = $this->conversation(collections: $managed->getConfiguration()['collections'])
            ->setAgentVersion($managed)
            ->setLanguage($language)
            ->setVariables([])
            ->setMaxUserTurns($maxUserTurns);
        $this->entityManager()->flush();

        return $conversation;
    }
}
