<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Validator\ValidAgentCandidate;
use Dmstr\NeuronAiBundle\Validator\ValidAgentCandidateValidator;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * Kernel-free checks of the structural candidate constraint used on POST and
 * PATCH: structure only, references are left to the publish.
 *
 * @extends ConstraintValidatorTestCase<ValidAgentCandidateValidator>
 */
final class ValidAgentCandidateValidatorTest extends ConstraintValidatorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setPropertyPath('candidate');
    }

    protected function createValidator(): ConstraintValidatorInterface
    {
        return new ValidAgentCandidateValidator();
    }

    public function testDefaultsAreValid(): void
    {
        $this->validator->validate(Agent::candidateDefaults(), new ValidAgentCandidate());

        $this->assertNoViolation();
    }

    public function testNullIsIgnored(): void
    {
        $this->validator->validate(null, new ValidAgentCandidate());

        $this->assertNoViolation();
    }

    public function testDanglingReferencesAreAllowedInACandidate(): void
    {
        $this->validator->validate([
            'instructions' => ['de' => 'Hallo'],
            'model' => 'not-a-model-reference',
            'collections' => ['does-not-exist'],
            'mcpTools' => [['connection' => 'nowhere', 'name' => 'nothing', 'terminal' => true]],
            'responseSchema' => ['type' => 'nonsense'],
            'languages' => ['de'],
            'defaultLanguage' => 'fr',
        ], new ValidAgentCandidate());

        $this->assertNoViolation();
    }

    public function testUnknownKeyIsAViolationOnTheCandidate(): void
    {
        $this->validator->validate(['unknown' => 1], new ValidAgentCandidate());

        self::assertSame(['candidate'], $this->paths());
        self::assertSame(ValidAgentCandidate::INVALID_CANDIDATE_ERROR, $this->context->getViolations()->get(0)->getCode());
    }

    public function testProviderKeyIsNoLongerPartOfTheCandidate(): void
    {
        $this->validator->validate(['provider' => 'anthropic'], new ValidAgentCandidate());

        self::assertSame(['candidate'], $this->paths());
    }

    public function testConnectionKeyIsAViolationOnTheKeyWithAHint(): void
    {
        $this->validator->validate(['connection' => 'claude'], new ValidAgentCandidate());

        self::assertSame(['candidate/connection'], $this->paths());
        self::assertStringContainsString(AgentConfiguration::CONNECTION_REMOVED, (string) $this->context->getViolations()->get(0)->getMessage());
    }

    public function testStringMaxToolCallsIsAViolationOnTheField(): void
    {
        $this->validator->validate(['maxToolCalls' => '3'], new ValidAgentCandidate());

        self::assertSame(['candidate/maxToolCalls'], $this->paths());
    }

    public function testRangesAndNestedPaths(): void
    {
        $this->validator->validate([
            'modelOptions' => ['temperature' => 3],
            'maxUserTurns' => 0,
            'retrievalDepth' => 0,
            'languages' => [],
            'mcpTools' => [['connection' => 'tools']],
            'model' => '',
        ], new ValidAgentCandidate());

        $paths = $this->paths();
        foreach (['candidate/modelOptions/temperature', 'candidate/maxUserTurns', 'candidate/retrievalDepth', 'candidate/languages', 'candidate/mcpTools/0', 'candidate/model'] as $expected) {
            self::assertContains($expected, $paths);
        }
    }

    public function testContextRangesAreViolationsOnTheFields(): void
    {
        $this->validator->validate([
            'contextMaxChunks' => 0,
            'contextMaxChars' => 0,
            'contextExpansionMaxChars' => -1,
            'contextInstructions' => ['de' => 5],
        ], new ValidAgentCandidate());

        $paths = $this->paths();
        foreach (['candidate/contextMaxChunks', 'candidate/contextMaxChars', 'candidate/contextExpansionMaxChars', 'candidate/contextInstructions/de'] as $expected) {
            self::assertContains($expected, $paths);
        }
    }

    public function testContextInstructionsForAnUnknownLanguageAreAllowedInACandidate(): void
    {
        // The languages of contextInstructions are a publish check.
        $this->validator->validate([
            'contextInstructions' => ['fr' => 'Réponds en français.'],
            'contextExpansionMaxChars' => 0,
        ], new ValidAgentCandidate());

        $this->assertNoViolation();
    }

    public function testRulesWithDanglingTemplatesAreAllowedInACandidate(): void
    {
        // Template references, rule languages and pattern compilation are publish checks.
        $this->validator->validate([
            'rules' => [
                ['match' => ['type' => 'keywords', 'keywords' => ['Preis']], 'outcome' => 'refuse', 'template' => 'missing'],
                ['match' => ['type' => 'pattern', 'pattern' => '(ab', 'flags' => 'i'], 'outcome' => 'continue', 'template' => 'refusal'],
                ['match' => ['type' => 'language', 'languages' => ['fr']], 'outcome' => 'answer'],
            ],
            'templates' => ['refusal' => ['xx' => '{{unknown}}']],
        ], new ValidAgentCandidate());

        $this->assertNoViolation();
    }

    public function testUnknownMatchTypeIsAViolationOnTheType(): void
    {
        $this->validator->validate(['rules' => [
            ['match' => ['type' => 'regex', 'pattern' => 'a'], 'outcome' => 'refuse', 'template' => 'no'],
        ]], new ValidAgentCandidate());

        self::assertSame(['candidate/rules/0/match/type'], $this->paths());
    }

    public function testUnknownOutcomeIsAViolationOnTheOutcome(): void
    {
        $this->validator->validate(['rules' => [
            ['match' => ['type' => 'keywords', 'keywords' => ['a']], 'outcome' => 'block', 'template' => 'no'],
        ]], new ValidAgentCandidate());

        self::assertSame(['candidate/rules/0/outcome'], $this->paths());
    }

    public function testDisallowedFlagIsAViolationOnTheFlags(): void
    {
        $this->validator->validate(['rules' => [
            ['match' => ['type' => 'pattern', 'pattern' => 'a', 'flags' => 'g'], 'outcome' => 'refuse', 'template' => 'no'],
        ]], new ValidAgentCandidate());

        self::assertSame(['candidate/rules/0/match/flags'], $this->paths());
    }

    public function testFieldsOfAnotherMatchTypeAndMissingTypeFieldsAreViolations(): void
    {
        $this->validator->validate(['rules' => [
            ['match' => ['type' => 'keywords', 'keywords' => ['a'], 'flags' => 'i'], 'outcome' => 'refuse', 'template' => 'no'],
            ['match' => ['type' => 'pattern'], 'outcome' => 'refuse', 'template' => 'no'],
            ['match' => ['type' => 'keywords', 'keywords' => []], 'outcome' => 'refuse', 'template' => 'no'],
        ]], new ValidAgentCandidate());

        $paths = $this->paths();
        foreach (['candidate/rules/0/match', 'candidate/rules/1/match', 'candidate/rules/2/match/keywords'] as $expected) {
            self::assertContains($expected, $paths);
        }
    }

    public function testInvalidTemplateNameIsAViolationOnTheTemplates(): void
    {
        $this->validator->validate(['templates' => ['bad-name' => ['de' => 'x']]], new ValidAgentCandidate());

        self::assertSame(['candidate/templates'], $this->paths());
    }

    public function testNonStringTemplateTextIsAViolationOnTheText(): void
    {
        $this->validator->validate(['templates' => ['ok' => ['de' => 5]]], new ValidAgentCandidate());

        self::assertSame(['candidate/templates/ok/de'], $this->paths());
    }

    public function testNonArrayIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate('candidate', new ValidAgentCandidate());
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return array_map(
            static fn (ConstraintViolationInterface $violation): string => $violation->getPropertyPath(),
            iterator_to_array($this->context->getViolations(), false),
        );
    }
}
