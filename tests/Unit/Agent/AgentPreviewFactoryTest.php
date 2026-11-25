<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:10:57 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Agent\AgentConfigurationValidator;
use Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ModelListerInterface;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of {@see AgentPreviewFactory}: the hash that
 * identifies a preview is a sha256 in hex, stable under the key order of
 * objects and for `{}` versus `[]` (PHP decodes both to the same empty
 * array), sensitive to the order of lists and to every changed value; a
 * disabled agent and a structurally invalid candidate are refused before
 * anything is read or written. Creation and reuse against the database are
 * covered by the integration test `AgentPreviewVersionTest`.
 */
final class AgentPreviewFactoryTest extends TestCase
{
    public function testHashIsASha256InHex(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', AgentPreviewFactory::hash(self::configuration()));
    }

    public function testHashIgnoresTheKeyOrderOfObjects(): void
    {
        $ordered = self::configuration([
            'instructions' => ['de' => 'Hallo.', 'en' => 'Hello.'],
            'responseSchema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']], 'required' => ['a']],
            'modelOptions' => ['temperature' => 0.2, 'maxTokens' => 100],
            'languages' => ['de', 'en'],
        ]);
        $shuffled = self::configuration([
            'languages' => ['de', 'en'],
            'modelOptions' => ['maxTokens' => 100, 'temperature' => 0.2],
            'responseSchema' => ['required' => ['a'], 'properties' => ['b' => ['type' => 'integer'], 'a' => ['type' => 'string']], 'type' => 'object'],
            'instructions' => ['en' => 'Hello.', 'de' => 'Hallo.'],
        ]);

        self::assertSame(AgentPreviewFactory::hash($ordered), AgentPreviewFactory::hash($shuffled));
    }

    public function testHashIsTheSameForAnEmptyObjectAndAnEmptyList(): void
    {
        $asObject = self::configuration(json_decode('{"responseSchema": {"type": "object", "properties": {}}, "modelOptions": {}, "templates": {}}', true, 512, \JSON_THROW_ON_ERROR));
        $asList = self::configuration(json_decode('{"responseSchema": {"type": "object", "properties": []}, "modelOptions": [], "templates": []}', true, 512, \JSON_THROW_ON_ERROR));

        self::assertSame(AgentPreviewFactory::hash($asObject), AgentPreviewFactory::hash($asList));
    }

    public function testHashChangesWithTheCandidate(): void
    {
        $base = AgentPreviewFactory::hash(self::configuration(['languages' => ['de', 'en']]));

        self::assertNotSame($base, AgentPreviewFactory::hash(self::configuration(['languages' => ['de', 'en'], 'maxToolCalls' => 4])));
        self::assertNotSame($base, AgentPreviewFactory::hash(self::configuration(['languages' => ['de', 'en'], 'instructions' => ['de' => 'Anders.']])));
        // Lists keep their order: the order of languages is part of the configuration.
        self::assertNotSame($base, AgentPreviewFactory::hash(self::configuration(['languages' => ['en', 'de']])));
    }

    public function testHashIsStableAcrossCalls(): void
    {
        self::assertSame(AgentPreviewFactory::hash(self::configuration()), AgentPreviewFactory::hash(self::configuration()));
    }

    public function testDisabledAgentIsRefusedBeforeTheCandidateIsChecked(): void
    {
        $agent = new Agent()->setName('Support')->setSlug('support')->setEnabled(false)->setCandidate(['maxToolCalls' => 'many']);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Agent "support" is disabled.');

        $this->factory()->previewFor($agent, 'alice');
    }

    public function testInvalidCandidateIsRejectedAtTheCandidatePath(): void
    {
        $agent = new Agent()->setName('Support')->setSlug('support')->setCandidate(['maxToolCalls' => 'many']);

        try {
            $this->factory()->previewFor($agent, 'alice');
            self::fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $paths = [];
            foreach ($e->getConstraintViolationList() as $violation) {
                $paths[] = $violation->getPropertyPath();
            }
            self::assertContains('candidate/maxToolCalls', $paths);
        }
    }

    /**
     * A structurally valid configuration with the given overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private static function configuration(array $overrides = []): AgentConfiguration
    {
        return AgentConfiguration::fromArray($overrides + [
            'instructions' => ['de' => 'Du bist hilfreich.'],
            'model' => '0199a0b1-0000-7000-8000-000000000001',
            'languages' => ['de'],
            'defaultLanguage' => 'de',
        ]);
    }

    /**
     * A factory whose entity manager must not be used: both refusals happen
     * before any lookup.
     */
    private function factory(): AgentPreviewFactory
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');
        $entityManager->expects(self::never())->method('wrapInTransaction');
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $iriConverter = $this->createStub(IriConverterInterface::class);
        $uuidResolver = $this->createStub(UuidResolver::class);
        $connectionResolver = new McpConnectionResolver($entityManager, $iriConverter, $uuidResolver);

        return new AgentPreviewFactory($entityManager, new AgentConfigurationValidator(
            new ProviderModelResolver($iriConverter, $uuidResolver),
            new ModelAvailabilityCheck($this->createStub(ModelListerInterface::class)),
            new ConversationReferences($entityManager, $connectionResolver),
            $connectionResolver,
            $entityManager,
        ));
    }
}
