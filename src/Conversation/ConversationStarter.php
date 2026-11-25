<?php

// file generated with AI assistance: Claude Code - 2026-10-14 01:10:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Stream\DiscardingSink;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Starts the first turn of a new agent conversation whose version has a
 * `startMessage`, so the agent acts before the user types anything.
 *
 * Two halves in the request of `POST /conversations`:
 *
 *  - {@see start()}, called by the create processor after the conversation
 *    is stored, renders the start message for the conversation's language
 *    and variables and runs {@see StreamingTurnService::begin()}: the user
 *    record and the `streaming` placeholder are written and the turn lock
 *    is taken, all before the 201 answer is sent;
 *  - on `kernel.terminate`, after the answer has gone out
 *    (`fastcgi_finish_request()` under php-fpm), the turn runs to its end
 *    with a {@see DiscardingSink}; its events are stored as for any streamed
 *    turn, and the client follows it with `GET /conversations/{id}/stream`.
 *
 * Why in the request and not in a worker: begin() captures the caller's JWT
 * for MCP connections with `forward_jwt` (a worker has none, so their write
 * tools would be dropped), and the turn lock is a session lock of the
 * database connection, so begin() and run() must share one process. The
 * request therefore has to be served by a pool without a run-time limit
 * (Caddy routes `POST /api/conversations` to the stream pool).
 *
 * A start that fails in begin() (e.g. a stale reference of the version)
 * leaves the conversation without a first turn and is logged; the creation
 * itself has succeeded and is not undone.
 */
final class ConversationStarter implements EventSubscriberInterface
{
    /** @var list<StreamingTurn> */
    private array $pending = [];

    public function __construct(
        private readonly VersionTurnMapper $mapper,
        private readonly StreamingTurnService $streaming,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'runPending'];
    }

    /**
     * Prepares the start turn of $conversation; true when one was prepared.
     */
    public function start(Conversation $conversation): bool
    {
        $version = $conversation->getAgentVersion();
        if (null === $version) {
            return false;
        }

        try {
            // Without MCP tools: only the start message is needed here; the
            // turn builds its tools in begin().
            $message = $this->mapper->map($version, $conversation->getVariables() ?? [], $conversation->getLanguage(), withMcpTools: false)->startMessage;
            if (null === $message) {
                return false;
            }
            $this->pending[] = $this->streaming->begin($conversation, $message);
        } catch (\Throwable $e) {
            $this->logger->warning('Start turn of conversation {conversation} not started: {error}', [
                'conversation' => (string) $conversation->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Runs the prepared start turns; on `kernel.terminate`, after the
     * answer has been sent.
     */
    public function runPending(): void
    {
        $turns = $this->pending;
        $this->pending = [];
        if ([] === $turns) {
            return;
        }

        ignore_user_abort(true);
        set_time_limit(0);
        // The Symfony runtime finishes the FastCGI request only without
        // debug (HttpKernelRunner); in debug the client would wait for the
        // whole turn. The answer has been sent at kernel.terminate, so the
        // request is finished here in either mode.
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        foreach ($turns as $turn) {
            try {
                $turn->run(new DiscardingSink());
            } catch (\Throwable $e) {
                // run() records failures as an error event and record
                // itself; this only guards the remaining turns.
                $this->logger->error('Start turn failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
