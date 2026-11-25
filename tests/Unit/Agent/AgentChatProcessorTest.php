<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:45:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentConfigurationValidator;
use Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Chat\AgentChat;
use Dmstr\NeuronAiBundle\Chat\AgentChatResult;
use Dmstr\NeuronAiBundle\Chat\ChatService;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ModelListerInterface;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Dmstr\NeuronAiBundle\State\AgentChatProcessor;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Kernel-free checks of the target choice of `POST /agents/{id}/chat`:
 * `target: candidate` without the permission `neuronai:agent:chat_candidate` answers 403 naming the
 * role before the agent's state is looked at (also for a disabled agent)
 * and before the preview factory; with the role the agent reaches the
 * factory (shown by its refusal of an invalid candidate at
 * `candidate/<field>`); the published target does not ask for the role.
 * All paths here end before the mapper and the chat service; the turn on
 * a preview is covered by the integration test `AgentChatCandidateTest`.
 */
final class AgentChatProcessorTest extends TestCase
{
    private const string ENTITY_ROOT = __DIR__.'/../../../src/Entity';

    /** @var list<string> roles the authorization checker was asked for */
    private array $asked = [];

    public function testCandidateWithoutTheAgentAdminRoleIs403(): void
    {
        $agent = self::agent(['maxToolCalls' => 'many']);

        try {
            $this->chat($this->processor(agentAdmin: false), $agent, 'candidate');
            self::fail('Expected a 403.');
        } catch (AccessDeniedHttpException $e) {
            self::assertSame(403, $e->getStatusCode());
            self::assertSame('Chatting with the candidate of an agent (target "candidate") requires the permission neuronai:agent:chat_candidate.', $e->getMessage());
        }
        self::assertSame([Permissions::AGENT_CHAT_CANDIDATE], $this->asked);
    }

    public function testRoleIsCheckedBeforeTheAgentIsDisabled(): void
    {
        $agent = self::agent(['maxToolCalls' => 'many'])->setEnabled(false);

        $this->expectException(AccessDeniedHttpException::class);

        $this->chat($this->processor(agentAdmin: false), $agent, 'candidate');
    }

    public function testCandidateWithTheRoleReachesThePreviewFactory(): void
    {
        $agent = self::agent(['maxToolCalls' => 'many']);

        try {
            $this->chat($this->processor(agentAdmin: true), $agent, 'candidate');
            self::fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $paths = [];
            foreach ($e->getConstraintViolationList() as $violation) {
                $paths[] = $violation->getPropertyPath();
            }
            self::assertContains('candidate/maxToolCalls', $paths);
        }
    }

    public function testDisabledAgentWithTheRoleIs422(): void
    {
        $agent = self::agent(['maxToolCalls' => 'many'])->setEnabled(false);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Agent "support" is disabled.');

        $this->chat($this->processor(agentAdmin: true), $agent, 'candidate');
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function publishedTargets(): iterable
    {
        yield 'target omitted' => [null];
        yield 'target published' => ['published'];
    }

    /**
     * The published target needs no extra role: an agent without a
     * current version gets the published-version refusal, not a 403.
     */
    #[DataProvider('publishedTargets')]
    public function testPublishedTargetDoesNotAskForTheRole(?string $target): void
    {
        $agent = self::agent([]);

        try {
            $this->chat($this->processor(agentAdmin: false), $agent, $target);
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertSame('Agent "support" has no published version.', $e->getMessage());
        }
        self::assertSame([], $this->asked);
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private static function agent(array $candidate): Agent
    {
        return new Agent()->setName('Support')->setSlug('support')->setCandidate($candidate);
    }

    private function chat(AgentChatProcessor $processor, Agent $agent, ?string $target): AgentChatResult
    {
        $body = ['messages' => [['role' => 'user', 'content' => 'Hallo?']]];
        if (null !== $target) {
            $body['target'] = $target;
        }
        $request = Request::create('/api/agents/'.$agent->getId().'/chat', 'POST', content: json_encode($body, \JSON_THROW_ON_ERROR));

        return $processor->process($agent, new Post(name: Agent::OPERATION_CHAT), [], ['request' => $request]);
    }

    /**
     * A processor whose entity manager must not be written: every path
     * here ends before a preview is stored. The mapper and the chat service
     * are never reached.
     */
    private function processor(bool $agentAdmin): AgentChatProcessor
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $iriConverter = $this->createStub(IriConverterInterface::class);
        $uuidResolver = $this->createStub(UuidResolver::class);
        $connectionResolver = new McpConnectionResolver($entityManager, $iriConverter, $uuidResolver);
        $previewFactory = new AgentPreviewFactory($entityManager, new AgentConfigurationValidator(
            new ProviderModelResolver($iriConverter, $uuidResolver),
            new ModelAvailabilityCheck($this->createStub(ModelListerInterface::class)),
            new ConversationReferences($entityManager, $connectionResolver),
            $connectionResolver,
            $entityManager,
        ));

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnCallback(function (mixed $attribute) use ($agentAdmin): bool {
            $this->asked[] = (string) $attribute;

            return $agentAdmin;
        });

        $roles = $agentAdmin ? ['ROLE_USER', 'ROLE_AGENT_ADMIN'] : ['ROLE_USER'];
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null, $roles), 'api', $roles));

        return new AgentChatProcessor(
            new InputBodyValidator(new OperationInputSchemaResolver([], [[self::ENTITY_ROOT, 'Dmstr\\NeuronAiBundle\\Entity']])),
            new AgentChat(
                new \ReflectionClass(VersionTurnMapper::class)->newInstanceWithoutConstructor(),
                $this->createStub(ChatService::class),
                $iriConverter,
                $previewFactory,
                $tokenStorage,
                $authorizationChecker,
            ),
        );
    }
}

// - revised 2026-11-20
