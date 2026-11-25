<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Conversation\ConversationTurnService;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /conversations/{id}/messages` (`conversation_turn`).
 *
 * The operation loads the conversation (`read: true`) and has already
 * checked `neuronai:conversation:turn_own` on it; this processor validates the raw body
 * against `Entity/Conversation/turn.input.json` (400 malformed JSON, 422
 * schema violation, also for a `maxToolCalls` below 1 or not an integer
 * and for `terminalTools` that are not unique non-empty strings)
 * and hands it to the turn service, which answers 409
 * while another turn holds the lock and 422 for an archived conversation,
 * dangling references or a terminal tool name that is not an offered tool. Returns the final assistant message (201).
 *
 * A replay conversation (`origin` `replay`) whose replay is still pending
 * or running answers 422 with code `conversation_closed` before the turn
 * service runs ({@see ConversationReplayGuard});
 * the replay job calls the turn service directly and is not affected.
 *
 * @implements ProcessorInterface<Conversation, Message>
 */
final class ConversationTurnProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ConversationTurnService $turnService,
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly ConversationReplayGuard $replayGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Message
    {
        if (!$data instanceof Conversation) {
            throw new NotFoundHttpException('Not Found');
        }

        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? $request->getContent() : '';

        $input = $this->inputBodyValidator->validate(Conversation::OPERATION_TURN, $body);

        $this->replayGuard->assertAcceptsManualTurn($data);

        /** @var array<string, mixed>|null $responseSchema the schema requires an object */
        $responseSchema = $input['responseSchema'] ?? null;

        // The schema admits integers only; JSON `3.0` decodes as float.
        $maxToolCalls = $input['maxToolCalls'] ?? null;

        /** @var list<string>|null $terminalTools the schema requires unique non-empty strings */
        $terminalTools = $input['terminalTools'] ?? null;

        return $this->turnService->turn($data, (string) $input['content'], $responseSchema, null === $maxToolCalls ? null : (int) $maxToolCalls, $terminalTools);
    }
}
