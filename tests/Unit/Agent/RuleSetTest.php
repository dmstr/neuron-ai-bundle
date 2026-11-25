<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:59:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Agent\Rule\Rule;
use Dmstr\NeuronAiBundle\Agent\Rule\RuleOutcome;
use Dmstr\NeuronAiBundle\Agent\Rule\RuleSet;
use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Kernel-free: rule matching and evaluation.
 * Rules are given in the candidate shape and normalized through
 * {@see AgentConfiguration::fromArray()} as the mapper receives them.
 */
final class RuleSetTest extends TestCase
{
    private const array TEMPLATES = [
        'refusal' => ['de' => 'Dazu nichts.', 'en' => 'Nothing on that.'],
        'answer' => ['de' => 'Antwort.'],
        'context' => ['de' => '{{rule}}/{{outcome}}/{{agentName}}/{{customer}}/{{missing}}'],
    ];

    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    public function testEmptyRuleSet(): void
    {
        $rules = new RuleSet();

        self::assertTrue($rules->isEmpty());
        self::assertNull($rules->evaluate('anything', 'de'));
        self::assertTrue($this->rules([])->isEmpty());
    }

    public function testNoMatchReturnsNull(): void
    {
        $rules = $this->rules([self::keywords(['preis'], 'refuse', 'refusal')]);

        self::assertFalse($rules->isEmpty());
        self::assertNull($rules->evaluate('Wie spät ist es?', 'de'));
    }

    public function testFirstMatchDecidesAndSwappingTheOrderSwapsTheOutcome(): void
    {
        $refuse = self::keywords(['preis'], 'refuse', 'refusal');
        $answer = self::pattern('preis', 'i', 'answer', 'answer');

        $outcome = $this->rules([$refuse, $answer])->evaluate('Der Preis?', 'de');
        self::assertNotNull($outcome);
        self::assertSame(0, $outcome->index);
        self::assertSame(RuleOutcome::REFUSE, $outcome->outcome);
        self::assertTrue($outcome->isRefusal());
        self::assertSame('Dazu nichts.', $outcome->text);

        $outcome = $this->rules([$answer, $refuse])->evaluate('Der Preis?', 'de');
        self::assertNotNull($outcome);
        self::assertSame(0, $outcome->index);
        self::assertSame(RuleOutcome::ANSWER, $outcome->outcome);
        self::assertFalse($outcome->isRefusal());
        self::assertSame('pattern', $outcome->match);
        self::assertSame('Antwort.', $outcome->text);
    }

    public function testContinueBeforeARefusalReturnsNull(): void
    {
        $rules = $this->rules([
            self::keywords(['preis'], 'continue', null),
            self::keywords(['preis'], 'refuse', 'refusal'),
        ]);

        self::assertNull($rules->evaluate('Der Preis?', 'de'));
    }

    public function testIndexCountsNonMatchingRules(): void
    {
        $outcome = $this->rules([
            self::keywords(['nie'], 'refuse', 'refusal'),
            self::keywords(['preis'], 'answer', 'answer', 'prices'),
        ])->evaluate('preis', 'de');

        self::assertNotNull($outcome);
        self::assertSame(1, $outcome->index);
        self::assertSame('prices', $outcome->name);
        self::assertSame('keywords', $outcome->match);
        self::assertSame('answer', $outcome->template);
        self::assertSame('de', $outcome->language);
    }

    public function testWholeWordDoesNotMatchInsideAWord(): void
    {
        $rules = $this->rules([self::keywords(['preis'], 'refuse', 'refusal')]);

        self::assertNull($rules->evaluate('Wo ist die Preisliste?', 'de'));
        self::assertNull($rules->evaluate('Einkaufspreis', 'de'));
        self::assertNull($rules->evaluate('preis2', 'de'));
        self::assertNotNull($rules->evaluate('Was ist der Preis?', 'de'));
        self::assertNotNull($rules->evaluate('preis', 'de'));
        self::assertNotNull($rules->evaluate('(Preis)', 'de'));
    }

    public function testSubstringModeMatchesInsideAWord(): void
    {
        $rules = $this->rules([self::keywords(['preis'], 'refuse', 'refusal', null, false)]);

        self::assertNotNull($rules->evaluate('Wo ist die Preisliste?', 'de'));
        self::assertNotNull($rules->evaluate('Einkaufspreis', 'de'));
    }

    public function testAnyKeywordMatches(): void
    {
        $rules = $this->rules([self::keywords(['kosten', 'preis'], 'refuse', 'refusal')]);

        self::assertNotNull($rules->evaluate('Was kostet das? Die Kosten bitte.', 'de'));
        self::assertNotNull($rules->evaluate('Der Preis bitte.', 'de'));
        self::assertNull($rules->evaluate('Was kostet das?', 'de'));
    }

    public function testKeywordsFoldUnicodeCase(): void
    {
        $rules = $this->rules([self::keywords(['äpfel'], 'refuse', 'refusal')]);

        self::assertNotNull($rules->evaluate('ÄPFEL im Angebot', 'de'));
        self::assertNotNull($rules->evaluate('Äpfel', 'de'));
        // Simple case folding only: ß does not fold to SS.
        self::assertNull($this->rules([self::keywords(['straße'], 'refuse', 'refusal')])->evaluate('STRASSE', 'de'));
    }

    public function testKeywordsAreLiteral(): void
    {
        $rules = $this->rules([self::keywords(['a.b', 'c/d', 'x+'], 'refuse', 'refusal', null, false)]);

        self::assertNull($rules->evaluate('axb', 'de'));
        self::assertNotNull($rules->evaluate('a.b', 'de'));
        self::assertNotNull($rules->evaluate('c/d', 'de'));
        self::assertNotNull($rules->evaluate('x+', 'de'));
        self::assertNull($rules->evaluate('xx', 'de'));
    }

    public function testPatternFlags(): void
    {
        self::assertNull($this->rules([self::pattern('^hallo$', '', 'answer', 'answer')])->evaluate('HALLO', 'de'));
        self::assertNotNull($this->rules([self::pattern('^hallo$', 'i', 'answer', 'answer')])->evaluate('HALLO', 'de'));
        self::assertNotNull($this->rules([self::pattern('^zwei$', 'im', 'answer', 'answer')])->evaluate("eins\nZwei", 'de'));
        self::assertNotNull($this->rules([self::pattern('a.b', 's', 'answer', 'answer')])->evaluate("a\nb", 'de'));
        self::assertNotNull($this->rules([self::pattern('a b # comment', 'x', 'answer', 'answer')])->evaluate('ab', 'de'));
    }

    public function testPatternIsUnicodeAndEscapesTheDelimiter(): void
    {
        self::assertNotNull($this->rules([self::pattern('^.$', '', 'answer', 'answer')])->evaluate('ä', 'de'));
        self::assertNotNull($this->rules([self::pattern('a/b', '', 'answer', 'answer')])->evaluate('a/b', 'de'));
        self::assertNotNull($this->rules([self::pattern('a\/b', '', 'answer', 'answer')])->evaluate('a/b', 'de'));
        self::assertSame('/a\/b\/c/iu', Rule::compilePattern('a/b\/c', 'i'));
    }

    public function testCatastrophicPatternIsNoMatchWithAWarningAndTheNextRuleIsEvaluated(): void
    {
        $rules = $this->rules([
            self::pattern('(a+)+$', '', 'refuse', 'refusal', 'catastrophic'),
            self::keywords(['a'], 'answer', 'answer', null, false),
        ], ['agent' => 'support', 'version' => 3]);

        $outcome = $rules->evaluate(str_repeat('a', 5000).'!', 'de');

        self::assertNotNull($outcome);
        self::assertSame(1, $outcome->index);
        self::assertCount(1, $this->logged);
        self::assertSame('warning', $this->logged[0]['level']);
        $context = $this->logged[0]['context'];
        self::assertSame('support', $context['agent']);
        self::assertSame(3, $context['version']);
        self::assertSame(0, $context['ruleIndex']);
        self::assertSame('catastrophic', $context['ruleName']);
        self::assertIsString($context['pregError']);
        self::assertNotSame('No error', $context['pregError']);
    }

    public function testInvalidUtf8InputIsNoMatchWithAWarning(): void
    {
        $rules = $this->rules([self::keywords(['a'], 'refuse', 'refusal', null, false)]);

        self::assertNull($rules->evaluate("a\xff", 'de'));
        self::assertCount(1, $this->logged);
    }

    public function testLanguageRuleFiresOutsideTheAllowedLanguages(): void
    {
        $rules = $this->rules([[
            'match' => ['type' => 'language', 'languages' => ['de']],
            'outcome' => 'refuse',
            'template' => 'refusal',
        ]]);

        self::assertNull($rules->evaluate('Hello', 'de'));
        $outcome = $rules->evaluate('Hello', 'en');
        self::assertNotNull($outcome);
        self::assertSame('language', $outcome->match);
        self::assertSame('en', $outcome->language);
        self::assertSame('Nothing on that.', $outcome->text);
    }

    public function testTextFallsBackToTheDefaultLanguage(): void
    {
        $outcome = $this->rules([self::keywords(['x'], 'answer', 'answer')])->evaluate('x', 'en');

        self::assertNotNull($outcome);
        self::assertSame('Antwort.', $outcome->text);
        self::assertSame('en', $outcome->language);
    }

    public function testRuleAndOutcomePlaceholdersAndLenientRendering(): void
    {
        $outcome = $this->rules([self::keywords(['x'], 'refuse', 'context', 'named')])->evaluate('x', 'de');
        self::assertNotNull($outcome);
        self::assertSame('named/refuse/Support/Kim/', $outcome->text);

        // Without a name, `rule` renders empty; a value of the same name is overridden.
        $outcome = RuleSet::fromConfiguration(
            AgentConfiguration::fromArray(['rules' => [self::keywords(['x'], 'answer', 'context')]])->rules,
            new TemplateSet(self::TEMPLATES, 'de'),
            ['agentName' => 'Support', 'rule' => 'caller', 'outcome' => 'caller'],
        )->evaluate('x', 'de');
        self::assertNotNull($outcome);
        self::assertSame('/answer/Support//', $outcome->text);
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed>       $logContext
     */
    private function rules(array $rules, array $logContext = []): RuleSet
    {
        $logger = new class($this->logged) extends AbstractLogger {
            /**
             * @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records
             */
            public function __construct(private array &$records)
            {
            }

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        return RuleSet::fromConfiguration(
            AgentConfiguration::fromArray(['rules' => $rules])->rules,
            new TemplateSet(self::TEMPLATES, 'de'),
            ['agentName' => 'Support', 'customer' => 'Kim'],
            $logger,
            $logContext,
        );
    }

    /**
     * @param list<string> $keywords
     *
     * @return array<string, mixed>
     */
    private static function keywords(array $keywords, string $outcome, ?string $template, ?string $name = null, bool $wholeWord = true): array
    {
        return [
            'name' => $name,
            'match' => ['type' => 'keywords', 'keywords' => $keywords, 'wholeWord' => $wholeWord],
            'outcome' => $outcome,
            'template' => $template,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pattern(string $pattern, string $flags, string $outcome, ?string $template, ?string $name = null): array
    {
        return [
            'name' => $name,
            'match' => ['type' => 'pattern', 'pattern' => $pattern, 'flags' => $flags],
            'outcome' => $outcome,
            'template' => $template,
        ];
    }
}
