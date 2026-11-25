<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:09:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicy;
use Dmstr\NeuronAiBundle\Anonymous\ConversationCloser;
use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\TurnPersistence;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnResult;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Stream\StreamEventStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Kernel-free: the limit arithmetic of {@see TurnPersistence} — the user-turn
 * limit of the next run, the tool-call bound of agent conversations, the
 * gate on a used-up `maxUserTurns` and the close when counting a turn
 * reaches it (also against the anonymous policy). The entity manager is a
 * stub that runs transactions inline; nothing is persisted.
 */
final class TurnLimitArithmeticTest extends TestCase
{
    /** @var int transactions the entity manager stub ran */
    private int $transactions = 0;

    private function persistence(?int $settingsMaxToolCalls = null, ?int $bundleMaxToolCalls = null): TurnPersistence
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(function (callable $func): mixed {
            ++$this->transactions;

            return $func();
        });
        $connection = $this->createStub(Connection::class);

        $defaults = new class($settingsMaxToolCalls) implements AgentLimitDefaultsInterface {
            public function __construct(private readonly ?int $maxToolCalls)
            {
            }

            public function maxToolCalls(): ?int
            {
                return $this->maxToolCalls;
            }

            public function maxUserTurns(): ?int
            {
                return null;
            }
        };

        return new TurnPersistence(
            $entityManager,
            $connection,
            new StreamEventStore($connection),
            new ConversationCloser(),
            new MockClock('2026-10-03 08:00:00'),
            limitDefaults: $defaults,
            maxToolCallsDefault: $bundleMaxToolCalls,
        );
    }

    private static function conversation(?int $maxUserTurns, int $turnCount, ?int $versionMaxToolCalls = null, bool $withAgent = false): Conversation
    {
        $conversation = new Conversation()
            ->setStatus(ConversationStatus::ACTIVE)
            ->setMaxUserTurns($maxUserTurns)
            ->setTurnCount($turnCount);
        if ($withAgent) {
            $agent = new Agent()->setName('Agent')->setSlug('agent');
            $conversation->setAgentVersion(new AgentVersion($agent, 1, ['maxToolCalls' => $versionMaxToolCalls] + Agent::candidateDefaults(), 'test-admin'));
        }

        return $conversation;
    }

    private static function turnResult(): TurnResult
    {
        return new TurnResult(new TurnRequest('fake', 'fake-model', 'hello'), '', [], [], []);
    }

    private static function policy(int $maxTurns): AnonymousAccessPolicy
    {
        return new AnonymousAccessPolicy([], [], null, 30, 10, $maxTurns, 1_000_000, 7);
    }

    /**
     * @return iterable<string, array{0: ?int, 1: int, 2: ?array{0: int, 1: int}}>
     */
    public static function userTurnLimits(): iterable
    {
        // maxUserTurns, turnCount => expected [max, turn] or null
        yield 'unbounded' => [null, 5, null];
        yield 'turns left after the next one' => [3, 1, null];
        yield 'the next turn is the last' => [3, 2, [3, 3]];
        yield 'limit 1 forces the first turn' => [1, 0, [1, 1]];
        yield 'beyond the limit is still forced' => [2, 4, [2, 5]];
    }

    /**
     * @param array{0: int, 1: int}|null $expected
     */
    #[DataProvider('userTurnLimits')]
    public function testUserTurnLimitOfTheNextTurn(?int $maxUserTurns, int $turnCount, ?array $expected): void
    {
        $limit = $this->persistence()->userTurnLimitFor(self::conversation($maxUserTurns, $turnCount));

        self::assertSame($expected, null === $limit ? null : [$limit->max, $limit->turn]);
    }

    /**
     * @return iterable<string, array{0: bool, 1: ?int, 2: ?int, 3: ?int, 4: ?int, 5: ?int}>
     */
    public static function toolCallBounds(): iterable
    {
        // agent, version, settings, bundle, request => expected
        yield 'no agent: the request passes unchanged above the bundle default' => [false, null, 3, 2, 5, 5];
        yield 'no agent: null stays null (runner default)' => [false, null, 3, 2, null, null];
        yield 'agent: the request does not raise the version' => [true, 2, null, null, 5, 2];
        yield 'agent: the request lowers the version' => [true, 4, null, null, 1, 1];
        yield 'agent: the version without a request' => [true, 2, 7, 9, null, 2];
        yield 'agent: the settings default when the version has none' => [true, null, 3, 9, 5, 3];
        yield 'agent: the bundle default when version and settings have none' => [true, null, null, 2, 5, 2];
        yield 'agent: unbounded everywhere, the request applies' => [true, null, null, null, 5, 5];
        yield 'agent: unbounded everywhere and no request' => [true, null, null, null, null, null];
    }

    #[DataProvider('toolCallBounds')]
    public function testToolCallBound(bool $withAgent, ?int $version, ?int $settings, ?int $bundle, ?int $request, ?int $expected): void
    {
        $conversation = self::conversation(null, 0, $version, $withAgent);

        self::assertSame($expected, $this->persistence($settings, $bundle)->maxToolCallsFor($conversation, $request));
    }

    public function testGateClosesAConversationWhoseUserTurnsAreUsedUp(): void
    {
        $conversation = self::conversation(2, 2);

        try {
            $this->persistence()->gate($conversation, null);
            self::fail('The gate let a turn beyond maxUserTurns through.');
        } catch (ConversationLimitException $e) {
            self::assertSame(ConversationLimitException::CODE_USER_TURN_LIMIT, $e->getErrorCode());
            self::assertSame('/conversation_errors/user_turn_limit', $e->getType());
            self::assertSame('The conversation reached its user-turn limit.', $e->getTitle());
            self::assertSame('The conversation reached its user-turn limit. It accepts no further turn.', $e->getDetail());
        }

        self::assertSame(1, $this->transactions);
        self::assertSame(ConversationStatus::CLOSED, $conversation->getStatus());
        self::assertSame(Conversation::CLOSE_REASON_USER_TURN_LIMIT, $conversation->getCloseReason());
    }

    public function testGatePassesWithTurnsLeftOrWithoutLimit(): void
    {
        $persistence = $this->persistence();

        self::assertNull($persistence->gate(self::conversation(3, 2), null));
        self::assertNull($persistence->gate(self::conversation(null, 50), null));
        self::assertSame(0, $this->transactions);
    }

    /**
     * @return iterable<string, array{0: ?int, 1: int, 2: ?int, 3: ?string}>
     */
    public static function countedTurns(): iterable
    {
        // maxUserTurns, turnCount before, anonymous maxTurns (null = not anonymous) => close reason
        yield 'last allowed turn closes' => [3, 2, null, Conversation::CLOSE_REASON_USER_TURN_LIMIT];
        yield 'turns left stays open' => [3, 1, null, null];
        yield 'no limit stays open' => [null, 99, null, null];
        yield 'tie with the anonymous turn limit is user_turn_limit' => [3, 2, 3, Conversation::CLOSE_REASON_USER_TURN_LIMIT];
        yield 'smaller than the anonymous turn limit is user_turn_limit' => [2, 1, 5, Conversation::CLOSE_REASON_USER_TURN_LIMIT];
        yield 'larger than the anonymous turn limit is turn_limit' => [5, 2, 3, Conversation::CLOSE_REASON_TURN_LIMIT];
        yield 'anonymous without maxUserTurns is turn_limit' => [null, 2, 3, Conversation::CLOSE_REASON_TURN_LIMIT];
    }

    #[DataProvider('countedTurns')]
    public function testCountingTheTurnCloses(?int $maxUserTurns, int $turnCount, ?int $anonymousMaxTurns, ?string $expectedReason): void
    {
        $conversation = self::conversation($maxUserTurns, $turnCount);
        $policy = null === $anonymousMaxTurns ? null : self::policy($anonymousMaxTurns);

        $this->persistence()->append($conversation, [], result: self::turnResult(), policy: $policy);

        self::assertSame($turnCount + 1, $conversation->getTurnCount());
        self::assertSame($expectedReason, $conversation->getCloseReason());
        self::assertSame(null === $expectedReason ? ConversationStatus::ACTIVE : ConversationStatus::CLOSED, $conversation->getStatus());
    }
}
