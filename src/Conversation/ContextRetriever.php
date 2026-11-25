<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Retrieval\CollectionRetriever;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\RAG\Document;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Context of a turn from the knowledge collections of a conversation (or of
 * a stateless chat request).
 *
 * The collections are given as entities or as stored UUID strings
 * ({@see loadCollections()}, unknown id → 422). The query is the user
 * message, with earlier user messages of the turn when
 * `turn.retrieval_history_messages` is set ({@see query()}); {@see CollectionRetriever} searches each collection with its own
 * embedding model and merges the hits by score. The merged list is then
 * capped: at most `turn.context_max_chunks` chunks with at most
 * `turn.context_max_chars` characters of content in total, in ranking
 * order. Only the chunks that pass the cap are sent and reported as sources.
 * Without collections there is no retrieval at all.
 *
 * {@see pool()} is the same search without the cap (the evaluation's
 * retrieval measurement ranks against it); {@see retrieve()} is exactly
 * `expand(cap(pool(...)))`, so production and measurement share the search. With
 * several collections the pool is cut to the depth after the merge, not per
 * collection.
 *
 * `$hybrid` and `$hybridWeight` (set by agent versions only) pass
 * through to {@see CollectionRetriever::search()}: keyword and vector lists
 * fused by weighted RRF, then cut to the depth. With hybrid on, the
 * {@see ContextChunk::$score} of every chunk is the RRF score instead of a
 * cosine similarity; the cap does not look at scores.
 *
 * Context shaping: an agent version passes its own
 * {@see ContextLimits} to {@see retrieve()}; the hits are capped with them
 * and then widened by neighbour passages within the separate expansion
 * budget ({@see ContextExpander}). Without limits (paths without an agent)
 * the bundle cap applies and nothing is expanded. {@see pool()} is neither
 * capped nor expanded, so a neighbour never turns a measured miss into a
 * hit. The instruction text in front of the context block is the caller's
 * ({@see instructionsFor()}): {@see LEGACY_INSTRUCTION} for versions stored
 * without context shaping keys, the bundle's `turn.context_instruction` (default
 * {@see DEFAULT_INSTRUCTION}, {@see defaultInstruction()}) for paths
 * without an agent, or a version's `contextInstructions` text.
 */
final class ContextRetriever
{
    /**
     * The RAG instruction from before context shaping, kept byte for byte for agent
     * versions stored without `contextInstructions`.
     */
    public const string LEGACY_INSTRUCTION = 'Use the following context from the knowledge collections to answer. If the context does not contain the answer, say so. Reference sources as [n].';

    /**
     * Default of the bundle option `turn.context_instruction`: answer from
     * the context only and in the language of the question.
     */
    public const string DEFAULT_INSTRUCTION = 'Answer only from the following context from the knowledge collections. If the context does not contain the answer, say so instead of using other knowledge. Answer in the language of the question. Reference sources as [n].';

    /**
     * @param int    $maxChunks          bundle cap `turn.context_max_chunks`, used without {@see ContextLimits}
     * @param int    $maxChars           bundle cap `turn.context_max_chars`, used without {@see ContextLimits}
     * @param string $defaultInstruction bundle option `turn.context_instruction`, for paths without an agent
     * @param int    $historyMessages    bundle option `turn.retrieval_history_messages`: earlier user messages in the search text ({@see query()})
     */
    public function __construct(
        private readonly CollectionRetriever $retriever,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $maxChunks = 8,
        private readonly int $maxChars = 6000,
        private readonly ContextExpander $expander = new ContextExpander(),
        private readonly string $defaultInstruction = self::DEFAULT_INSTRUCTION,
        private readonly int $historyMessages = 0,
    ) {
    }

    /**
     * The search text of a turn: the user message and up to
     * `turn.retrieval_history_messages` earlier user messages of $history
     * ({@see RetrievalQuery}).
     *
     * @param list<\NeuronAI\Chat\Messages\Message> $history
     */
    public function query(string $content, array $history): string
    {
        return RetrievalQuery::of($content, $history, $this->historyMessages);
    }

    /**
     * The instruction text of paths without an agent (stateless chat,
     * conversations without an agent): the bundle option
     * `turn.context_instruction`.
     */
    public function defaultInstruction(): string
    {
        return $this->defaultInstruction;
    }

    /**
     * Loads Collection entities for full UUID strings (order kept, duplicates
     * removed). Entities are passed through.
     *
     * @param iterable<Collection|string> $collections
     *
     * @return list<Collection>
     *
     * @throws UnprocessableEntityHttpException for an invalid or unknown id
     */
    public function loadCollections(iterable $collections): array
    {
        $loaded = [];
        foreach ($collections as $collection) {
            if (!$collection instanceof Collection) {
                $id = (string) $collection;
                if (!Uuid::isValid($id)) {
                    throw new UnprocessableEntityHttpException(sprintf('Invalid collection id "%s".', $id));
                }
                $collection = $this->entityManager->find(Collection::class, Uuid::fromString($id))
                    ?? throw new UnprocessableEntityHttpException(sprintf('Collection "%s" does not exist (any more).', $id));
            }
            $loaded[(string) $collection->getId()] = $collection;
        }

        return array_values($loaded);
    }

    /**
     * Retrieves, caps and expands the context chunks for $query:
     * {@see ContextExpander::expand()} of {@see cap()} of {@see pool()}, with
     * the expansion budget of $limits.
     *
     * @param list<Collection>   $collections  empty = no retrieval
     * @param bool               $hybrid       keyword plus vector search, fused ({@see CollectionRetriever::search()})
     * @param float              $hybridWeight keyword share of the fusion, 0..1
     * @param ContextLimits|null $limits       the agent version's limits; null = the bundle cap, no expansion
     *
     * @return list<ContextChunk> the chunks to send, in ranking order
     */
    public function retrieve(string $query, array $collections, int $depth, bool $hybrid = false, float $hybridWeight = 0.5, ?ContextLimits $limits = null): array
    {
        return $this->expander->expand(
            $this->cap($this->pool($query, $collections, $depth, $hybrid, $hybridWeight), $limits),
            $limits?->expansionMaxChars ?? 0,
        );
    }

    /**
     * The uncapped search result for $query: one query embedding per
     * distinct embedding model, one store query per collection, merged by
     * score descending and cut to $depth after the merge
     * ({@see CollectionRetriever::search()}); with $hybrid the keyword and
     * vector lists fused by weighted RRF, cut to $depth after the fusion.
     *
     * @param list<Collection> $collections  empty = no retrieval
     * @param bool             $hybrid       keyword plus vector search, fused; scores are RRF scores then
     * @param float            $hybridWeight keyword share of the fusion, 0..1
     *
     * @return list<ContextChunk> at most $depth chunks, in ranking order; [] without collections or with $depth < 1
     */
    public function pool(string $query, array $collections, int $depth, bool $hybrid = false, float $hybridWeight = 0.5): array
    {
        if ([] === $collections || $depth < 1) {
            return [];
        }

        $names = [];
        foreach ($collections as $collection) {
            $names[(string) $collection->getId()] = $collection->getName();
        }

        return array_map(
            static function (Document $document) use ($names, $query): ContextChunk {
                $chunk = ContextChunk::fromDocument($document, $names);

                return $chunk->withQuote(SourceLocator::questionQuote($chunk->content, $query, $chunk->breadcrumb, $chunk->title));
            },
            $this->retriever->search($query, $collections, $depth, [], $hybrid, $hybridWeight),
        );
    }

    /**
     * Applies the context cap: chunks in ranking order until the chunk count
     * or the character budget is reached. A first chunk that alone exceeds
     * the budget is shortened to it, so a turn never loses all context to
     * one oversized chunk.
     *
     * @param list<ContextChunk> $chunks
     * @param ContextLimits|null $limits chunk count and character budget; null = the bundle cap
     *
     * @return list<ContextChunk>
     */
    public function cap(array $chunks, ?ContextLimits $limits = null): array
    {
        $maxChunks = $limits?->maxChunks ?? $this->maxChunks;
        $maxChars = $limits?->maxChars ?? $this->maxChars;
        $kept = [];
        $chars = 0;

        foreach ($chunks as $chunk) {
            if (\count($kept) >= $maxChunks) {
                break;
            }

            $length = mb_strlen($chunk->content);
            if ($chars + $length > $maxChars) {
                if ([] === $kept) {
                    $kept[] = $chunk->withContent(mb_substr($chunk->content, 0, $maxChars));
                }
                break;
            }

            $kept[] = $chunk;
            $chars += $length;
        }

        return $kept;
    }

    /**
     * The context section of the system prompt for the given chunks: the
     * instruction text, then the numbered chunks in a `<context>` block;
     * empty string without chunks. The text is used literally (no
     * placeholders).
     *
     * @param list<ContextChunk> $chunks
     * @param string             $instruction the resolved instruction text ({@see TurnRequest::$contextInstruction})
     */
    public static function instructionsFor(array $chunks, string $instruction = self::LEGACY_INSTRUCTION): string
    {
        if ([] === $chunks) {
            return '';
        }

        $blocks = [];
        foreach ($chunks as $i => $chunk) {
            $blocks[] = sprintf("[%d] (%s)\n%s", $i + 1, $chunk->sourceUrl ?? $chunk->filename ?? 'unknown', $chunk->content);
        }

        return $instruction."\n\n<context>\n".implode("\n\n", $blocks)."\n</context>";
    }
}
// - revised 2026-11-09
