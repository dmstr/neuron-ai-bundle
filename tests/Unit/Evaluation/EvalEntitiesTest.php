<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:38:08 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation;

use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\Evaluation\RetrievalMeasurement;
use Dmstr\NeuronAiBundle\Evaluation\RetrievalStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free test of the evaluation model: the status and
 * source vocabularies other parts rely on, the defaults of new entities,
 * the fluent setters and `isActive()`, the `origin` of conversations and
 * the retrieval fields (run aggregates, `applyRetrieval()`).
 */
final class EvalEntitiesTest extends TestCase
{
    public function testVocabularies(): void
    {
        self::assertSame(['active', 'archived'], EvalQuestionStatus::ALL);
        self::assertSame(['pending', 'running', 'completed', 'cancelled', 'failed'], EvalRunStatus::ALL);
        self::assertSame(['pending', 'completed', 'failed'], EvalResultStatus::ALL);
        self::assertSame(['batch', 'single'], EvalResultSource::ALL);

        self::assertSame(EvalQuestionStatus::ALL, EvalQuestionStatus::all());
        self::assertSame(EvalRunStatus::ALL, EvalRunStatus::all());
        self::assertSame(EvalResultStatus::ALL, EvalResultStatus::all());
        self::assertSame(EvalResultSource::ALL, EvalResultSource::all());
    }

    public function testQuestionDefaults(): void
    {
        $question = new EvalQuestion();

        self::assertInstanceOf(Uuid::class, $question->getId());
        self::assertNotEquals((string) $question->getId(), (string) (new EvalQuestion())->getId());
        self::assertSame(EvalQuestionStatus::ACTIVE, $question->getStatus());
        self::assertTrue($question->isActive());
        self::assertSame('', $question->getKey());
        self::assertNull($question->getRequestData());
        self::assertNull($question->getMarkers());
        self::assertNull($question->isNegative());
        self::assertLessThanOrEqual($question->getUpdatedAt(), $question->getCreatedAt());
    }

    public function testQuestionSettersAndArchiving(): void
    {
        $question = (new EvalQuestion())
            ->setKey('support.opening-hours')
            ->setQuestion('When are you open?')
            ->setExpectedAnswer('Monday to Friday, 9 to 17.')
            ->setRequestData(['customer' => 'ACME'])
            ->setMarkers(['opening'])
            ->setNegative(false)
            ->setStatus(EvalQuestionStatus::ARCHIVED);

        self::assertSame('support.opening-hours', $question->getKey());
        self::assertSame('When are you open?', $question->getQuestion());
        self::assertSame('Monday to Friday, 9 to 17.', $question->getExpectedAnswer());
        self::assertSame(['customer' => 'ACME'], $question->getRequestData());
        self::assertSame(['opening'], $question->getMarkers());
        self::assertFalse($question->isNegative());
        self::assertFalse($question->isActive());
    }

    public function testQuestionKeyPattern(): void
    {
        foreach (['a', 'support.opening-hours', 'q_001', '0-start', str_repeat('a', 100)] as $valid) {
            self::assertSame(1, preg_match(EvalQuestion::KEY_PATTERN, $valid), $valid);
        }
        foreach (['', '-start', '.start', 'Upper', 'with space', 'umlaut-ä', str_repeat('a', 101)] as $invalid) {
            self::assertSame(0, preg_match(EvalQuestion::KEY_PATTERN, $invalid), $invalid);
        }
    }

    public function testRunDefaults(): void
    {
        $run = new EvalRun();

        self::assertInstanceOf(Uuid::class, $run->getId());
        self::assertSame(EvalRunStatus::PENDING, $run->getStatus());
        self::assertTrue($run->isActive());
        self::assertSame('', $run->getOwnerSubject());
        self::assertNull($run->getMode());
        self::assertFalse($run->isRetrievalOnly());
        self::assertNull($run->getJobId());
        self::assertNull($run->getCancelRequestedAt());
        self::assertSame(0, $run->getQuestionsTotal());
        self::assertSame(0, $run->getTokensTotal());
        self::assertSame(0, $run->getInteractionsTotal());
        self::assertNull($run->getVerdictCounts());
        self::assertNull($run->getRecall());
        self::assertNull($run->getMrr());
        self::assertNull($run->getQuestionsMeasured());
        self::assertNull($run->getQuestionsNegative());
        self::assertNull($run->getQuestionsUnmeasured());
        self::assertNull($run->getQuestionsRetrievalFailed());
        self::assertNull($run->getStartedAt());
        self::assertNull($run->getFinishedAt());
    }

    public function testRunWithoutVersionFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);

        (new EvalRun())->getAgentVersion();
    }

    public function testRunSettersAndActiveStates(): void
    {
        $version = self::version();
        $now = new \DateTimeImmutable();

        $run = (new EvalRun())
            ->setAgentVersion($version)
            ->setOwnerSubject('reviewer-1')
            ->setJobId('0199a0b1-0000-7000-8000-000000000001')
            ->setQuestionsTotal(3)
            ->setTokensTotal(1200)
            ->setInteractionsTotal(2)
            ->setVerdictCounts(['correct' => 2, 'wrong' => 1])
            ->setStartedAt($now)
            ->setCancelRequestedAt($now)
            ->setMode('retrieval')
            ->setRetrievalOnly(true);

        self::assertSame($version, $run->getAgentVersion());
        self::assertSame('reviewer-1', $run->getOwnerSubject());
        self::assertSame('0199a0b1-0000-7000-8000-000000000001', $run->getJobId());
        self::assertSame(3, $run->getQuestionsTotal());
        self::assertSame(1200, $run->getTokensTotal());
        self::assertSame(2, $run->getInteractionsTotal());
        self::assertSame(['correct' => 2, 'wrong' => 1], $run->getVerdictCounts());
        self::assertSame($now, $run->getStartedAt());
        self::assertSame($now, $run->getCancelRequestedAt());
        self::assertSame('retrieval', $run->getMode());
        self::assertTrue($run->isRetrievalOnly());
        self::assertFalse($run->setRetrievalOnly(false)->isRetrievalOnly());

        $expected = [
            EvalRunStatus::PENDING => true,
            EvalRunStatus::RUNNING => true,
            EvalRunStatus::COMPLETED => false,
            EvalRunStatus::CANCELLED => false,
            EvalRunStatus::FAILED => false,
        ];
        foreach ($expected as $status => $active) {
            self::assertSame($active, $run->setStatus($status)->isActive(), $status);
        }

        $run->setFinishedAt($now);
        self::assertSame($now, $run->getFinishedAt());
    }

    public function testResultDefaults(): void
    {
        $result = new EvalResult();

        self::assertInstanceOf(Uuid::class, $result->getId());
        self::assertSame(EvalResultStatus::PENDING, $result->getStatus());
        self::assertSame(EvalResultSource::BATCH, $result->getSource());
        self::assertNull($result->getEvalRun());
        self::assertNull($result->getConversation());
        self::assertNull($result->getErrorMessage());
        self::assertNull($result->getDurationMs());
        self::assertNull($result->getTokensInput());
        self::assertNull($result->getTokensOutput());
        self::assertNull($result->getInteractions());
        self::assertNull($result->getHumanRating());
        self::assertNull($result->getRecallHit());
        self::assertNull($result->getFirstRelevantRank());
        self::assertNull($result->getRetrievalStatus());
    }

    public function testResultWithoutQuestionFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);

        (new EvalResult())->getEvalQuestion();
    }

    public function testResultSetters(): void
    {
        $run = new EvalRun();
        $question = (new EvalQuestion())->setKey('q1');
        $conversation = (new Conversation())->setOrigin(Conversation::ORIGIN_EVAL);

        $result = (new EvalResult())
            ->setEvalRun($run)
            ->setEvalQuestion($question)
            ->setConversation($conversation)
            ->setSource(EvalResultSource::SINGLE)
            ->setStatus(EvalResultStatus::FAILED)
            ->setErrorMessage('Provider timeout')
            ->setDurationMs(1500)
            ->setTokensInput(800)
            ->setTokensOutput(200)
            ->setInteractions(1)
            ->setHumanRating(['verdict' => 'correct'])
            ->setRecallHit(true)
            ->setFirstRelevantRank(2);

        self::assertSame($run, $result->getEvalRun());
        self::assertSame($question, $result->getEvalQuestion());
        self::assertSame($conversation, $result->getConversation());
        self::assertSame(EvalResultSource::SINGLE, $result->getSource());
        self::assertSame(EvalResultStatus::FAILED, $result->getStatus());
        self::assertSame('Provider timeout', $result->getErrorMessage());
        self::assertSame(1500, $result->getDurationMs());
        self::assertSame(800, $result->getTokensInput());
        self::assertSame(200, $result->getTokensOutput());
        self::assertSame(1, $result->getInteractions());
        self::assertSame(['verdict' => 'correct'], $result->getHumanRating());
        self::assertTrue($result->getRecallHit());
        self::assertSame(2, $result->getFirstRelevantRank());

        self::assertNull($result->setEvalRun(null)->setConversation(null)->getEvalRun());
        self::assertNull($result->getConversation());
    }

    public function testRunRetrievalAggregates(): void
    {
        $run = (new EvalRun())
            ->setRecall(0.75)
            ->setMrr(0.625)
            ->setQuestionsMeasured(4)
            ->setQuestionsNegative(2)
            ->setQuestionsUnmeasured(5)
            ->setQuestionsRetrievalFailed(1);

        self::assertSame(0.75, $run->getRecall());
        self::assertSame(0.625, $run->getMrr());
        self::assertSame(4, $run->getQuestionsMeasured());
        self::assertSame(2, $run->getQuestionsNegative());
        self::assertSame(5, $run->getQuestionsUnmeasured());
        self::assertSame(1, $run->getQuestionsRetrievalFailed());

        $run->setRecall(null)->setMrr(null)->setQuestionsMeasured(null)->setQuestionsNegative(null)->setQuestionsUnmeasured(null)->setQuestionsRetrievalFailed(null);
        self::assertNull($run->getRecall());
        self::assertNull($run->getMrr());
        self::assertNull($run->getQuestionsMeasured());
        self::assertNull($run->getQuestionsNegative());
        self::assertNull($run->getQuestionsUnmeasured());
        self::assertNull($run->getQuestionsRetrievalFailed());
    }

    public function testResultRetrievalStatusAndApplyRetrieval(): void
    {
        $result = (new EvalResult())->setRetrievalStatus(RetrievalStatus::UNMEASURED);
        self::assertSame(RetrievalStatus::UNMEASURED, $result->getRetrievalStatus());

        $hit = RetrievalMeasurement::classify(false, ['Wartung'], [new ContextChunk('Intro', 0.9), new ContextChunk('Die Wartung', 0.8)]);
        self::assertSame($result, $result->applyRetrieval($hit));
        self::assertSame(RetrievalStatus::MEASURED, $result->getRetrievalStatus());
        self::assertTrue($result->getRecallHit());
        self::assertSame(2, $result->getFirstRelevantRank());

        // Applying another measurement replaces all three fields.
        $result->applyRetrieval(RetrievalMeasurement::classify(false, ['Garantie'], [new ContextChunk('Intro', 0.9)]));
        self::assertSame(RetrievalStatus::MEASURED, $result->getRetrievalStatus());
        self::assertFalse($result->getRecallHit());
        self::assertNull($result->getFirstRelevantRank());

        $result->applyRetrieval(RetrievalMeasurement::failed());
        self::assertSame(RetrievalStatus::FAILED, $result->getRetrievalStatus());
        self::assertNull($result->getRecallHit());
        self::assertNull($result->getFirstRelevantRank());

        $result->applyRetrieval(RetrievalMeasurement::classify(true, null, []));
        self::assertSame(RetrievalStatus::NEGATIVE, $result->getRetrievalStatus());

        self::assertNull($result->setRetrievalStatus(null)->getRetrievalStatus());
    }

    public function testConversationOrigin(): void
    {
        self::assertSame('eval', Conversation::ORIGIN_EVAL);

        $conversation = new Conversation();
        self::assertNull($conversation->getOrigin());
        self::assertSame(Conversation::ORIGIN_EVAL, $conversation->setOrigin(Conversation::ORIGIN_EVAL)->getOrigin());
        self::assertNull($conversation->setOrigin(null)->getOrigin());
    }

    private static function version(): AgentVersion
    {
        return new AgentVersion(new Agent(), 1, [], 'publisher');
    }
}
