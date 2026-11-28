<?php

// file generated with AI assistance: Claude Code - 2026-11-27 22:48:58 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Chat;

use Dmstr\NeuronAiBundle\Chat\GuardedTool;
use Dmstr\NeuronAiBundle\Chat\ToolArgumentGuard;
use Dmstr\NeuronAiBundle\Testing\CallbackTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The decision of {@see ToolArgumentGuard::refusal()} over the value
 * matrix, its constructor checks, and the {@see GuardedTool} wrapper on
 * its own: a refused call never runs the wrapped tool, an allowed one
 * does, and a clone carries its own copy of the wrapped tool.
 */
final class ToolArgumentGuardTest extends TestCase
{
    private const array ARGUMENTS = ['list_issues' => 'project', 'get_issue' => 'project_id'];

    private const array ALLOWED = ['acme/app', 'acme/platform/backend', 42, 'group%2Fproject'];

    /**
     * @param array<string, mixed> $inputs
     * @param list<string>         $declared
     */
    #[DataProvider('decisions')]
    public function testRefusal(string $tool, array $inputs, array $declared, ?string $expected): void
    {
        self::assertSame($expected, new ToolArgumentGuard(self::ARGUMENTS, self::ALLOWED)->refusal($tool, $inputs, $declared));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, list<string>, string|null}>
     */
    public static function decisions(): iterable
    {
        $notAllowed = 'Tool "list_issues" is refused: the value of the argument "project" is not allowed in this run.';
        $missing = 'Tool "list_issues" is refused: the argument "project" is missing.';
        $type = 'Tool "list_issues" is refused: the argument "project" must be a string or an integer.';
        $declared = ['project', 'state'];

        yield 'allowed path' => ['list_issues', ['project' => 'acme/app'], $declared, null];
        yield 'nested group path' => ['list_issues', ['project' => 'acme/platform/backend'], $declared, null];
        yield 'allowed id as int' => ['get_issue', ['project_id' => 42], ['project_id'], null];
        yield 'allowed id as string' => ['get_issue', ['project_id' => '42'], ['project_id'], null];
        yield 'listed encoded spelling' => ['list_issues', ['project' => 'group%2Fproject'], $declared, null];
        yield 'extra keys' => ['list_issues', ['project' => 'acme/app', 'tenant' => 'other'], $declared, null];
        yield 'foreign path' => ['list_issues', ['project' => 'other/app'], $declared, $notAllowed];
        yield 'foreign id' => ['get_issue', ['project_id' => 7], ['project_id'], 'Tool "get_issue" is refused: the value of the argument "project_id" is not allowed in this run.'];
        yield 'other letter case' => ['list_issues', ['project' => 'ACME/app'], $declared, $notAllowed];
        yield 'unlisted encoded spelling' => ['list_issues', ['project' => 'acme%2Fapp'], $declared, $notAllowed];
        yield 'decoded form of a listed encoded spelling' => ['list_issues', ['project' => 'group/project'], $declared, $notAllowed];
        yield 'parent group' => ['list_issues', ['project' => 'acme/platform'], $declared, $notAllowed];
        yield 'padded' => ['list_issues', ['project' => 'acme/app '], $declared, $notAllowed];
        yield 'id with leading zero' => ['get_issue', ['project_id' => '042'], ['project_id'], 'Tool "get_issue" is refused: the value of the argument "project_id" is not allowed in this run.'];
        yield 'empty string' => ['list_issues', ['project' => ''], $declared, $notAllowed];
        yield 'whitespace' => ['list_issues', ['project' => " \t"], $declared, $notAllowed];
        yield 'null' => ['list_issues', ['project' => null], $declared, $missing];
        yield 'missing key' => ['list_issues', ['state' => 'open'], $declared, $missing];
        yield 'array' => ['list_issues', ['project' => ['acme/app']], $declared, $type];
        yield 'object as array' => ['list_issues', ['project' => ['path' => 'acme/app']], $declared, $type];
        yield 'object' => ['list_issues', ['project' => (object) ['path' => 'acme/app']], $declared, $type];
        yield 'bool true' => ['list_issues', ['project' => true], $declared, $type];
        yield 'bool false' => ['list_issues', ['project' => false], $declared, $type];
        yield 'float' => ['get_issue', ['project_id' => 42.0], ['project_id'], 'Tool "get_issue" is refused: the argument "project_id" must be a string or an integer.'];
        yield 'tool not in the map' => ['server_info', [], [], 'Tool "server_info" is refused: it is not available in this run.'];
        yield 'tool not in the map with an allowed value' => ['delete_project', ['project' => 'acme/app'], ['project'], 'Tool "delete_project" is refused: it is not available in this run.'];
        yield 'argument not declared' => ['list_issues', ['project' => 'acme/app'], ['state'], 'Tool "list_issues" is refused: it does not declare the argument "project".'];
    }

    public function testEmptyAllowedSetRefusesEverything(): void
    {
        $guard = new ToolArgumentGuard(self::ARGUMENTS, []);

        foreach (['', ' ', '0', 'acme/app'] as $value) {
            self::assertNotNull($guard->refusal('list_issues', ['project' => $value], ['project']));
        }
        self::assertNotNull($guard->refusal('get_issue', ['project_id' => 0], ['project_id']));
    }

    public function testEmptyStringIsAllowedOnlyWhenListed(): void
    {
        self::assertNull(new ToolArgumentGuard(self::ARGUMENTS, [''])->refusal('list_issues', ['project' => ''], ['project']));
    }

    public function testEmptyMapRefusesEveryTool(): void
    {
        self::assertSame('Tool "list_issues" is refused: it is not available in this run.', new ToolArgumentGuard([], self::ALLOWED)->refusal('list_issues', ['project' => 'acme/app'], ['project']));
    }

    public function testAllowedValuesAreKeptAsStrings(): void
    {
        self::assertSame(['acme/app', 'acme/platform/backend', '42', 'group%2Fproject'], new ToolArgumentGuard(self::ARGUMENTS, [...self::ALLOWED, '42'])->allowed);
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param list<mixed>             $allowed
     */
    #[DataProvider('invalidGuards')]
    public function testInvalidGuardIsRejected(array $arguments, array $allowed): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ToolArgumentGuard($arguments, $allowed);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, list<mixed>}>
     */
    public static function invalidGuards(): iterable
    {
        yield 'empty argument name' => [['list_issues' => ''], []];
        yield 'empty tool name' => [['' => 'project'], []];
        yield 'list instead of a map' => [['project'], []];
        yield 'argument name not a string' => [['list_issues' => ['project']], []];
        yield 'float value' => [self::ARGUMENTS, [4.2]];
        yield 'bool value' => [self::ARGUMENTS, [true]];
        yield 'null value' => [self::ARGUMENTS, [null]];
    }

    public function testGuardedToolRunsTheWrappedToolOnlyWhenAllowed(): void
    {
        $runs = [];
        $tool = new CallbackTool('list_issues', 'Lists issues.', static function (string $project) use (&$runs): string {
            $runs[] = $project;

            return 'issues of '.$project;
        });
        $tool->addProperty(new ToolProperty('project', PropertyType::STRING, 'Project path', true));
        [$guarded] = new ToolArgumentGuard(self::ARGUMENTS, self::ALLOWED)->guard([$tool]);

        $refused = clone $guarded;
        $refused->setInputs(['project' => 'other/app']);
        $refused->execute();

        $allowed = clone $guarded;
        $allowed->setInputs(['project' => 'acme/app']);
        $allowed->execute();

        self::assertInstanceOf(GuardedTool::class, $guarded);
        self::assertSame(['acme/app'], $runs);
        $result = $refused->getResult();
        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertTrue($result->isError());
        self::assertSame('issues of acme/app', $allowed->getResult());
        self::assertSame(['project' => 'other/app'], $refused->getInputs(), 'Each clone binds its own inputs.');
        self::assertSame([], $guarded->getInputs());
        self::assertFalse($guarded->hasResult());
        self::assertSame('list_issues', $guarded->getName());
        self::assertSame($tool->getInputSchema(), $guarded->getInputSchema());
        self::assertSame('issues of acme/app', $allowed->jsonSerialize()['result']);
    }
}
