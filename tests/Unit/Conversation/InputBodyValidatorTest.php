<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free test of the raw-body validation of bundle operations against
 * their input schema, here the turn body (`Entity/Conversation/turn.input.json`)
 * resolved by the same resolver configuration the bundle registers.
 */
final class InputBodyValidatorTest extends TestCase
{
    private const string ENTITY_ROOT = __DIR__.'/../../../src/Entity';

    public function testValidTurnBodyIsDecoded(): void
    {
        $input = self::validator()->validate(Conversation::OPERATION_TURN, '{"content": "Hallo"}');

        self::assertSame(['content' => 'Hallo'], $input);
    }

    public function testResponseSchemaIsDecodedAsArray(): void
    {
        $body = '{"content": "Farbe?", "responseSchema": {"type": "object", "properties": {"color": {"type": "string"}}}}';

        $input = self::validator()->validate(Conversation::OPERATION_TURN, $body);

        self::assertSame('Farbe?', $input['content']);
        self::assertSame(['type' => 'object', 'properties' => ['color' => ['type' => 'string']]], $input['responseSchema']);
    }

    public function testMaxToolCallsIsDecoded(): void
    {
        $input = self::validator()->validate(Conversation::OPERATION_TURN, '{"content": "Hallo", "maxToolCalls": 3}');

        self::assertSame(['content' => 'Hallo', 'maxToolCalls' => 3], $input);
    }

    public function testNullMaxToolCallsIsAccepted(): void
    {
        $input = self::validator()->validate(Conversation::OPERATION_TURN, '{"content": "Hallo", "maxToolCalls": null}');

        self::assertSame(['content' => 'Hallo', 'maxToolCalls' => null], $input);
    }

    public function testTerminalToolsAreDecodedInRequestOrder(): void
    {
        $input = self::validator()->validate(Conversation::OPERATION_TURN, '{"content": "Hallo", "terminalTools": ["submit_answer", "lookup"]}');

        self::assertSame(['content' => 'Hallo', 'terminalTools' => ['submit_answer', 'lookup']], $input);
    }

    public function testNullTerminalToolsAreAccepted(): void
    {
        $input = self::validator()->validate(Conversation::OPERATION_TURN, '{"content": "Hallo", "terminalTools": null}');

        self::assertSame(['content' => 'Hallo', 'terminalTools' => null], $input);
    }

    public function testEmptyTerminalToolsAreAccepted(): void
    {
        $input = self::validator()->validate(Conversation::OPERATION_TURN, '{"content": "Hallo", "terminalTools": []}');

        self::assertSame(['content' => 'Hallo', 'terminalTools' => []], $input);
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);

        self::validator()->validate(Conversation::OPERATION_TURN, '{"content": ');
    }

    #[DataProvider('schemaViolations')]
    public function testSchemaViolationIsUnprocessable(string $body): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Input validation failed for operation "conversation_turn"');

        self::validator()->validate(Conversation::OPERATION_TURN, $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function schemaViolations(): iterable
    {
        // An empty body counts as {} and misses the required content.
        yield 'empty body' => [''];
        yield 'whitespace body' => ["  \n"];
        yield 'empty object' => ['{}'];
        yield 'empty content' => ['{"content": ""}'];
        yield 'content not a string' => ['{"content": 42}'];
        yield 'schema not an object' => ['{"content": "Hallo", "responseSchema": "object"}'];
        yield 'unknown property' => ['{"content": "Hallo", "prompt": "Hallo"}'];
        yield 'maxToolCalls zero' => ['{"content": "Hallo", "maxToolCalls": 0}'];
        yield 'maxToolCalls negative' => ['{"content": "Hallo", "maxToolCalls": -1}'];
        yield 'maxToolCalls a string' => ['{"content": "Hallo", "maxToolCalls": "3"}'];
        yield 'maxToolCalls a fraction' => ['{"content": "Hallo", "maxToolCalls": 2.5}'];
        yield 'maxToolCalls a boolean' => ['{"content": "Hallo", "maxToolCalls": true}'];
        yield 'terminalTools a string' => ['{"content": "Hallo", "terminalTools": "submit_answer"}'];
        yield 'terminalTools duplicate name' => ['{"content": "Hallo", "terminalTools": ["submit_answer", "submit_answer"]}'];
        yield 'terminalTools non-string entry' => ['{"content": "Hallo", "terminalTools": ["submit_answer", 42]}'];
        yield 'terminalTools null entry' => ['{"content": "Hallo", "terminalTools": [null]}'];
        yield 'terminalTools empty name' => ['{"content": "Hallo", "terminalTools": [""]}'];
        yield 'not an object' => ['["Hallo"]'];
    }

    public function testOperationWithoutSchemaIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        self::validator()->validate('conversation_unknown', '{}');
    }

    private static function validator(): InputBodyValidator
    {
        return new InputBodyValidator(new OperationInputSchemaResolver([], [[self::ENTITY_ROOT, 'Dmstr\\NeuronAiBundle\\Entity']]));
    }
}
