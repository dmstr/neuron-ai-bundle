<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

use ApiPlatform\JsonSchema\SchemaFactory;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Error as ErrorOperation;
use ApiPlatform\Metadata\ErrorResource;
use ApiPlatform\Metadata\Exception\HttpExceptionInterface;
use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface as SymfonyHttpExceptionInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\WebLink\Link;

/**
 * 422 answer of the turn gate: the conversation accepts no further turn.
 * The problem document carries a stable machine-readable `code`:
 *
 *  - `conversation_closed`: archived, or closed for another reason;
 *  - `turn_limit`: `max_turns_per_conversation` reached;
 *  - `token_limit`: `max_tokens_per_conversation` reached;
 *  - `retention_expired`: `retention_days` passed since creation;
 *  - `user_turn_limit`: the conversation's own `maxUserTurns` reached
 *    (any conversation, not only anonymous ones).
 *
 * An API Platform error resource modelled on the core
 * `ValidationException`: the exception itself is serialized (`type`,
 * `title`, `detail`, `status`, `code`), not converted into the generic
 * `Error`. It lives in an API Platform mapping path of the bundle, which is
 * what makes it a resource class.
 */
#[ErrorResource(
    uriTemplate: '/conversation_errors/{id}',
    status: 422,
    uriVariables: ['id'],
    openapi: false,
    outputFormats: [
        'jsonapi' => ['application/vnd.api+json'],
        'jsonld' => ['application/ld+json'],
        'json' => ['application/problem+json', 'application/json'],
        'xml' => ['application/xml', 'text/xml'],
    ],
    provider: 'api_platform.state.error_provider',
    shortName: 'ConversationError',
    description: 'The conversation accepts no further turn.',
    operations: [
        new ErrorOperation(
            name: '_api_conversation_errors_problem',
            outputFormats: [
                'json' => ['application/problem+json'],
            ],
            normalizationContext: [
                SchemaFactory::OPENAPI_DEFINITION_NAME => '',
                'groups' => ['jsonproblem'],
                'ignored_attributes' => ['trace', 'file', 'line', 'code', 'message', 'traceAsString', 'previous'],
                'skip_null_values' => true,
            ],
        ),
        new ErrorOperation(
            name: '_api_conversation_errors_hydra',
            outputFormats: ['jsonld' => ['application/problem+json', 'application/ld+json']],
            links: [new Link(rel: 'http://www.w3.org/ns/json-ld#error', href: 'http://www.w3.org/ns/hydra/error')],
            normalizationContext: [
                SchemaFactory::OPENAPI_DEFINITION_NAME => '',
                'groups' => ['jsonld'],
                'ignored_attributes' => ['trace', 'file', 'line', 'code', 'message', 'traceAsString', 'previous'],
                'skip_null_values' => true,
            ],
        ),
        new ErrorOperation(
            name: '_api_conversation_errors_jsonapi',
            outputFormats: ['jsonapi' => ['application/vnd.api+json']],
            normalizationContext: [
                SchemaFactory::OPENAPI_DEFINITION_NAME => '',
                'disable_json_schema_serializer_groups' => false,
                'groups' => ['jsonapi'],
                'ignored_attributes' => ['trace', 'file', 'line', 'code', 'message', 'traceAsString', 'previous'],
                'skip_null_values' => true,
            ],
        ),
        new ErrorOperation(
            name: '_api_conversation_errors_xml',
            outputFormats: [
                'xml' => ['application/xml', 'text/xml'],
            ],
            normalizationContext: [
                'groups' => ['jsonproblem'],
                'ignored_attributes' => ['trace', 'file', 'line', 'code', 'message', 'traceAsString', 'previous'],
                'skip_null_values' => true,
            ],
        ),
    ],
    graphQlOperations: [],
)]
#[ApiProperty(property: 'traceAsString', hydra: false)]
#[ApiProperty(property: 'string', hydra: false)]
final class ConversationLimitException extends \RuntimeException implements ProblemExceptionInterface, HttpExceptionInterface, SymfonyHttpExceptionInterface
{
    public const string CODE_CLOSED = 'conversation_closed';
    public const string CODE_TURN_LIMIT = 'turn_limit';
    public const string CODE_TOKEN_LIMIT = 'token_limit';
    public const string CODE_RETENTION_EXPIRED = 'retention_expired';
    public const string CODE_USER_TURN_LIMIT = 'user_turn_limit';

    /** Close reason => code of a turn on a conversation closed for that reason. */
    private const array CODE_BY_CLOSE_REASON = [
        Conversation::CLOSE_REASON_TURN_LIMIT => self::CODE_TURN_LIMIT,
        Conversation::CLOSE_REASON_TOKEN_LIMIT => self::CODE_TOKEN_LIMIT,
        Conversation::CLOSE_REASON_RETENTION => self::CODE_RETENTION_EXPIRED,
        Conversation::CLOSE_REASON_USER_TURN_LIMIT => self::CODE_USER_TURN_LIMIT,
    ];

    private const array TITLES = [
        self::CODE_CLOSED => 'The conversation is closed.',
        self::CODE_TURN_LIMIT => 'The conversation reached its turn limit.',
        self::CODE_TOKEN_LIMIT => 'The conversation reached its token budget.',
        self::CODE_RETENTION_EXPIRED => 'The retention period of the conversation has passed.',
        self::CODE_USER_TURN_LIMIT => 'The conversation reached its user-turn limit.',
    ];

    private function __construct(
        private readonly string $errorCode,
        string $detail,
    ) {
        parent::__construct($detail);
    }

    /**
     * @param self::CODE_* $code
     */
    public static function create(string $code, string $detail): self
    {
        if (!isset(self::TITLES[$code])) {
            throw new \InvalidArgumentException(\sprintf('Unknown conversation error code "%s".', $code));
        }

        return new self($code, $detail);
    }

    /**
     * The answer to a turn on a conversation that accepts no turns: the
     * code follows the close reason (`turn_limit`, `token_limit`,
     * `retention_expired`, `user_turn_limit`); archived conversations and closes without a
     * known reason get `conversation_closed`.
     */
    public static function forConversation(Conversation $conversation): self
    {
        $code = self::CODE_BY_CLOSE_REASON[$conversation->getCloseReason() ?? ''] ?? self::CODE_CLOSED;
        $detail = self::CODE_CLOSED === $code
            ? \sprintf('The conversation is %s and accepts no further turn.', $conversation->getStatus())
            : self::TITLES[$code].' It accepts no further turn.';

        return new self($code, $detail);
    }

    /**
     * The identifier of the error resource (the code).
     */
    public function getId(): string
    {
        return $this->errorCode;
    }

    /**
     * Stable machine-readable reason; serialized as `code`.
     */
    #[SerializedName('code')]
    #[Groups(['jsonld', 'jsonproblem', 'jsonapi'])]
    #[ApiProperty(writable: false, initializable: false, description: 'Stable code of the problem: conversation_closed, turn_limit, token_limit, retention_expired or user_turn_limit.')]
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    #[Groups(['jsonld', 'jsonproblem', 'jsonapi'])]
    #[ApiProperty(writable: false, initializable: false)]
    public function getType(): string
    {
        return '/conversation_errors/'.$this->errorCode;
    }

    #[Groups(['jsonld', 'jsonproblem', 'jsonapi'])]
    #[ApiProperty(writable: false, initializable: false)]
    public function getTitle(): ?string
    {
        return self::TITLES[$this->errorCode];
    }

    #[Groups(['jsonld', 'jsonproblem', 'jsonapi'])]
    #[ApiProperty(writable: false, initializable: false)]
    public function getDetail(): ?string
    {
        return $this->getMessage();
    }

    #[Groups(['jsonld'])]
    #[ApiProperty(writable: false, initializable: false)]
    public function getDescription(): string
    {
        return $this->getMessage();
    }

    #[Groups(['jsonld', 'jsonproblem', 'jsonapi'])]
    public function getStatus(): ?int
    {
        return 422;
    }

    #[Groups(['jsonld', 'jsonproblem', 'jsonapi'])]
    #[ApiProperty(writable: false, initializable: false)]
    public function getInstance(): ?string
    {
        return null;
    }

    public function getStatusCode(): int
    {
        return 422;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
