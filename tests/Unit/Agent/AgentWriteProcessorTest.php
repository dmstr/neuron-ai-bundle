<?php

// file generated with AI assistance: Claude Code - 2026-10-06 19:10:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\State\AgentWriteProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The renamed key `draft` is refused with 422 on POST and PATCH of an
 * agent; every other body reaches the persist processor. A POST
 * fills the missing top-level keys of Agent::NEW_AGENT_DEFAULTS.
 */
final class AgentWriteProcessorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function draftBodies(): iterable
    {
        yield 'only draft' => ['{"draft": {"model": "llama3.2"}}'];
        yield 'draft next to candidate' => ['{"name": "A", "candidate": {}, "draft": {}}'];
        yield 'null draft' => ['{"draft": null}'];
    }

    #[DataProvider('draftBodies')]
    public function testDraftKeyIsRefused(string $body): void
    {
        $persist = $this->createMock(ProcessorInterface::class);
        $persist->expects(self::never())->method('process');

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('"draft" was renamed to "candidate"');

        new AgentWriteProcessor($persist)->process(new Agent(), new Post(), [], ['request' => self::request($body)]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherBodies(): iterable
    {
        yield 'candidate' => ['{"name": "A", "slug": "a", "candidate": {"model": "llama3.2"}}'];
        yield 'draft only inside the candidate text' => ['{"candidate": {"instructions": {"de": "draft"}}}'];
        yield 'empty body' => [''];
        yield 'malformed json' => ['{'];
    }

    #[DataProvider('otherBodies')]
    public function testOtherBodiesArePersisted(string $body): void
    {
        $agent = new Agent();
        $persist = $this->createMock(ProcessorInterface::class);
        $persist->expects(self::once())->method('process')->willReturn($agent);

        self::assertSame($agent, new AgentWriteProcessor($persist)->process($agent, new Patch(), [], ['request' => self::request($body)]));
    }

    public function testWithoutRequestTheDataIsPersisted(): void
    {
        $agent = new Agent();
        $persist = $this->createMock(ProcessorInterface::class);
        $persist->expects(self::once())->method('process')->willReturn($agent);

        self::assertSame($agent, new AgentWriteProcessor($persist)->process($agent, new Post()));
    }

    public function testPostAddsTheNewAgentDefaultsTheCandidateLacks(): void
    {
        $agent = new Agent()->setCandidate(['model' => 'llama3.2', 'hybrid' => false, 'modelOptions' => ['temperature' => 0.2]]);
        $persist = $this->createMock(ProcessorInterface::class);
        $persist->expects(self::once())->method('process')->willReturn($agent);

        new AgentWriteProcessor($persist)->process($agent, new Post());

        self::assertSame(['model' => 'llama3.2', 'hybrid' => false, 'modelOptions' => ['temperature' => 0.2], 'hybridWeight' => 0.3], $agent->getCandidate());
    }

    public function testPatchKeepsTheCandidateAsSent(): void
    {
        $agent = new Agent()->setCandidate(['model' => 'llama3.2']);
        $persist = $this->createMock(ProcessorInterface::class);
        $persist->expects(self::once())->method('process')->willReturn($agent);

        new AgentWriteProcessor($persist)->process($agent, new Patch());

        self::assertSame(['model' => 'llama3.2'], $agent->getCandidate());
    }

    private static function request(string $body): Request
    {
        return Request::create('/api/agents', 'POST', content: $body);
    }
}

// - revised 2026-10-20
