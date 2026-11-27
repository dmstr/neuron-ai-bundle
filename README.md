<!-- file generated with AI assistance: Claude Code - 2026-11-25 21:28:51 UTC -->

# dmstr/neuron-ai-bundle

Symfony bundle integrating the [Neuron AI](https://github.com/neuron-core/neuron-ai) framework into an API Platform application. It provides:

- chat and embedding providers built from provider connections (`ollama`, `anthropic`) of dmstr/api-configuration-bundle, managed at runtime, with a model catalogue per connection;
- an embedding model registry with per-model task prefixes and model-agnostic pgvector store wiring ([dmstr/neuron-pgvector-store](https://github.com/dmstr/neuron-pgvector-store)), with a reindex path per collection and hybrid (keyword plus vector) search;
- knowledge collections as an API Platform resource, with lock, delete with content and reindex;
- MCP servers as the `mcp` connection type, a catalogue of their tools, and the knowledge base served as RAG tools over MCP (with dmstr/mcp-endpoint-bundle);
- persisted conversations with streaming, resume and cancel, including anonymous conversations with a capability token, limits and a scheduled purge;
- versioned agents with publish, rollback and preview versions, pre-answer rules, output templates and answer gates, an agent chat, an OpenAI-compatible chat API and an agent runner for turns without a request, with an optional per-run tool argument guard;
- an evaluation of agent versions (batch runs, retrieval metrics, an AI judge, human ratings, run comparison, conversation replay) and feedback;
- a stateless chat service on the same turn runner, artifact tools for agents, and kernel-free document extraction and heading-aware chunking;
- test seams (`Dmstr\NeuronAiBundle\Testing`), so no test of a host application contacts a model server or an MCP server.

The bundle owns only its `neuron_*` tables. The documents and chunks of a collection and the vector store table belong to the host application, which connects them to the bundle through a few ports (see [Host application ports](#host-application-ports)).

## Requirements

- PHP >= 8.5 with `ext-json`, `ext-mbstring` and `ext-pdo`
- Symfony 7.4 (FrameworkBundle, SecurityBundle, Messenger, Scheduler, RateLimiter, Lock), Doctrine ORM 3 with DBAL 3 and DoctrineMigrationsBundle, `gedmo/doctrine-extensions` (the audited fields are `#[Gedmo\Versioned]`)
- PostgreSQL with [pgvector](https://github.com/pgvector/pgvector) >= 0.8, reached through the host's DBAL connection (so `ext-pdo_pgsql`); the bundle supports no other database
- API Platform `^4.4.8`
- [`neuron-core/neuron-ai`](https://github.com/neuron-core/neuron-ai) `>=4.1.11 <4.1.17`: 4.1.17 adds `ClosesOpenTurn`, which inserts a synthetic assistant message when a turn that ended in a terminal tool is replayed and so changes the history the provider sees; that is not supported yet
- [`dmstr/neuron-pgvector-store`](https://github.com/dmstr/neuron-pgvector-store)
- The dmstr bundles it builds on, installed as dependencies: [`dmstr/api-configuration-bundle`](https://github.com/dmstr/api-configuration-bundle) (connections), [`dmstr/api-platform-utils-bundle`](https://github.com/dmstr/api-platform-utils-bundle) (secret encryption), [`dmstr/openapi-json-schema-bundle`](https://github.com/dmstr/openapi-json-schema-bundle) (input schemas) and [`dmstr/symfony-job-queue-bundle`](https://github.com/dmstr/symfony-job-queue-bundle) (background jobs on Messenger)
- `symfony/lock` (required): the creation limit of anonymous conversations, the artifact quota and the `neuron` schedule use `lock.factory`; configure a store shared by all PHP processes
- An implementation of `Dmstr\NeuronAiBundle\Reindex\ReindexSourceInterface`, see [Host application ports](#host-application-ports)
- A security voter for the bundle's permissions, see [Permissions](#permissions)
- Optional: [`dmstr/mcp-endpoint-bundle`](https://github.com/dmstr/mcp-endpoint-bundle) to serve the RAG tools over MCP, `lexik/jwt-authentication-bundle` for JWT authentication next to capability tokens and for `forward_jwt` MCP connections, `phpunit/phpunit` for the test helpers in `src/Testing/` (none of them is registered as a service)

## Installation

```bash
composer require dmstr/neuron-ai-bundle
```

Register the bundle in `config/bundles.php` after the bundles it builds on (Symfony Flex adds most of them):

```php
Dmstr\NeuronAiBundle\DmstrNeuronAiBundle::class => ['all' => true],
```

The bundle prepends its Doctrine ORM mapping (alias `DmstrNeuronAi`), its API Platform resource paths, its migrations path (namespace `Dmstr\NeuronAiBundle\Migrations`), the validation mapping `config/validator/ApiConfiguration.xml`, and the cache pool `cache.neuron_provider_tokens`, so the host application configures none of them. Import the plain Symfony routes (streamed turn and resume, the single execution of an evaluation question, the OpenAI-compatible chat API) below the API prefix:

```yaml
# config/routes/dmstr_neuron_ai.yaml
dmstr_neuron_ai:
    resource: '@DmstrNeuronAiBundle/config/routes.php'
    prefix: /api
```

Run the migrations afterwards (see [Migrations](#migrations)):

```bash
bin/console doctrine:migrations:migrate --no-interaction
```

One piece of wiring is always the host's: it must alias `Dmstr\NeuronAiBundle\Reindex\ReindexSourceInterface` to its own implementation, because the bundle does not know where the documents of a collection live; the container does not compile without it (see [Host application ports](#host-application-ports) and [Reindex to another model](#reindex-to-another-model)). Anonymous conversations need more application wiring (an access policy, the security firewall, a cache pool, a worker consuming `scheduler_neuron`); see [Anonymous access](#anonymous-access). Agents need the api firewall to cover their routes and, optionally, an alias for the application's limit defaults; see [Agent application wiring](#agent-application-wiring). Evaluation and feedback need the api firewall, the permission map and an alias for the application's evaluation settings; see [Evaluation application wiring](#evaluation-application-wiring).

The bundles this one builds on expect a few things from the host application that they do not register themselves, among them the encryption key `dmstr_api_platform_utils.credential_encryption.key` (see [Connection secrets and encryption](#connection-secrets-and-encryption)); see their READMEs.

## Host application ports

The bundle declares ten ports for what only the host application knows. One is mandatory, the others have a default or are optional (the bundle injects them with `nullOnInvalid()` and degrades as described):

| Port | Required | Without an alias |
| --- | --- | --- |
| `Reindex\ReindexSourceInterface` | **yes** | the container does not compile (no default, no `nullOnInvalid()`); enumerates and embeds the documents of a collection for the reindex, see [Reindex to another model](#reindex-to-another-model) |
| `Knowledge\CollectionContentPurgerInterface` | no | only empty collections can be deleted (409 otherwise), see [Deleting a collection](#deleting-a-collection) |
| `Mcp\Server\CollectionDocumentCounterInterface` | no, default `Mcp\Server\CollectionDocumentCounter` | `documentCount` of `rag_list_collections` is counted in the store table and `chunkCount` is unknown, see [Tools](#tools) |
| `Retrieval\NeighbourSourceInterface` | no | the neighbour expansion and the neighbours of `rag_get_context` are a no-op, see [Neighbour expansion](#neighbour-expansion) |
| `Retrieval\SourceFreshnessInterface` | no | the sources of the agent chat carry no `checkedAt` and `changedAt`, see [Agent chat](#agent-chat) |
| `Anonymous\AnonymousAccessPolicyInterface` | no | anonymous creation and anonymous turns answer 503, see [Access policy](#access-policy) |
| `Agent\AgentLimitDefaultsInterface` | no | no application default between the version and the bundle default, see [Limit defaults](#limit-defaults) |
| `Agent\Preview\AgentPreviewRetentionInterface` | no | previews are never purged, every purge run logs a warning, see [Preview versions](#preview-versions) |
| `Artifact\ArtifactLimitsInterface` | no, default `Artifact\DefaultArtifactLimits` | the bundle's artifact limits apply, see [Artifact tools](#artifact-tools) |
| `Evaluation\EvaluationSettingsInterface` | no | no judge, no human rating schema and no human rating comparison; feedback uses the bundle default of 30 submissions per subject and hour, see [Evaluation settings](#evaluation-settings) |

All names are relative to `Dmstr\NeuronAiBundle\`. A minimal host aliases the mandatory port only:

```yaml
# config/services.yaml
services:
    Dmstr\NeuronAiBundle\Reindex\ReindexSourceInterface: '@Acme\Knowledge\DocumentReindexSource'
```

Further interfaces with a bundle default can be replaced for tests or other transports: `Mcp\McpTransportFactoryInterface`, `Security\CurrentJwtProviderInterface`, `Provider\ModelListerInterface`, `Provider\ModelDescriberInterface` and the service `ProviderFactory` (see [Test seams](#test-seams)).

## Configuration

```yaml
dmstr_neuron_ai:
    embeddings:
        # Registry of the embedding models a collection may use. The default
        # map is shown; setting `models` replaces it as a whole.
        models:
            nomic-embed-text:
                provider: 'ollama'
                dimension: 768
                query_prefix: 'search_query: '
                document_prefix: 'search_document: '
                context_length: null
            mxbai-embed-large:
                provider: 'ollama'
                dimension: 1024
                query_prefix: 'Represent this sentence for searching relevant passages: '
                document_prefix: ''
                context_length: null
            # Multilingual (100+ languages), no task prefixes. The best of the
            # three on German text, see "Choosing a model" below.
            bge-m3:
                provider: 'ollama'
                dimension: 1024
                query_prefix: ''
                document_prefix: ''
                context_length: null
    store:
        table: 'neuron_documents'
        top_k: 4
    # Limits of a conversation turn and of the stateless chat.
    turn:
        deadline_seconds: 120
        context_max_chunks: 8
        context_max_chars: 6000
        # Instruction in front of the retrieved context for paths without an
        # agent and agent versions without a text of their own (default shown).
        context_instruction: 'Answer only from the following context from the knowledge collections. If the context does not contain the answer, say so instead of using other knowledge. Answer in the language of the question. Reference sources as [n].'
        # Earlier user messages searched together with the current one
        # (0 = the message alone).
        retrieval_history_messages: 2
        history_context_window: 200000
        # Executed tool calls per turn after which the model must answer
        # without tools; leave the key out for unbounded (the default).
        # max_tool_calls_default: 8
    # Model catalogue of the provider connections.
    provider_models:
        # Ollama `POST /show` calls per connection and scan (default shown).
        max_describe_calls: 200
    # Anonymous conversations (public creation with a capability token).
    anonymous:
        # Key of the HMAC over client addresses; empty refuses anonymous creation.
        address_hash_key: '%env(ANONYMOUS_ADDRESS_HASH_KEY)%'
        # Cache pool holding the creation counters (shared by all php containers).
        rate_limiter_cache_pool: 'cache.rate_limiter'
        # Interval of the scheduled purge (schedule `neuron`).
        purge_interval: '15 minutes'
    # Preview versions of agents (frozen candidates for test chats and evaluations).
    agent_previews:
        # Interval of the scheduled purge of previews past their retention (schedule `neuron`).
        purge_interval: '1 day'
    # Storage root of the artifact files (one folder per user); must exist and be writable.
    artifacts:
        root: '%kernel.project_dir%/var/artifacts'
    # OpenAI-compatible chat API: `owned_by` in the model list of GET /api/v1/models.
    openai_compatible:
        owned_by: 'dmstr'
```

`embeddings.models` is a map node and takes no env placeholder as a whole. `turn.max_tool_calls_default` is an integer node with minimum 1 and no default value: leave the key out for unbounded turns, because an explicit `null` is rejected by the integer node. The turn keys are described in [Conversations](#conversations), the `anonymous` keys in [Anonymous access](#anonymous-access). `provider_models.max_describe_calls` (integer, minimum 1, default 200) caps the Ollama `POST /show` calls per connection and describe call (see [Model descriptions](#model-descriptions)); models over the budget keep the values a catalogue already holds for them. `anonymous.purge_interval` and `agent_previews.purge_interval` are anything `RecurringMessage::every()` accepts: a relative date string (`15 minutes`), an ISO 8601 duration (`PT15M`) or seconds. The retention of previews is no bundle option but a runtime value of the application (see [Preview versions](#preview-versions)). `artifacts.root` is described in [Artifact tools](#artifact-tools). The value of `owned_by` in the model list of `GET /api/v1/models` is the option `dmstr_neuron_ai.openai_compatible.owned_by` (default `dmstr`, see [OpenAI-compatible chat API](#openai-compatible-chat-api)).

Chat and embedding endpoints, credentials and models are not configured here: endpoints and credentials are [provider connections](#provider-connections) (`ollama`, `anthropic`), and every agent candidate, chat request, conversation, anonymous release and collection names its model explicitly as an entry of the [model catalogue](#model-catalogue), which names its connection; there is no default provider, connection or model. MCP servers are `mcp` connections (see [MCP servers as the `mcp` connection type](#mcp-servers-as-the-mcp-connection-type)).

### Embedding model registry

The embedding model is a property of each [knowledge collection](#knowledge-collections), chosen as an entry of the [model catalogue](#model-catalogue) that names the `ollama` connection; `embeddings.models` lists the models a collection may use, with what the store, the retriever and the validator need to know about them (the endpoint is the entry's connection). A registered name is the vector label of the model: it matches the catalogue name exactly or with or without `:latest` (`EmbeddingModelRegistry::findEquivalent()`, `forEntry()`), so `nomic-embed-text` covers the entry `nomic-embed-text:latest`, and the store rows and indexes are keyed by the registered name. Each entry has five keys:

| Key | Meaning |
|---|---|
| `provider` | connection type that serves the model; only `ollama` exists so far (default); a collection's embedding connection must be of this type |
| `dimension` | length of the model's vectors, required, 1 to 2000 (the limit of HNSW indexes) |
| `query_prefix` | task prefix prepended to search queries, default `''` |
| `document_prefix` | task prefix prepended to indexed content, default `''` |
| `context_length` | context length in tokens, default `null` (provider default); sent to Ollama as `options.num_ctx` |

Validation rules:

- A model name (the map key) must match `^[A-Za-z0-9][A-Za-z0-9._:/-]*$` and have at most 190 characters, because the vector store inlines it into SQL; the dimension must be within 1..2000. Violations fail the configuration processing (container compilation) with "Invalid embedding models: …".
- `provider` accepts only `ollama`.

`models` has a default value rather than defaults per entry: an app that sets `models` replaces the default map as a whole, so repeat the `nomic-embed-text`, `mxbai-embed-large` and `bge-m3` entries if you add a model and still want them. Note the trailing space in the prefixes. A host application can also use a model's `context_length` as the length guard of the chunks it embeds (for example four characters per token).

**Choosing a model.** Retrieval quality depends on the embedding model more than on chunk size. On a German corpus (31 documents, 21 questions, plain vector search, `ollama pull` models, same chunking) the share of questions whose answer was the first hit was: `bge-m3` 19, `nomic-embed-text` 13, `mxbai-embed-large` 10. `nomic-embed-text` and `mxbai-embed-large` are trained mostly on English; `bge-m3` is multilingual and found paraphrased questions ("Gewährleistung" for "Garantie") that the others missed. Use `bge-m3` for German content. Chunk size 600 against 1500 gave two more first hits with `bge-m3` and halves the context sent to the model. The document title (`Document.metadata.title`) is written in front of the embedded text, so a chunk of a page without headings still carries what the document is about.

Changing a model's prefixes, provider or dimension changes its vectors: reindex the collections that use it (see [Reindex to another model](#reindex-to-another-model)).

### Store table

The store table (`store.table`) is model-agnostic: it holds the vectors of several models side by side in the dimensionless column `vector`, every row carries the name of its model in the column `model`, and every model gets one partial HNSW expression index, `idx_<table>_vector_<hash>` with `<hash>` being the first 12 hex characters of `sha1(<model>)`. The store API (`forModel()`, `ensureModelIndex()`, `deleteByModel()`, `countBy()`) is described in the [dmstr/neuron-pgvector-store README](https://github.com/dmstr/neuron-pgvector-store#readme). The service is built with the neuron-ai document schema `Embeddings\EmbeddingDocumentSchema`: the metadata keys `collection_id` and `topic` are filterable (the store adds its column `model`), so the collection scope of the retriever and the deletes per collection are validated against it, and a filter on any other key fails with a `DocumentSchemaException`. It requires pgvector >= 0.8 (for example the image `pgvector/pgvector:0.8.6-pg16`). Keyword search for [hybrid search](#hybrid-search) uses one full-text GIN expression index per language, `idx_<table>_fts_german` and `idx_<table>_fts_english`, on the same table; they cover all models, so no index is created at runtime for them.

The store table is created neither by the ORM nor by this bundle's migrations: it belongs to the host application (see [Migrations](#migrations)). The name comes from `store.table` (default `neuron_documents`, the default of dmstr/neuron-pgvector-store); an application whose vector rows are `Embedding` entities of its own typically sets `embedding`. The host's migrations create the table as `PgVectorStore::setupTable()` does, with the two full-text indexes and the vector index of its first model; in a standalone setup, call `setupTable()` once instead (it runs `CREATE EXTENSION IF NOT EXISTS vector` and creates the table with its metadata, source and full-text indexes, but no vector index). The vector indexes of the models are created at runtime by `Embeddings\EmbeddingIndexManager` (idempotent, `CREATE INDEX IF NOT EXISTS`):

- when a collection is created, by the entity listener `Doctrine\CollectionEmbeddingListener` (`postPersist`), so the first search of a new model already has an index;
- at the start of a reindex run, for the target model;
- by `bin/console neuron:embedding:index:sync`, which creates the missing indexes of all current and pending models of all collections and never drops one. Run it on deploy or after restoring a dump without the runtime-created indexes; it exits 1 when a collection uses a model that is not registered (the indexes of the registered models are created anyway).

The bundle registers a `postGenerateSchema` listener (`Doctrine\ExcludeStoreTableListener`) that removes this table from the schema the ORM generates, because the ORM schema tool ignores the DBAL `schema_filter` for mapped tables and would otherwise compare the table even when the app maps it onto a read-only entity. Exclude the table in the application's `doctrine.dbal.schema_filter` as well, so `doctrine:schema:update` does not propose to drop it.

### Container parameters

The bundle exposes these container parameters:

| Parameter | Type |
|---|---|
| `dmstr_neuron_ai.store.top_k` | int |
| `dmstr_neuron_ai.turn.deadline_seconds`, `.turn.context_max_chunks`, `.turn.context_max_chars`, `.turn.retrieval_history_messages`, `.turn.history_context_window` | int |
| `dmstr_neuron_ai.turn.context_instruction` | string |
| `dmstr_neuron_ai.turn.max_tool_calls_default` | int or null (null = unbounded) |
| `dmstr_neuron_ai.provider_models.max_describe_calls` | int |
| `dmstr_neuron_ai.anonymous.address_hash_key`, `.anonymous.rate_limiter_cache_pool`, `.anonymous.purge_interval` | string |
| `dmstr_neuron_ai.agent_previews.purge_interval` | string |
| `dmstr_neuron_ai.artifacts.root` | string |

The model map is not a parameter; read it through the `Embeddings\EmbeddingModelRegistry` service (`get($name)`, `has($name)`, `names()`, `all()`, `findEquivalent($catalogueName)`, `forEntry($entry)`).

## Knowledge collections

`Dmstr\NeuronAiBundle\Entity\Collection` (table `neuron_collection`) is a named set of documents that share one embedding space. Fields: `id` (UUID), `name` (unique, lowercase letters, digits and dashes, not starting with a dash, max. 190 characters), `description` (optional), `embeddingModel` (the catalogue entry, IRI), `embeddingModelName` (read-only, the registered name), `embeddingConnection` (read-only), `embeddingDimension`, `pendingEmbeddingModel`, `pendingEmbeddingModelName` and `pendingEmbeddingConnection` (read-only), `reindexJobId` (read-only), `keywordLanguage`, `lockedAt`, `lockedBy` and `locked` (read-only, see [Lock](#lock)), `createdAt`, `updatedAt`.

The API Platform resource lives under the `/admin` route prefix:

| Operation | Route | Access |
|---|---|---|
| list (30 per page, filters `?name=` and `?embeddingProviderConnection=` exact, `?search=` name contains the term, case-insensitive, `Filter\CollectionSearchFilter`, the type-ahead of the agent's collections picker) | `GET /api/admin/collections` | `neuronai:collection:read` |
| read | `GET /api/admin/collections/{id}` | `neuronai:collection:read` |
| create | `POST /api/admin/collections` | `neuronai:collection:write` |
| replace / update | `PUT`, `PATCH /api/admin/collections/{id}` | `neuronai:collection:write` |
| delete, with its content (see [Deleting a collection](#deleting-a-collection)) | `DELETE /api/admin/collections/{id}` | `neuronai:collection:write` |
| reindex to another model | `POST /api/admin/collections/{id}/reindex` | `neuronai:collection:write` |
| cancel the pending reindex | `POST /api/admin/collections/{id}/reindex/cancel` | `neuronai:collection:write` |
| lock | `POST /api/admin/collections/{id}/lock` | `neuronai:collection:lock` |
| unlock | `POST /api/admin/collections/{id}/unlock` | `neuronai:collection:unlock` |

The class-level constraint `Validator\ValidEmbeddingModel` checks the model reference against the model catalogue and the [registry](#embedding-model-registry) (`Embeddings\EmbeddingModelResolver`). There is no default model:

- `embeddingModel` is required on create: the IRI (`/api/refs/provider_models/<uuid>`) or the full or partial UUID of an entry of the [model catalogue](#model-catalogue), resolved by `Provider\ProviderModelResolver` with the capability `embedding`: available, on an active `ollama` [provider connection](#provider-connections), not known to lack `embedding`. Its name must match a registered model exactly or with or without `:latest`. Every problem answers 422 on `embeddingModel`; a missing reference and a name the registry does not know list the known models. A model name is no reference. Pick the entry from the field's `x-collection` (`ProviderModel::EMBEDDING_MODEL_COLLECTION_URL`, the available entries with `embedding` of active connections; never-described entries are not offered there, but a reference to them is accepted) or with `GET /api/refs/provider_models?apiConfiguration=<connection IRI>&name=nomic-embed-text:latest`.
- The collection stores the entry (`embedding_model_id`), its connection (`embedding_connection_id`) and the registered name as the label (`embedding_model`), all with foreign keys without cascade where they reference. The representation shows the entry's IRI as `embeddingModel`, the label as `embeddingModelName` and the connection's name as `embeddingConnection`; the last two are read-only, and a request that sends them changes nothing. Every collection has an entry (`embedding_model_id` is NOT NULL); a collection without a provider connection cannot get one, and the migration that introduced the column aborts while such a collection exists (see [Migrations](#migrations)).
- `embeddingDimension` is optional and derived from the registry. A given value must equal the registered dimension of the model, otherwise 422 on `embeddingDimension`.
- The model of a stored collection cannot be changed through `PUT` or `PATCH`: another entry, also one of the same label on another connection, answers 422 on `embeddingModel` with "Changing the embedding model requires a reindex: POST /api/admin/collections/{id}/reindex with the "model" to switch to.". Sending back the current entry (IRI or UUID, also after it became unavailable) is no change.
- `pendingEmbeddingModel` (the IRI of the target entry), `pendingEmbeddingModelName` and `pendingEmbeddingConnection` are the target of a running reindex and `reindexJobId` the id of the job of the last reindex (no foreign key); they are set by the reindex only.
- `keywordLanguage` is the PostgreSQL text search configuration of the collection's keyword part of [hybrid search](#hybrid-search): `german` (default for new collections) or `english`; any other value answers 422. It is writable on create, `PUT` and `PATCH`. A change needs no reindex, because the full-text indexes are per language expression and not stored per row: the next search uses the other index. The migration that added the column (`Version20261004090000`) set `english` on every collection that existed then; set `german` where such a collection holds German text. Vector-only searches ignore the field.

### Lock

A locked collection is a finished corpus that must not change, for example the knowledge base behind a published agent or an evaluation baseline. Locking freezes the collection and its content, while every read keeps working: listing, search, `rag_search` and the other RAG tools, retrieval in conversations and evaluation runs.

- **Lock:** `POST /api/admin/collections/{id}/lock` (`neuronai:collection:lock`, no body, 200 with the collection) sets `lockedAt` and `lockedBy`, the user identifier of the token (for a JWT its subject). Locking a locked collection changes nothing and answers 200, so a client can repeat the call safely.
- **Unlock:** `POST /api/admin/collections/{id}/unlock` (200 with the collection, idempotent as well) clears both fields. It requires the permission `neuronai:collection:unlock`, which the application maps separately from `neuronai:collection:lock` in `za7_core.permissions`, for example to a role held by a few named people but not by every administrator. Keeping lock and unlock apart means an administrator, or an agent acting with an administrator's token, can freeze a collection but cannot undo the freeze.
- **No unlock over MCP:** there is deliberately no MCP tool that unlocks a collection. The permission alone would not guarantee this, because a token that holds it also passes the MCP endpoints; the guarantee comes from the missing tool.
- **Audit:** `lockedAt` and `lockedBy` are `#[Gedmo\Versioned]` on the loggable entity, so every lock and unlock is in the audit log with its actor. `locked` is the derived flag (`lockedAt` is set). All three fields are read-only; a request that sends them changes nothing.

A write to a locked collection or to its content answers **423 Locked** with the message `Collection "<name>" is locked` (`Knowledge\CollectionLockedException`, an `HttpException`; API Platform renders a problem document, over MCP it becomes a tool error with the same message). 423 is used instead of the 409 of other refusals, because it tells a client exactly why the write failed and that retrying will not help until someone unlocks. The paths that refuse:

- the collection itself: `PUT`, `PATCH` and `DELETE`, and every other change through the EntityManager (console, application code), by the Doctrine entity listener `Doctrine\CollectionLockListener` (`preUpdate`, `preRemove`); only the lock fields themselves may change, so unlocking works;
- the content, through `Knowledge\CollectionLockGuard::assertWritable()`: the bundle calls it at the start of a reindex, and the host application calls it at each of its own content write paths (ingest, document upload, deletion of a document or chunk, extraction, re-embedding, import sinks; an import checks once per run and again for every item, so a lock set during an import stops it before the next item while the items imported so far stay).

The guard reads `locked_at` from the database row instead of the loaded entity, because a long-running messenger worker holds entities loaded before the lock was set or cleared; the row is the only state every process agrees on.

Background work follows one rule: what waits refuses, what runs finishes. The document jobs belong to the host application, which follows the same rule.

- **Waiting document jobs** (extraction, embedding of an upload) check the lock when they start. A locked collection ends the job `failed` with `Collection "<name>" is locked` in `resultData.error`, without a retry and without touching the document or its chunks, because the usual failure path would remove the chunks of an extraction.
- **Running document jobs** finish. Checking the lock in the middle of an extraction would leave half-written chunks, which is worse than one document that completes after the lock.
- **Reindex:** `POST .../reindex` refuses a locked collection, and a waiting reindex job refuses it before any work. A lock set during a run ends it at the next checkpoint or at the swap: the rows written for the pending model (the shadow index) are deleted, the collection keeps its current model and its pending model, and the job ends `failed` without a retry. `POST .../reindex/cancel` stays allowed on a locked collection, because it only discards an unfinished reindex.
- **Bulk re-embedding** in the host application (for example a console command) skips the chunks of locked collections and reports them; a requested model switch of a locked collection fails, because it does not happen.
- **Not checked:** the embedding of a single chunk writes only derived data, and a check there would put messages of a lock set in flight into retry loops; the paths that create embedding work are checked instead. `neuron:embedding:index:sync` changes the schema, not the content, and stays allowed.

### Deleting a collection

`DELETE /api/admin/collections/{id}` (`State\CollectionDeleteProcessor`) deletes the collection together with its content, so no rows or files of a deleted collection are left behind and the foreign keys (`RESTRICT`) no longer turn the deletion into a database error. In this order:

1. **423 Locked** for a locked collection, before anything is deleted.
2. **409 Conflict** while a reindex is pending or running, because its job would write rows for a collection that is gone; cancel it first with `POST .../reindex/cancel`.
3. **One transaction** with the content purge of the application (documents, chunks, and the vector rows of the collection for every embedding model: current, pending and leftovers of an earlier reindex), the removal of the collection's id from the scope of every MCP endpoint (`Dmstr\McpEndpoint\Scope\ScopePruner`, only when dmstr/mcp-endpoint-bundle is installed, audited through Gedmo) and the removal of the collection itself (audited). A content write that races the deletion rolls everything back and answers 409.
4. **After the commit**, the stored files of the documents are deleted. Files go last on purpose: a rollback must not leave document rows whose files are gone, while a file left behind by a failed deletion is harmless and only logged.

The scope pruning is called from the processor and not from a Doctrine listener, because a flush inside a flush is not allowed and the processor is the one deletion path of a collection (REST, admin UI, MCP).

**Ports for the host application.** The bundle does not know where an application keeps documents and chunks, so two optional ports let the application take over:

- `Knowledge\CollectionContentPurgerInterface::purge(Collection): CollectionPurgeReport` deletes the database rows of the content in one transaction (nested into the caller's), does not check the lock (the caller does), and returns a `Knowledge\CollectionPurgeReport` whose `afterCommit()` deletes the files. Without an alias the bundle deletes only empty collections and answers 409 for a collection with content (`Collection "<name>" still has content … no content purger is configured`), which is the behaviour of the bundle used standalone.
- `Mcp\Server\CollectionDocumentCounterInterface` (`count()` and `countChunks()`) supplies `documentCount` and `chunkCount` of `rag_list_collections` (see [Tools](#tools)). The bundle aliases it to its store counter `Mcp\Server\CollectionDocumentCounter`, which counts in the vector table and knows no chunk count.

An application that keeps documents and chunks in tables of its own aliases both:

```yaml
# config/services.yaml
services:
    Dmstr\NeuronAiBundle\Knowledge\CollectionContentPurgerInterface: '@Acme\Knowledge\CollectionContentPurger'
    Dmstr\NeuronAiBundle\Mcp\Server\CollectionDocumentCounterInterface: '@Acme\Knowledge\DocumentRowCounter'
```

### Reindex to another model

A model change embeds every document of the collection with the new model, which may be on another embedding connection, and switches the collection over in one step, all or nothing.

| Route | Body | Answer |
|---|---|---|
| `POST /api/admin/collections/{id}/reindex` | `{"model": "<reference>"}` (`src/Entity/Collection/reindex.input.json`: `model` required, 1 to 190 characters, the IRI or full or partial UUID of a catalogue entry; no other properties) | 201 with the queued `Job` |
| `POST /api/admin/collections/{id}/reindex/cancel` | none | 200 with the collection |

Both require `neuronai:collection:write` (403 otherwise) and answer 404 for an unknown collection. The reindex validates the raw body itself, like the MCP tool scan: malformed JSON answers 400, `connection` (no input: the entry names the connection) 422 at `connection`, a schema violation (missing `model`, another property) 422. Everything else about the target answers 422 at `model`: a reference that does not resolve to an entry usable for embeddings (as for `embeddingModel` on create), a name the registry does not know, the collection's current label, and a different model while another one is pending. The current label on another connection is refused as well: the stored vectors are labelled by the registered name only, so a reindex to the same label would skip every document, and the same model may yield other vectors on another server. The `model` field of the request body carries the same `x-collection` as `embeddingModel` (`OpenApi\PickerSchemaResolver` on top of the static file). Cancel answers 422 when no reindex is pending.

The reindex sets the entry as the collection's pending model (`pendingEmbeddingModel`), its registered name as `pendingEmbeddingModelName` and its connection as `pendingEmbeddingConnection`, and queues a job of type `collection_reindex` (input `{"collection": "<uuid>", "model": "<label>", "providerModel": "<entry uuid>"}`; the job processor compares `model` with the pending label); the job id is stored as `reindexJobId`. The job processor `JobProcessor\CollectionReindexJobProcessor` runs `Reindex\CollectionReindexer` and writes progress and `resultData` every 25 documents and at the end:

```json
{"total": 120, "done": 95, "skipped": 23, "chunks": 131, "failed": [{"sourceType": "chunk", "sourceName": "0199…", "error": "…"}], "cancelled": false}
```

`done` and `chunks` count this run, `skipped` the documents that already had rows of the target model, `failed` the documents whose embedding threw.

Semantics:

- **Dual write.** While a model is pending, the app embeds new and changed documents for both the current and the pending model. Searches keep using the current model until the swap.
- **Resumable.** A document that already has rows of the target model is skipped, so running the reindex again after a failure or an interruption only embeds what is missing.
- **All or nothing.** Only when every document succeeded is the collection switched over, in one transaction that locks and re-reads the collection row: the entry, the connection, the label and `embeddingDimension` become the target's, the pending fields and `reindexJobId` are cleared. Afterwards the old model's rows of the collection are deleted.
- **Failure.** When documents failed, the job fails with the report in `resultData`, the pending model is kept and messenger may retry it. Resume by posting the same model again (allowed while it is pending).
- **Cancel.** Cancel clears the pending model and the job id and deletes the pending model's rows of the collection. A running job notices the cancel at its next checkpoint (every 25 documents and before the swap), ends without swapping (`cancelled: true`) and removes the rows it wrote after the cancel.
- **Probe.** Before the run, a probe text is embedded and its length compared with the registered dimension. A mismatch is a registry error: the pending model is cancelled and the job fails. Provider errors (model server down) keep the pending model for a retry.
- **Stale jobs.** A job whose model is no longer pending, or whose collection points to another job, ends as a no-op (`resultData.skipped`); this covers a redelivered message after completion or cancel.

The bundle ships no synchronous reindex command; a host application can run `Reindex\CollectionReindexer` (`start()`, `run()` with a progress callback, `cancel()`) from a console command of its own, which then follows the rules of the API and resumes when run again. The per-model indexes of current and pending models are created with:

```bash
bin/console neuron:embedding:index:sync
```

**Port for the host application.** The bundle does not know where documents live. The host application implements `Reindex\ReindexSourceInterface` and aliases it in its `config/services.yaml`; the container does not compile without the alias:

```php
interface ReindexSourceInterface
{
    /** @return iterable<array{string, string}> (sourceType, sourceName) in a stable order */
    public function enumerate(Collection $collection): iterable;

    /** Embeds one document with $model through the ollama connection $connection (the reindex target), replacing that model's rows of it; returns the chunk count. */
    public function embed(Collection $collection, string $sourceType, string $sourceName, ApiConfiguration $connection, string $model): int;
}
```

```yaml
services:
    Dmstr\NeuronAiBundle\Reindex\ReindexSourceInterface: '@Acme\Knowledge\DocumentReindexSource'
```

The `(sourceType, sourceName)` pair must be the store's source group of the document's chunks, because the reindexer counts the target model's rows by it to skip finished documents. A typical implementation enumerates the chunks of the collection ordered by creation time and id, and embeds each through the same code path as its ingest, with embedding ids that include the model (for example `chunk-<n>/<model>`), so every model has its own rows.

Known limits:

- One reindex per collection at a time; a different model can only be queued after the pending one is finished or cancelled.
- An embed message that is in flight during the swap can leave rows of the old model behind; a full re-embedding of the collection cleans them up.
- Memory grows with very large collections, because the reindexer does not detach the documents it has processed.

## MCP servers as the `mcp` connection type

MCP servers are connections of [dmstr/api-configuration-bundle](https://github.com/dmstr/api-configuration-bundle): rows of `ApiConfiguration` (table `dmstr_api_configuration`) whose `configJson` matches the bundle's `mcp` schema. They are created, listed, changed and deleted through the generic resource under `/api/admin/api_configurations` (reading and writing are the `apiconfig:*` permissions of dmstr/api-configuration-bundle) and health-checked through `GET /api/admin/api_configurations/{id}/health` or `bin/console app:api-configuration:health <id>`.

`Dmstr\NeuronAiBundle\Mcp\McpExtension` implements both `ApiExtensionInterface` (health client) and `SchemaProviderInterface` (schema branch). The bundle tags it explicitly with `dmstr_api_configuration.extension` and `dmstr_openapi_json_schema.provider`, because neither the app's `_instanceof` nor the api-configuration bundle's autoconfiguration reaches services defined by another bundle.

### Configuration schema

`src/Mcp/schema.json` is one flat branch of the unified `ApiConfiguration.configJson` `anyOf` (title "MCP server", `additionalProperties: false`):

| Field | Value |
|---|---|
| `mcp`, `type`, `endpoint_type` | discriminators, `const` `true` / `"mcp"` / `"rest"`, `readOnly` with `default` |
| `url` | required, `format: uri` and `^https?://` |
| `transport` | required, `const` `"streamable-http"` (the only supported transport) |
| `auth_type` | `none` (default), `bearer`, `header`, `client_credentials` or `forward_jwt` |
| `token` | required for `bearer` and `header`, `writeOnly` (stored encrypted, returned as `********`) |
| `header_name` | required for `header`, letters, digits and dashes |
| `token_url` | required for `client_credentials`, `format: uri` and `^https?://` (an identity provider in an internal network may be plain HTTP) |
| `client_id` | required for `client_credentials` |
| `client_secret` | required for `client_credentials`, `writeOnly` (stored encrypted, returned as `********`) |
| `scope` | optional for `client_credentials`, space-separated scopes |
| `scan_token_url` | required for `forward_jwt`, token endpoint of the scan service account, `format: uri` and `^https?://` |
| `scan_client_id` | required for `forward_jwt`, client id of the scan service account |
| `scan_client_secret` | required for `forward_jwt`, `writeOnly` (stored encrypted, returned as `********`) |
| `scan_scope` | optional for `forward_jwt`, space-separated scopes of the scan service account |
| `timeout` | optional, seconds (1 to 300), default 30 |
| `restricted` | optional boolean, default `false`; must be `true` when present with `client_credentials`, free for `forward_jwt` |

Authentication variants as sent by the default transport:

```json
{"mcp": true, "type": "mcp", "endpoint_type": "rest", "url": "https://mcp.example.org/mcp", "transport": "streamable-http", "auth_type": "none"}
{"mcp": true, "type": "mcp", "endpoint_type": "rest", "url": "https://mcp.example.org/mcp", "transport": "streamable-http", "auth_type": "bearer", "token": "…"}
{"mcp": true, "type": "mcp", "endpoint_type": "rest", "url": "https://mcp.example.org/mcp", "transport": "streamable-http", "auth_type": "header", "header_name": "X-Api-Key", "token": "…"}
{"mcp": true, "type": "mcp", "endpoint_type": "rest", "url": "https://mcp.example.org/mcp", "transport": "streamable-http", "auth_type": "client_credentials", "token_url": "https://sso.example.org/realms/example/protocol/openid-connect/token", "client_id": "mcp-service-account", "client_secret": "…", "restricted": true}
{"mcp": true, "type": "mcp", "endpoint_type": "rest", "url": "https://workflow.example.org/mcp", "transport": "streamable-http", "auth_type": "forward_jwt", "scan_token_url": "https://sso.example.org/realms/example/protocol/openid-connect/token", "scan_client_id": "mcp-scan", "scan_client_secret": "…"}
```

`bearer` sends `Authorization: Bearer <token>`, `header` sends `<header_name>: <token>`, `none` sends no credentials even if a token is stored.

`client_credentials` authenticates as an OAuth2 service account (e.g. a confidential OpenID Connect client). The transport (`Mcp\TokenAuthenticatedStreamableHttpTransport`) asks `Provider\Auth\ClientCredentialsTokenProvider` for the token on every request and sends it as `Authorization: Bearer <token>`; nothing is fetched before the first request. Tokens are cached in the pool `cache.neuron_provider_tokens` (shared with `ollama` connections, key prefix `neuron_mcp_token_` plus a hash of `token_url`, `client_id`, `client_secret` and `scope`) and renewed 30 seconds before `expires_in`, so a turn that outlasts a token gets a new one for its next tool call. When the MCP server answers HTTP 401, the cached token is discarded and the same request is sent once more with a fresh token; a second 401 fails as usual. A failing token request raises `Provider\Auth\ProviderAuthenticationException`, whose message names the endpoint and the HTTP status but never the secret.

`forward_jwt` calls the MCP server as the person chatting, so the server's own authorization and audit (e.g. a workflow variable that records who triggered a process) see that person's `sub` instead of a service account. In a chat or conversation turn the JWT the request was authenticated with is sent unchanged as `Authorization: Bearer <jwt>` (plain `StreamableHttpTransport` with `token` = the JWT). Everything that does not run as a person uses the scan service account of `scan_token_url`, `scan_client_id`, `scan_client_secret` and `scan_scope`, with the client credentials path described above (same transport, token cache and 401 retry): the catalogue scan and sync, the health check, and turns without a JWT. The purpose reaches the transport factory as `Mcp\McpTransportContext` (see [Transport factory and connector](#transport-factory-and-connector)).

- **Where the JWT comes from.** `Security\CurrentJwtProviderInterface::currentJwt(): ?string`, implemented by `Security\TokenStorageJwtProvider`, reads the raw JWT from the security token in the token storage, i.e. Lexik's `JWTPostAuthenticationToken::getCredentials()`, never from the request headers. Code under `Anonymous\SystemActor::run()` (which swaps in a `PreAuthenticatedToken`), capability tokens of anonymous conversations and the test authenticator therefore have no JWT. Without the Lexik bundle the result is always null.
- **Captured at build time.** `Mcp\McpToolFactory` reads the JWT once while it builds the tools of a turn, during the request, and hands it to `create()`. A streamed turn runs its tools after the request has ended, so the JWT is never read later. The forwarded JWT expires like any access token; a turn that outlasts it fails its next tool call with the server's 401 (no renewal, unlike the scan service account).
- **Fail-closed without a JWT.** A turn without a JWT (replay of a conversation and batch evaluation, both running under the system actor; capability tokens) builds only the tools of a `forward_jwt` connection whose catalogue annotation `readOnlyHint` is exactly `true`, and runs them with the scan service account. Every other tool of that connection is dropped and logged once per connection at warning level with the tool names. For an agent version (`forToolsStrict()`) dropping is no stale reference: the turn runs with fewer tools and no 422. The version's `terminalTools` are limited to the tools actually built (`Agent\VersionTurnMapper`), so a dropped terminal tool is neither terminal nor forced by the tool-call bound; when all terminal tools were dropped, the bound closes the turn with a final answer instead. A single question of an evaluation run executed interactively (`POST …/execute`) runs in the user's request and forwards that user's JWT.
- **Identity provider prerequisites.** The MCP server must accept the person's token as is; for the application's own `/mcp/<slug>` endpoints that holds, because the token already passed the application's issuer and client check. The scan client is a confidential client with a service account and an audience mapper that adds the audience the MCP server expects to `aud` (its `azp` is the scan client); its service account needs the rights the read tools require on the server.
- **Restriction.** `restricted` is not forced: the connection acts as the calling user, whose own permissions apply on the server. Set it when the connection should still only be used through agent versions.

**Restricted connections.** `restricted: true` marks a connection that may only be used through agent versions or by a caller with the permission `neuronai:mcp_connection:use_restricted`. A `client_credentials` connection is always restricted, because its service account acts for every user: the schema rejects `restricted: false` for it, and `Mcp\McpConnectionRestriction::isRestricted(array $configJson): bool` returns `true` for it also when the key is omitted. Callers that resolve connections for a request use this method to decide. `Mcp\McpConnectionResolver::resolveForDirectUse()` and `resolveManyForDirectUse()` enforce it for connections a caller names directly (the `mcpConnections` of `POST /api/chat` and of `POST /conversations` without an agent): a restricted connection answers 403 unless the caller holds `neuronai:mcp_connection:use_restricted` (`Permissions::MCP_CONNECTION_USE_RESTRICTED`). Agent versions, the catalogue scan, the seed and the turns of stored conversations use the unchecked `resolve()` / `resolveMany()`. The resolver takes the authorization checker as optional fourth argument; without it every restricted connection is refused on the direct-use path (fail closed).

Connection names are not unique across connection types, but the class-level constraint `Validator\McpConnectionNameUnique` rejects a second `mcp` connection with the same name (422 on `name`). The bundle attaches it to the vendor entity with `config/validator/ApiConfiguration.xml`, which it prepends to `framework.validation.mapping.paths`. `Mcp\McpConnectionResolver` resolves references to connections (IRI, full or partial UUID, or name within `type = 'mcp'`) and answers unknown, inactive, ambiguous and non-`mcp` references with 422.

The unified schema is built by `SchemaRegistry` and cached in `cache.app` for up to 3600 seconds. If the `mcp` branch is missing from `configJson` validation or from the OpenAPI document after a deployment, clear the pool with `bin/console cache:pool:clear cache.app` (or `bin/console cache:clear`).

### Seeding connections

`bin/console neuron:mcp:connection:seed` creates or updates one `mcp` connection from its arguments, for setup scripts and deployments that must not depend on someone clicking through the admin UI:

```bash
bin/console neuron:mcp:connection:seed <name> <url> [--auth=none|bearer|header|client_credentials|forward_jwt] [--token=<token>] [--header-name=<header>] [--token-url=<url>] [--client-id=<id>] [--client-secret=<secret>] [--scope=<scopes>] [--scan-token-url=<url>] [--scan-client-id=<id>] [--scan-client-secret=<secret>] [--scan-scope=<scopes>] [--restricted] [--transport=streamable-http] [--timeout=<seconds>]

bin/console neuron:mcp:connection:seed docs https://mcp.example.org/mcp
bin/console neuron:mcp:connection:seed docs https://mcp.example.org/mcp --auth=bearer --token=secret
bin/console neuron:mcp:connection:seed docs https://mcp.example.org/mcp --auth=header --header-name=X-Api-Key --token=secret --timeout=10
bin/console neuron:mcp:connection:seed docs https://mcp.example.org/mcp --auth=client_credentials --token-url=https://sso.example.org/realms/example/protocol/openid-connect/token --client-id=mcp-service-account --client-secret=secret --restricted
bin/console neuron:mcp:connection:seed workflow https://workflow.example.org/mcp --auth=forward_jwt --scan-token-url=https://sso.example.org/realms/example/protocol/openid-connect/token --scan-client-id=mcp-scan --scan-client-secret=secret
```

- The connection is keyed by its name within `type = 'mcp'`. The command builds the complete `configJson` (discriminators, `url`, `transport`, `auth_type`, `header_name`, `token`, `token_url`, `client_id`, `client_secret`, `scope`, `scan_token_url`, `scan_client_id`, `scan_client_secret`, `scan_scope`, `timeout`, and `restricted: true` with `--restricted`; omitted options are left out, `auth_type` defaults to `none`).
- Idempotent: a missing connection is created as active; an existing one whose `configJson` differs is updated in place (same id, its catalogue rows stay, its `active` flag is kept); an existing one with an equal `configJson` (key order does not count) is left untouched, nothing is flushed, `updatedAt` does not change and the command prints "unchanged". Fields changed in the admin UI are overwritten by the next seed run with different values.
- The entity is validated with the Symfony validator before anything is written, so the unified connection schema and the name uniqueness apply exactly as through the API. Violations are printed and the command exits non-zero without writing, e.g. for `--auth=bearer` without `--token` or a URL that does not start with `http://` or `https://`. `--token` without `--auth=bearer|header`, `--header-name` without `--auth=header` and `--token-url`, `--client-id`, `--client-secret` or `--scope` without `--auth=client_credentials` and `--scan-token-url`, `--scan-client-id`, `--scan-client-secret` or `--scan-scope` without `--auth=forward_jwt` are rejected as well instead of being stored unused. Several `mcp` connections with the given name (created before the uniqueness constraint existed) are an error.
- The command never contacts the server: it neither probes the endpoint nor scans tools. Check the connection with `app:api-configuration:health <id>` and fill the catalogue with `neuron:mcp-tools:scan --connection=<name>`.
- The token, the client secret and the scan client secret are passed on the command line and end up in the shell history and the process list; in production, take them from an environment variable of the deployment (`--token="$MCP_DOCS_TOKEN"`) rather than writing them into a script. All are stored encrypted, and the comparison for "unchanged" uses the decrypted values.

Because the seed does not contact the server, it also succeeds in a setup script where the MCP server is not running yet.

### Health check

`Mcp\McpHealthClient` is a thin `ApiClientInterface` adapter; its domain methods (`getProjects()`, `getTodos()`, …) are no-ops. Creating the client performs no I/O. `getHealthInfo()` performs the MCP handshake (`initialize`, `notifications/initialized`) and `tools/list` on a fresh transport and never throws:

- success: `status: ok`, `authenticated: true` and `metadata.custom` with `toolCount`, `protocolVersion`, `serverName` and `serverVersion`;
- failure: `status: error`, `authenticated: false`, a `message` and `error.code` `UNAUTHENTICATED` (the server answered 401/403, a `client_credentials` token endpoint refused the client, or a JSON-RPC error of the handshake says unauthorized or forbidden), `PROTOCOL` (the server answered, but not with usable JSON-RPC: an unreadable body, a malformed or refused handshake or tool listing) or `UNREACHABLE` (network, TLS, HTTP 5xx, unusable configuration). The classification reads exception types, the HTTP status of the `HttpException` that neuron-ai 4.x chains to its `McpException`, and the structured JSON-RPC error of `Mcp\McpProtocolException`, never exception messages.

The console command's exit code mirrors the status.

**Known platform limitation: the REST route loses the nested fields.** `GET /api/admin/api_configurations/{id}/health` delivers the scalars of the result (`status`, `authenticated`, `endpoint`, `responseTime`, `message`) intact, but not `metadata` and `error`. The platform's health provider returns a `\stdClass` from an operation without an output class, and in `jsonld`, the only enabled format, API Platform normalizes each nested array with the resource class still in the context, so the Hydra collection normalizer turns `metadata` and `error` into keyless `hydra:Collection` objects (`hydra:member` lists without `custom`, `code`, …). This affects every connection type, not only `mcp`. Until it is fixed in dmstr/api-configuration-bundle, read the reason from `message`, whose prefix names the failure class (`MCP server rejected the credentials: …`, `MCP protocol error: …`, `MCP server unreachable: …`; success reads `MCP server reachable, N tool(s) listed.`), or use `app:api-configuration:health <id>`, which renders the complete result including `metadata.custom` and `error.code`.

### Transport factory and connector

Health, catalogue and chat obtain MCP transports from `Mcp\McpTransportFactoryInterface::create(array $config, ?McpTransportContext $context = null): McpTransportInterface`, where `$config` is the connection's `configJson` (secrets decrypted) and `$context` names the purpose: `McpTransportContext::scan()` (catalogue scan and sync, `McpToolScanner`), `health()` (`McpHealthClient`) or `turn(?string $jwt)` (tools of a turn, `McpToolFactory`, with the JWT of the person chatting for `forward_jwt` connections); null behaves like a scan. Only `forward_jwt` depends on the context. The default implementation `Mcp\StreamableHttpTransportFactory` builds Neuron's `StreamableHttpTransport` from `url`, `timeout` and the authentication fields (for `client_credentials` and for `forward_jwt` without a person's JWT the subclass `Mcp\TokenAuthenticatedStreamableHttpTransport`, see [Configuration schema](#configuration-schema)); `create()` performs no I/O. Replace the interface alias to change the transport, e.g. in tests.

`Mcp\McpServerConnector` extends Neuron's `McpConnector` and takes the transport instance as `config['transport']` (`McpServerConnector::forTransport($transport)`). It wraps the transport in `Mcp\McpProtocolGuard`, which turns malformed JSON-RPC answers into `Mcp\McpProtocolException`, and adds `listTools()` (raw definitions, all pages), `getInitializeResult()` and `createCatalogueTool($name, $description, $inputSchema)` / `createToolFromDefinition($definition)`. Tools built from catalogue data need no connection; the connector connects on the first tool execution. An argument property that declares its types only through `oneOf`/`anyOf` branches (e.g. a `variables` argument that is an object or an array) gets its type from the branches, `object` first; Neuron's `McpConnector` would announce it as a string, and the server would reject the text the model then sends.

### Fake transport for tests

`Testing\FakeMcpTransportFactory` returns `Testing\FakeMcpTransport` instances that answer `initialize`, `tools/list` and `tools/call` from scripts and record every request, so no test contacts an MCP server. Scripts apply to every connection or, with the optional `$url` argument, to one connection URL:

```php
$fake = static::getContainer()->get(FakeMcpTransportFactory::class);
$fake->serveServerInfo('docs-server', '2.1.0')
    ->serveTools([['name' => 'search', 'description' => 'Search', 'inputSchema' => ['type' => 'object']]])
    ->queueToolResult('three hits');
$fake->rejectCredentials('https://other.example.org/mcp'); // vendor 401 wording
$fake->failWith(new \RuntimeException('Connection refused'), 'https://down.example.org/mcp');
$fake->queueRaw('tools/list', ['jsonrpc' => '2.0']); // malformed answer
// ... code under test ...
$fake->getMethods();            // ['initialize', 'notifications/initialized', 'tools/list', ...]
$fake->getRequests($url, 'tools/call');
$fake->getCreatedConfigs();     // configs passed to create()
$fake->getCreatedContexts();    // McpTransportContext (or null) of each create(), same order
$fake->getLastCreatedContext(); // e.g. ->purpose === 'turn', ->jwt
```

Further scripting methods are `queueResult()`, `queueError()`, `queueException()` and `reset()`; the class docblock lists them all. Wiring in the host application's `config/services.yaml`:

```yaml
when@test:
    services:
        Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory:
            public: true
        Dmstr\NeuronAiBundle\Mcp\McpTransportFactoryInterface:
            alias: Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory
        Dmstr\NeuronAiBundle\Testing\FakeCurrentJwtProvider:
            public: true
        Dmstr\NeuronAiBundle\Security\CurrentJwtProviderInterface:
            alias: Dmstr\NeuronAiBundle\Testing\FakeCurrentJwtProvider
```

`Testing\FakeCurrentJwtProvider` stands in for the JWT source of `forward_jwt` connections: it returns null (no JWT, read-only tools only) until a test calls `setJwt('header.payload.signature')`; `reset()` clears it.

The fake's state lives in the service instance: script it after the kernel that serves the request has booted, and call `$client->disableReboot()` when a test sends more than one request.

### Tool catalogue

The tools of `mcp` connections are mirrored into the REF entity `Entity\Refs\McpTool` (table `neuron_mcp_tool`): one row per connection and tool name (unique `(api_configuration_id, name)`), with `name`, `title` (display name, cut to 255 characters), `description`, `inputSchema` (the JSON schema of the arguments as the server announces it), `annotations` (behaviour hints such as `readOnlyHint`; passed on to the Neuron tools built by `Mcp\McpToolFactory`), `meta` (the tool's `_meta`; `annotations` and `meta` are `null` when the server sends none or an empty object), `syncedAt` (last confirmation by the server), `lastError` (error of the last scan or sync, `null` after a successful one) and timestamps. `apiConfiguration` references the connection; the foreign key has `ON DELETE CASCADE`, so deleting a connection deletes its rows. The migrations are `Version20260930213000` and `Version20261009100000` (`title`, `annotations`, `meta`) in the bundle's migration namespace.

| Route | Role | Behaviour |
|---|---|---|
| `GET /api/refs/mcp_tools` | `neuronai:mcp_tool:read` | list; filters `apiConfiguration` (IRI or UUID, exact) and `name` (exact) |
| `GET /api/refs/mcp_tools/{id}` | `neuronai:mcp_tool:read` | one row |
| `POST /api/refs/mcp_tools/scan` | `neuronai:mcp_tool:write` | queues a job, answers 201 with the `Job` |
| `POST /api/refs/mcp_tools/{id}/sync` | `neuronai:mcp_tool:write` | refreshes one row synchronously, answers 200 with the row |

Rows are written only by scan and sync; the resource has no `POST`, `PUT`, `PATCH` or `DELETE` of its own. The application maps `neuronai:mcp_tool:write` to the roles that may trigger them (see [Permissions](#permissions)).

**Scan** mirrors all active `mcp` connections, or the one given in the body. The body follows `src/Entity/Refs/McpTool/scan.input.json`: an optional `apiConfiguration` (IRI of an active `mcp` connection; its UUID or a name without spaces work as well; empty means the same as omitted), no other properties. The scan processor validates the raw body against this schema before anything else, because the platform's `JobQueueService` only sees schemas below the app's `src/Entity` and would accept any body: malformed JSON answers 400, a schema violation 422, an unknown, inactive, ambiguous or non-`mcp` connection 422 (through `Mcp\McpConnectionResolver`). Only then a job of type `mcp_tool_scan` is created (`{}` or `{"apiConfiguration": "<uuid>"}`) and dispatched to the `async` transport. The bundle adds its own `OperationInputSchemaResolver` (service `dmstr_neuron_ai.input_schema_resolver`, priority 10 in the `dmstr_openapi_json_schema.input_resolver` chain, entity root `src/Entity`), so the OpenAPI `requestBody` of the scan shows the same schema.

The job processor `JobProcessor\McpToolScanJobProcessor` (tag `dmstr.job_queue.processor`) runs `Mcp\McpToolScanner` and stores its summary as the job's `resultData`: totals `created`, `updated`, `removed`, `unchanged`, `scanned`, `skipped`, `failed` and one entry per connection with `id`, `name`, `status` (`ok`, `skipped`, `error`), the four counters, `error` and `warnings`. Per connection the scanner lists the tools through `McpTransportFactoryInterface` and `McpServerConnector`, creates missing rows, updates rows whose title, description, input schema, annotations or `_meta` changed (object key order does not count), removes rows of tools the server no longer lists, and sets `syncedAt` and clears `lastError` on every listed row. Inactive connections are skipped without any request. A connection that cannot be listed keeps its rows, which get `lastError`; it is reported in the summary and does not stop the other connections. The job fails (and messenger may retry it) only when the referenced connection no longer exists or every scanned connection failed.

**Sync** refreshes exactly one row: if the server still lists the tool, title, description, input schema, annotations and `meta` are updated, `lastError` is cleared and `syncedAt` set. If the tool vanished, the server fails or the connection is inactive, the row is kept, `lastError` is set and `syncedAt` stays unchanged; the answer is still 200. Sync never creates or removes rows; removal is the scan's job.

Console twins run synchronously, without a job:

```bash
bin/console neuron:mcp-tools:scan                      # all active mcp connections
bin/console neuron:mcp-tools:scan --connection=docs    # one connection: UUID, partial UUID, IRI or name
bin/console neuron:mcp-tools:sync <tool-uuid>
```

`neuron:mcp-tools:scan` prints the summary and exits non-zero when a connection failed; `neuron:mcp-tools:sync` exits non-zero when it had to set `lastError`.

Reading the rows of one connection (to build Neuron tools from them, use `Mcp\McpToolFactory`, see [Chat tools from the catalogue](#chat-tools-from-the-catalogue)):

```php
$tools = $entityManager->getRepository(McpTool::class)
    ->findBy(['apiConfiguration' => $connection], ['name' => 'ASC']);
```

The catalogue is a snapshot: nothing rescans automatically. After a server added, changed or removed tools, run a scan (API or console); until then the catalogue and anything built from it show the old state, and rows of a failing server keep their last known definition with `lastError` set.

### Chat tools from the catalogue

`Chat\ChatService::chat(array $messages, ?string $instructions = null, ?string $model = null, array $collections = [], ?array $mcpConnections = null, ?array $responseSchema = null, ?int $retrievalDepth = null, ?int $maxToolCalls = null, ?array $terminalTools = null)` runs one stateless turn on the same `Conversation\TurnRunner` as a [conversation](#conversations), without storing anything. `$messages` is a list of `['role' => 'user'|'assistant', 'content' => string]`; the last entry is the user message of the turn, the earlier ones are the history. `$collections` are `Collection` entities or full collection UUIDs to retrieve context from (empty means no retrieval; the chunks are capped like in a turn), `$retrievalDepth` defaults to `store.top_k`. `$model` is required: the IRI (`/api/refs/provider_models/<uuid>`) or the full or partial UUID of an entry of the [model catalogue](#model-catalogue), which names its [provider connection](#provider-connections) (an active `ollama` or `anthropic` connection). There is no default model. `Conversation\ProviderSelection` resolves it (`Provider\ProviderModelResolver`, capability `completion`) and checks it live against the entry's connection (`Provider\ModelAvailabilityCheck`) before anything else runs. The tools are the catalogued tools of the connections listed in `$mcpConnections`; a [restricted](#configuration-schema) connection among them answers 403 unless the caller holds `neuronai:mcp_connection:use_restricted`. It returns an array with the keys `answer`, `provider` (the connection's type), `model` (the entry's name), `connection` (the connection's name), `mcp_connections` (connection names), `sources` (the context chunks sent, with the fields of the app's search hits), `usage` (token counts summed over the provider calls, or null), `structured` and `schema_errors` (see `$responseSchema` below), the booleans `forced` and `limit_reached` (see `maxToolCalls` below) and `terminal_tool` (see `terminalTools` below).

The bundle ships the service, not an HTTP operation; a host application typically exposes it as `POST /api/chat`, which this README uses as the name of the stateless chat. It takes the same fields in camel case (`model` with the `x-collection` of the available chat models, `ProviderModel::CHAT_MODEL_COLLECTION_URL`), resolves `collections` given as UUID or name, and returns `answer`, `provider`, `model`, `connection`, `mcpConnections`, `sources`, `usage`, `structured`, `schemaErrors`, `forced`, `limitReached` and `terminalTool`:

```json
{"messages": [{"role": "user", "content": "When must the spindle be serviced?"}], "model": "/api/refs/provider_models/0199a1b2-…", "collections": ["handbook"], "mcpConnections": ["docs", "/api/admin/api_configurations/0199a1b2-…"]}
```

- An empty message list, an entry with another role or without string content, and a list whose last entry is not a non-empty user message answer 422. So do, at `model`, a missing, unknown or ambiguous model reference, an unavailable entry, an entry of an inactive connection or one known to lack `completion`, a model the connection does not offer and one that cannot be verified because the provider is unreachable, and an unknown collection. `provider` and `connection` are no fields of the request (a model name is no reference either); the answer derives them from the entry's connection.
- Each `mcpConnections` entry is the IRI of an `mcp` connection, its full or partial UUID (at least 8 hex digits) or its name. An omitted or empty list means no tools; there is no "all connections" default. The same connection referenced twice is used once.
- Unknown, inactive, ambiguous and non-`mcp` references answer 422 before any provider is contacted. So does a tool name offered by two of the listed connections, because the model cannot tell the two tools apart; list only one of them.
- With at least one tool, the model must be able to take tool calls (`Provider\ToolModelCheck`, the check of the agent [publish](#publish), see [Model catalogue](#model-catalogue)): a model the catalogue knows to lack `tools` (or `completion`) answers 422 at `model` with the same text as the publish, naming `mcpConnections`, before any provider is contacted. A model without a catalogue row, with unknown capabilities or with a stale row passes, unless the connection's [allow-list](#allow-list-of-tool-models) `tool_models` does not list it.
- Tools are built from the catalogue rows, not from a live `tools/list`. Building them contacts no server: the connector of a connection performs the MCP handshake (`initialize`, `notifications/initialized`) and `tools/call` only when the model calls one of its tools, and a chat without tool calls never reaches the server. A failing tool call does not abort the turn: the error text becomes the tool result and the model continues.
- Rows whose last scan or sync failed (`lastError` set) are offered with their last known definition. If a tool has meanwhile vanished or changed on the server, the call fails and the agent receives the error; run a scan to bring the catalogue up to date. Rows whose input schema Neuron cannot express as tool properties (e.g. a property of type `null` only) are skipped with a warning in the log.
- With `$responseSchema` the schema is added to the instructions and the answer (code fences stripped) is parsed and validated against it: `structured` holds the decoded answer when it matches, otherwise `structured` is null and `schema_errors` holds the errors (`invalid_json` for an unparsable answer).
- `maxToolCalls` (an integer of at least 1, or null) bounds the tool calls of the chat turn exactly as in a [conversation turn](#the-turn), with `turn.max_tool_calls_default` as the default; 0 or a negative number answers 422, a value that is not an integer (e.g. `"3"`) answers 400, because API Platform rejects the type mismatch on the typed input property before validation runs (as for `retrievalDepth`). The answer adds two booleans: `limitReached` (the bound was reached) and `forced` (the forced step produced the answer).
- `terminalTools` (a list of unique non-empty tool names, or null) names offered tools whose call ends the chat turn with the tool's result as the answer, exactly as in a [conversation turn](#the-turn); null or `[]` means none. A duplicate, an entry that is not a non-empty string and a name that is not an offered tool answer 422 (the unknown name is in the message), before any provider is contacted; a value that is not an array answers 400, as for `maxToolCalls`. The answer adds `terminalTool` (`terminal_tool` in the service's array): the name of the terminal tool that answered, or null.
- A provider failure or a passed deadline is rethrown unchanged; the HTTP operation answers 500.

The chat is a thin wrapper around two reusable services (private, service id = class name):

| Service | Purpose |
|---|---|
| `Mcp\McpConnectionResolver` | `resolve(string $reference): ApiConfiguration` and `resolveMany(iterable $references): list<ApiConfiguration>` (deduplicated, first-seen order); 422 for unknown, inactive, ambiguous, non-`mcp` and empty references |
| `Mcp\McpToolFactory` | `forConnections(iterable<ApiConfiguration> $connections): list<ToolInterface>` (all rows per connection, in name order) and `forTools(iterable<McpTool> $tools): list<ToolInterface>` (given rows, given order); one transport and one `McpServerConnector` per connection, shared by its tools, no I/O while building; `forward_jwt` connections get the JWT of the current request or, without one, only their read-only tools (see [Configuration schema](#configuration-schema)) |

```php
$connections = $resolver->resolveMany(['docs']);
$agent->addTool($toolFactory->forConnections($connections));
```

The tools built here run every call the model makes with the connection's credentials. A caller of an agent turn that must keep the calls of one run to certain tenants or projects wraps them in a [tool argument guard](#tool-argument-guard), which refuses a call with another value before the connector contacts the server.

In tests, `Testing\FakeMcpTransportFactory` records whether a tool call reached the server, and `Testing\FakeProviderFactory::queueToolCall()` makes the fake model request a tool call (see [Test seams](#test-seams)).

### Limitations

- **Health client contract.** The `mcp` health client is still an adapter on `ApiClientInterface`, not a `HealthProbeInterface` (the slim health interface of dmstr/api-configuration-bundle). The `token` is a secret: encrypted at rest, masked in responses, readable only with the permission `apiconfig:read` (see [Connection secrets and encryption](#connection-secrets-and-encryption)).
- **Protocol.** Neuron's client (4.1.11) requests protocol revision `2025-11-25` and sends the revision the server answers as `MCP-Protocol-Version` on every later request; it reads every event of an SSE response, skipping notifications and server requests until the response to its request, and keeps the `Mcp-Session-Id` only for the lifetime of one transport. Redirects are followed only within the same origin. Stdio and the legacy SSE transport are not offered as connection options.
- **Strict tool schemas.** neuron-ai 4.x builds an MCP tool only from an input schema that its tool properties can express: a type list with two non-null types, `$ref`, `allOf` and `oneOf`/`anyOf` next to a `type` or below the top level throw a `ToolException`. `Mcp\McpServerConnector` still turns a top-level property typed only by `oneOf`/`anyOf` branches into that type (`object` when one branch is an object) and drops the branches; every other refused row is skipped with a warning by `forTools()` and rejected by `forToolsStrict()`.

## RAG tools over MCP

The bundle serves the knowledge base as three read-only MCP tools: `Mcp\Server\Resource\Knowledge` is an API Platform resource without HTTP operations whose `McpTool` operations `rag_list_collections`, `rag_search` and `rag_get_context` are reached through [dmstr/mcp-endpoint-bundle](https://github.com/dmstr/mcp-endpoint-bundle) at `/mcp/<slug>`. A tool appears on an endpoint only if the endpoint lists it in `tools`, and it sees only the collections of that endpoint's scope.

### Registration

`dmstr/mcp-endpoint-bundle` is a `suggest`, not a `require`. The tools exist only while `Dmstr\McpEndpoint\McpEndpointBundle` is installed and registered in the kernel, because they read the endpoint of the request (`McpEndpointContext`) and declare its scope key: then the bundle adds `src/Mcp/Server/Resource` to `api_platform.mapping.paths` and registers the services of `src/Mcp/Server` (`CollectionScopeResolver`, tagged `dmstr_mcp_endpoint.scope_resolver` explicitly for the scope key `collections`; `EndpointRetrievalScope`; `KnowledgeStoreTable`, `CollectionDocumentCounter` (the default of `CollectionDocumentCounterInterface`) and `ChunkEmbeddings` on the store table; the tools' provider and processors). Without the endpoint bundle none of this is registered. Neighbours of `rag_get_context` come from the `Retrieval\NeighbourSourceInterface` alias of the application, the same port as [Neighbour expansion](#neighbour-expansion); without an alias the tool returns no neighbours.

### Tools

| Tool | Arguments | Result |
|---|---|---|
| `rag_list_collections` | none | `collections`: the existing collections of the scope in scope order, each with `id`, `name`, `description`, `documentCount`, `chunkCount` (when known), `embeddingModel` |
| `rag_search` | `query` (required, 1 to 2000 characters), `collection` (optional, name or UUID as listed), `k` (optional integer of at least 1, default 5), `topic` (optional, exact match of the chunk metadata `topic`) | `hits`: ranked by score, best first, in the shape of `Conversation\ContextChunk` (`content`, `score`, `collectionId`, `collectionName`, `topic`, `sourceUrl`, `chunkId`, `chunkNumber`, `documentId`, `filename`, `breadcrumb`), the shape of a `Retrieval\KnowledgeSearch` hit |
| `rag_get_context` | `chunkId` (required, UUID, the `chunkId` of a hit) | `hit`: the embeddings of that chunk in `chunkNumber` order, in the shape of a `rag_search` hit with `score` 1 (looked up by id, not ranked); `before` and `after`: the neighbouring chunk in its source document as `{chunkId, content}`, absent when there is none |

All three are `readOnlyHint: true`, `destructiveHint: false`, `idempotentHint: true`, `validate: true` and declare `meta['de.dmstr/scope'] = 'collections'`, so an endpoint that lists one of them must name at least one collection (422 on save otherwise). Arguments are validated before any lookup: a wrong type, `k` of 0, a negative or non-integer `k`, a blank `query` or a `chunkId` that is no UUID answer a JSON-RPC error naming the argument (`k: …`). Descriptions are one line and name the returned fields, because some MCP clients (Neuron) ignore `structuredContent` and the output schema. Results are serialized as JSON-LD, like every MCP tool of the application; optional fields without a value (`description`, `chunkCount`, `before`, `after`, empty chunk fields) are omitted, not null.

- **`documentCount` and `chunkCount`** come from `CollectionDocumentCounterInterface` (see [Deleting a collection](#deleting-a-collection) for the port). An application that keeps documents and chunks in tables of its own counts there, because the documents and chunks are what a user uploaded or ingested, while the vector table holds one row per embedding and model: for example `documentCount` as the documents that are embedded plus the distinct sources of chunks without a document, and `chunkCount` as the chunk rows, falling back to the store counter for a collection whose vectors were written to the store directly. The store counter, the bundle default, counts searchable documents in the store table: the chunks of an uploaded or imported file (`document_id`) count once, the chunks of one source URL count once, every other chunk counts once; rows of all embedding models are counted together, so a collection in the middle of a reindex is not counted twice.
- **`k` and the retrieval settings.** `k` is clamped to the endpoint's `retrieval.maxK`, else to the policy default (`McpEndpointPolicyInterface::defaultMaxK()`, 10 in the default policy of dmstr/mcp-endpoint-bundle), always within 1 to 50; the store is never asked for 0 or more than `maxK` rows. `hybrid` and `hybridWeight` come from the endpoint's `retrieval`, else from the policy defaults (false and 0.5), see [Hybrid search](#hybrid-search).
- **`rag_search` without `collection`** searches every existing collection of the scope; with `collection` only that one.
- **`rag_get_context`** returns the embeddings of the collection's current embedding model only, so a chunk in the middle of a reindex is not returned twice. Neighbours are what `NeighbourSourceInterface::neighbours()` returns; a typical implementation returns the chunks at chunk index − 1 and + 1 of the same document, and nothing for chunks without a document order. A neighbour is returned only if its own chunks lie in the scope as well (the port returns id and text only, so the store is asked); a neighbour that is not searchable yet is left out. There is no `radius`.

### Scope

The scope is the endpoint's `scope.collections` (collection ids, resolved from names or UUIDs when the endpoint is saved), read through the one `Mcp\Server\EndpointRetrievalScope`.

- **"Not found", never "forbidden".** A collection outside the scope and an unknown one answer the same JSON-RPC error `Collection "<ref>" not found`; a chunk that is unknown, has no embeddings, lies in a collection outside the scope or in a deleted collection answers `Chunk "<id>" not found`. No answer tells whether something exists outside the scope.
- **Dead ids are pruned and skipped.** Deleting a collection through the API removes its id from every endpoint scope (see [Deleting a collection](#deleting-a-collection)). An id that still lingers, for example after a deletion by SQL, is dropped by the endpoint validator on the endpoint's next save, and until then the tools skip it silently: `rag_list_collections` leaves it out, `rag_search` searches the rest, `rag_get_context` answers "not found".
- **An empty scope is nothing.** Without an endpoint (a call outside `/mcp/<slug>`) or with an empty scope, `rag_list_collections` returns no collections, `rag_search` no hits and `rag_get_context` "not found"; no tool ever falls back to all collections. Such a call is refused before the database or the embedding connection is asked.

### Access: two layers

Two independent checks decide who may call a tool, and both must pass:

1. **Tool `security`** (code): every RAG tool has `security: "is_granted('neuronai:knowledge:read')"` on its `McpTool`. Changing it needs a release of this bundle.
2. **Endpoint `requiredPermission`** (data): the `McpEndpoint` record names the permission a caller needs; the endpoint bundle checks it before the MCP transport runs, so a caller without it gets HTTP 403 and never a tool list. Changing it is a `PATCH /api/admin/mcp_endpoints/{id}` or another run of the seed command.

The stricter of both wins. The recommended start is a narrow `requiredPermission` on every endpoint that offers the RAG tools; widening it later is a data change at the endpoint, not a release:

```bash
php bin/console mcp-endpoint:seed knowledge --name="Knowledge base" \
    --tools=rag_list_collections,rag_search,rag_get_context \
    --scope-collections=handbook --required-permission=<permission>
```

### Operational notes

- **Prompt injection.** The tools return chunks of indexed documents verbatim. An external MCP client must treat them as untrusted input, not as instructions; the endpoint `description` (sent as `instructions`) should say so, as the default of the seed command does.
- **Embedding load.** Every `rag_search` call embeds the query through the embedding connection of the searched collections (Ollama), so a widely shared endpoint multiplies that load; there is no rate limit per endpoint yet. `rag_list_collections` and `rag_get_context` read the database only.

## Provider connections

Endpoints and credentials of chat and embedding providers are connections of [dmstr/api-configuration-bundle](https://github.com/dmstr/api-configuration-bundle), managed through `/api/admin/api_configurations` like `mcp` connections. Two types exist (`Provider\ProviderType`):

| Type | Serves | Fields of `configJson` |
| --- | --- | --- |
| `ollama` | chat and the embeddings of collections | `url` (required, with the `/api` suffix, e.g. `http://localhost:11434/api`), `timeout`, `auth_type` (`none` default, `bearer`, `client_credentials`), `token` (`bearer` only, `writeOnly`), `token_url` (`client_credentials` only, `https://` required), `client_id`, `client_secret` (`writeOnly`) and `scope` (optional) for `client_credentials`; see [Authentication of ollama connections](#authentication-of-ollama-connections); `tool_models` (optional, see [Allow-list of tool models](#allow-list-of-tool-models)) |
| `anthropic` | chat | `api_key` (required, `writeOnly`: encrypted at rest, masked as `********`), `base_url` (default `https://api.anthropic.com/v1/`), `max_tokens` (output limit when a version sets none, default 8192), `timeout`, `tool_models` (optional, see [Allow-list of tool models](#allow-list-of-tool-models)) |

`timeout` (1 to 600 seconds) is the HTTP timeout of chat requests, capped at `turn.deadline_seconds`. The discriminators (`<type>: true`, `type`, `endpoint_type: rest`) follow the convention of the `mcp` type. Each type is one class (`Provider\Connection\OllamaConnectionType`, `AnthropicConnectionType`) that registers the schema branch (`SchemaProviderInterface`) and the health probe (`HealthProbeInterface`); there is no `ApiExtensionInterface` client, the bundle needs none for validation, masking or health.

- **Names** are unique across both provider types (`Validator\ProviderConnectionNameUnique`, 422 at `name`), so a reference by name is never ambiguous; an `mcp` or `firecrawl` connection may use the same name. The vendor table has no unique index, the resolver treats a duplicate as ambiguous.
- **References** (`Provider\Connection\ProviderConnectionResolver`): `resolveChat()` accepts an IRI, a full or partial UUID or a name and returns an active `ollama` or `anthropic` connection; `resolveEmbedding()` accepts `ollama` only. Empty, unknown, inactive, wrong-type and ambiguous references raise `ProviderConnectionReferenceException` (422) with a `reason` (`empty`, `unknown`, `inactive`, `wrong_type`, `ambiguous`). Callers store the connection's UUID.
- **Models** are always named explicitly next to the connection; there is no default model and no allow-list. `Provider\ModelAvailabilityCheck::assertAvailable($connection, $model)` is the single live check at the entry points (one call to the provider, timeout 5 s, no retry); it throws `ModelUnavailableException` with `reason` `not_offered` (with the offered names) or `unreachable` ("could not be verified (provider unreachable: …)"). Ollama names match with or without the default tag (`llama3.2` = `llama3.2:latest`). The [model catalogue](#model-catalogue) adds what each model can do; it does not replace this live check. The listing behind it is `Provider\ModelListerInterface` (Ollama `GET {url}/tags`, Anthropic `GET {base_url}models`); its failures (`ModelListingException`, codes `UNREACHABLE`, `UNAUTHENTICATED`, `PROTOCOL`, `CONFIGURATION`) never contain a key.
- **Health**: `GET /api/admin/api_configurations/{id}/health` lists the models through the same lister: `status: ok` with `metadata.custom.modelCount`, or `status: error` with one of the codes above.
- **Providers**: `ProviderFactory::chatProvider($connection, $model, ?ModelOptions)` and `embeddingsProvider($connection, $model)` build the neuron-ai providers from a connection, with the `api_key` decrypted through `ConfigSecrets::decrypt()`. They are the only way the bundle builds providers: there is no configured endpoint, key or model.
- **Deleting** a provider connection that is still referenced is refused with 409 and the list of references (`Doctrine\ProviderConnectionDeleteGuard`, a `preRemove` entity listener, so the console and application code are covered too): published agent versions (through the catalogue entries of the connection that `configuration.model` references, or a `configuration.connection` that an older stored version still holds), conversations (through the catalogue entry `provider_model_id`, which the delete would cascade away, or `provider_connection_id`; also anonymous, evaluation and replay conversations) and collections (through the catalogue entries `embedding_model_id` and `pending_embedding_model_id`, current model or reindex target, or the connection columns, also of a pending target queued without an entry). The guard runs before the delete, so the cascade of the entries never reaches the keys of conversations and collections, which have no cascade and are NOT NULL. Message and evaluation records are no references: their catalogue entry (`provider_model_id`) is set to null by the database when the entry goes with the connection, the model name stays. Deactivate the connection (`active: false`) instead; references to it then fail as stale: a turn, agent chat, judge or replay on it answers 422 (`configuration/model` for versions), anonymous creation 503, ingest, search and reindex of a collection on it fail with a clear message.

### Authentication of ollama connections

An `ollama` connection can authenticate against an Ollama server behind a reverse proxy, or against a hosted Ollama API. `auth_type` selects the variant; the schema rejects unknown keys (`additionalProperties: false`) and requires the fields of the chosen variant:

| `auth_type` | Required fields | What is sent |
| --- | --- | --- |
| `none` (default, also when the key is missing) | – | no `Authorization` header; behaviour unchanged |
| `bearer` | `token` | `Authorization: Bearer <token>` with a static token, e.g. an API key of ollama.com's hosted API; a token with CR or LF is rejected |
| `client_credentials` | `token_url` (`https://` only), `client_id`, `client_secret`; optional `scope` | `Authorization: Bearer <access token>` with an access token fetched from `token_url` by the OAuth2 client credentials grant (e.g. an OpenID Connect provider) |

`token` and `client_secret` are `writeOnly` secrets like the Anthropic `api_key` (see [Connection secrets and encryption](#connection-secrets-and-encryption)): stored encrypted (`enc:v1:…`), returned as `********`, and on `PATCH` the mask or an omitted key keeps the stored value. These secrets live only in the connection and are set over the REST API; there is no environment variable and no seed entry for them. A create with client credentials (all values are placeholders):

```bash
curl --silent --request POST \
  --header "Authorization: Bearer ${TOKEN}" \
  --header "Content-Type: application/ld+json" \
  --header "Accept: application/ld+json" \
  --data @- \
  "https://app.example.org/api/admin/api_configurations" <<'JSON'
{
  "name": "ollama-remote",
  "active": true,
  "configJson": {
    "ollama": true,
    "type": "ollama",
    "endpoint_type": "rest",
    "url": "https://ollama.example.org/api",
    "auth_type": "client_credentials",
    "token_url": "https://sso.example.org/realms/example/protocol/openid-connect/token",
    "client_id": "ollama-client",
    "client_secret": "<client secret>",
    "scope": "openid"
  }
}
JSON
```

`scope` is optional and may be left out. To rotate the secret, `PATCH` the connection (`Content-Type: application/merge-patch+json`) with the complete `configJson` and the new `client_secret`; `configJson` is replaced as a whole, only a secret sent as `********` or left out keeps its stored value.

**Where it applies.** `Provider\Auth\OllamaHttpClientFactory::for(array $decryptedConfig, HttpClientInterface $inner, string $connectionName = '')` wraps Neuron's HTTP client of every `ollama` provider the bundle builds: chat turns and agent turns, answer gates, embeddings (ingest, reindex, retrieval), the model listing behind the live model check and the connection health check. With `auth_type` `none` it returns the inner client unchanged; an unusable auth configuration (missing field, unsupported `auth_type`) fails before any request is sent.

**Token lifecycle.** `Provider\Auth\AuthenticatingHttpClient` resolves the token on every request and every stream, not once per provider, because the messenger worker keeps embedding providers alive longer than an access token lives. For `client_credentials` the token comes from `POST token_url` with a form-encoded body (`grant_type=client_credentials`, `client_id`, `client_secret`, `scope` when set), timeout 5 s. It is cached in the pool `cache.neuron_provider_tokens` (adapter `cache.app`, so php-fpm and the messenger worker share it when `cache.app` is Redis; an array pool in tests) under `neuron_ollama_token_` plus the SHA-256 of the credentials, with a lifetime of `expires_in` minus 30 s (at least 1 s). A 401 or 403 from the Ollama server evicts the cached token and retries the request once with a fresh one; a second rejection fails. A static `bearer` token is not retried. When the cache fails, the request goes on with a freshly fetched, uncached token; protection against parallel token requests across processes is best effort.

**Errors.** Every failure raises `Provider\Auth\ProviderAuthenticationException`: the token endpoint is unreachable, refuses the credentials or answers without a usable `access_token` and positive `expires_in`, or the Ollama server rejects the token (for `client_credentials` also after one renewal; a static `bearer` token is not renewed). The message names the connection and the HTTP status and, for the token endpoint, its `error` and `error_description` cut to 200 characters; it never contains the client secret, a token or a response body, and it chains no previous exception (the transport exceptions carry the request with its secrets). A failed turn stores and streams this message. The health check reports `error.code` `UNAUTHENTICATED` for a token failure and for a 401/403 of the server, and `UNREACHABLE` when the Ollama server cannot be reached.

**Identity provider requirements.** A confidential client with only "Service accounts" enabled (no standard or direct-access flow); an audience mapper that adds the proxy's audience to the access token; the realm role the proxy requires assigned to the client's service account. When "Full scope allowed" is off for the client, that role must also be in the client's dedicated scope, otherwise it is missing from the token and the proxy answers 403.

**Security note.** An access token for such a proxy typically grants the full Ollama API, including `/api/delete` and `/api/pull`. Where possible, restrict the upstream paths at the proxy to what the bundle uses (`/api/chat`, `/api/embed`, `/api/tags`); that is outside this bundle.

**Test seam.** `OllamaHttpClientFactory` takes an optional `$tokenHttpClient` (Neuron's `HttpClientInterface` for token requests; the tests pass `Testing\FakeHttpClient`) and an optional `$now` clock closure, so token fetch, caching, expiry and the evict-and-retry path are tested without a token server or real time. `Testing\ProviderConnectionFixtures::ollamaBearer()` and `ollamaClientCredentials()` build valid authenticated connections with placeholder constants (`PLACEHOLDER_TOKEN`, `TOKEN_URL`, `CLIENT_ID`, `PLACEHOLDER_CLIENT_SECRET`).

### Model descriptions

`Provider\ModelDescriberInterface` describes the models of a provider connection with what the live listing does not report: family, parameter size, quantization, capabilities and the trained context length. It is the source of the model catalogue and stays apart from `ModelListerInterface`, which remains one cheap call for the live model check and the health probe. The service is `Provider\ProviderModelDescriber`, which dispatches by type:

- `describeModels($connection, $known = [])` returns a `Provider\Model\ModelDescriptionList` (`models`, `warnings`); `describeModel($connection, $name)` returns one `Provider\Model\ModelDescription`, always described afresh, or null when the connection does not offer the model (names match with or without `:latest`, `Provider\ModelName::equivalent()`). A failed describe of an offered model is a description with `described = false` and an `error`, never null. Connection-level failures throw `ModelListingException` with the codes of the lister.
- A `ModelDescription` carries `name`, `displayName`, `family`, `parameterSize`, `quantization`, `digest`, `capabilities`, `maxContextLength`, `maxOutputTokens`, `supportsSampling`, `providerData`, `described` and `error`. `capabilities` is null when unknown, a list without `tools` when the model is known to lack tools; the vocabulary is `Provider\Model\ModelCapability::ALL` (Ollama's values; unknown values are dropped). `maxContextLength` is the trained maximum, not Ollama's runtime `num_ctx`. `supportsSampling` says whether the model accepts `temperature`, `top_p` and `top_k`: always for Ollama, for Anthropic from the rule table `Provider\AnthropicSamplingSupport` (no for Opus 4.7 and later, Fable and Sonnet 5 and later; yes for earlier models; null for ids the table does not cover), because the Models API does not report it.
- **Ollama** (`Provider\OllamaModelDescriber`): `GET {url}/tags`, then `POST {url}/show` with `{"model": "<name>"}` per model (never `verbose`), with one client per connection that authenticates like the lister. Besides capabilities and the trained context length, `/show` yields the Modelfile `parameters` (a `key value` text, parsed by `OllamaModelDescriber::parseParameters()` into `providerData.parameters`: numbers as int or float, `true`/`false` as bool, quotes removed, `stop` and repeated keys as lists; `[]` when the Modelfile sets none), the scalar `model_info` keys (`providerData.model_info`), the Modelfile `system` prompt, the scalar `projector_info` keys of vision models and, for cloud models, `remote_model` and `remote_host`; `template`, `license`, `modelfile` and `tensors` are not kept. A `/tags` entry with `capabilities` and `details.context_length` is no reason to skip `/show` (it has no parameters); it is the fallback description of a model that `/show` did not describe (budget, circuit breaker, failure). A model whose digest equals the one in `$known` and whose capabilities and parameters are known (`parametersKnown`, absent = true) comes back with `described = false`. The rest is described new models first, then changed ones, then never-described ones, at most `provider_models.max_describe_calls` per call; models over the budget come back with `described = false` and one warning. The `/show` loop stops on the first `/show` that is rejected (401/403 after the client's one token refresh), answers 404 or 405 for a listed model (a gateway blocking the route) or fails without an HTTP status (network down, timeout); that model and the rest come back with `described = false` and one warning, while other `/show` failures (5xx, an unusable answer) mark only that model. The worst case is `max_describe_calls` × 5 s per connection.
- **Anthropic** (`Provider\AnthropicModelDescriber`): `GET {base_url}models`, paginated like the lister, and `GET {base_url}models/{id}` for one model (404 = not offered). Capabilities are synthesized: `completion` and `tools` always, `vision` and `thinking` from the API's `capabilities` object, never `embedding`; `max_input_tokens` and `max_tokens` become the context length and the output limit (0 = not reported).

### Model catalogue

The models of `ollama` and `anthropic` connections are mirrored into the REF entity `Entity\Refs\ProviderModel` (table `neuron_provider_model`), one row per connection and model name (unique `(api_configuration_id, name)`), from the [model descriptions](#model-descriptions):

| Field | Meaning |
|---|---|
| `name`, `displayName` | model name as the provider lists it (Ollama `llama3.1:8b`, Anthropic model id), Anthropic `display_name` |
| `family`, `parameterSize`, `quantization` | Ollama `details` (`llama`, `8.0B`, `Q4_K_M`); Anthropic `line` as family |
| `capabilities` | sorted list from `Provider\Model\ModelCapability::ALL` (`completion`, `tools`, `insert`, `vision`, `embedding`, `thinking`, `image`, `audio`, `decision`); **null = unknown**, `[]` = known to be none |
| `maxContextLength` | trained maximum context in tokens (Ollama `<arch>.context_length`, Anthropic `max_input_tokens`), not Ollama's runtime `num_ctx`; `EmbeddingModelRegistry`'s configured `contextLength` keeps winning for embeddings |
| `maxOutputTokens` | Anthropic `max_tokens`; null for Ollama |
| `supportsSampling` | whether the model accepts `temperature`, `top_p` and `top_k` (always for Ollama, rule table for Anthropic); null = unknown |
| `digest` | Ollama digest, drives the incremental rescan |
| `defaultContextLength` | read-only, Ollama: the context length the server uses for a request without `num_ctx`, from the Modelfile (`providerData.parameters.num_ctx`); null when the Modelfile does not set it (the server default applies, which the API does not report) or the model was not described by `/show` |
| `providerData` | extras (Ollama `families`, `format`, `size`, `modified_at`, `parameter_count`, `embedding_length`, `remote_model`, `remote_host`, `system`, `projector_info`, `parameters` (parsed Modelfile parameters; missing = never described by `/show`, so the scan describes the row again) and `model_info` (scalar keys of `/show`); Anthropic `capabilities`, `created_at`) |
| `stale` | computed: the row was described from another endpoint than the connection points to now (see below) |
| `available` | whether the connection offered the model at the last scan or sync; a model no longer offered keeps its row with `false` (migration `Version20261008160000`) |
| `syncedAt`, `lastError` | last successful describe (null when never described), error of the last scan or sync |

`apiConfiguration` references the connection; the foreign key has `ON DELETE CASCADE`, so deleting a connection deletes its rows (they are no references, `ProviderConnectionDeleteGuard` ignores them). The migration is `Version20261006180000` in the bundle's migration namespace; `capabilities` is `JSONB`.

| Route | Role | Behaviour |
|---|---|---|
| `GET /api/refs/provider_models` | `neuronai:provider_model:read` | list; filters `apiConfiguration` (IRI or UUID, exact), `name` and `family` (exact), `search` (name contains the term, case-insensitive, or UUID starts with it; `Filter\ModelSearchFilter`, the type-ahead of the model pickers), `available` (boolean) and `capability` |
| `GET /api/refs/provider_models/{id}` | `neuronai:provider_model:read` | one row |
| `GET /api/refs/provider_models/{id}/options_schema` | `neuronai:provider_model:read` | the JSON schema of the agent [model options](#model-options) the model accepts |
| `POST /api/refs/provider_models/scan` | `neuronai:provider_model:write` | queues a job, answers 201 with the `Job` |
| `POST /api/refs/provider_models/{id}/sync` | `neuronai:provider_model:write` | describes one row afresh, answers 200 with the row (409 while a scan of the connection runs) |

Rows are written only by scan and sync. Scan and sync make outbound calls with the stored credentials and reading the connections needs `apiconfig:read` anyway; agent authors (`neuronai:agent:write`) read the catalogue to pick a model with `neuronai:provider_model:read`. The scan body's `x-collection` points to `/api/admin/api_configurations`, which only admins can read.

**Capability filter.** `?capability=tools` or `?capability[]=tools&capability[]=vision` returns the models that have every listed capability (`Filter\CapabilityFilter`, the bundle's first custom API Platform filter, attached by `#[ApiFilter]`). An unknown value answers 400; models with unknown capabilities never match. Because DQL has no jsonb containment, the filter selects the matching ids with a DBAL query (`capabilities @> CAST(:caps AS jsonb)`) first.

**Scan** mirrors all active provider connections, or the one given in the body. The body follows `src/Entity/Refs/ProviderModel/scan.input.json`: an optional `apiConfiguration` (IRI of an active `ollama` or `anthropic` connection; its UUID or a name without spaces work as well; empty means the same as omitted), no other properties. The connection types are `ProviderModel::CONNECTION_TYPES`; the scanner reads them, and an `OpenApi\PickerSchemaResolver` for the operation and the field (priority 20 in the input resolver chain) builds the field's `x-collection` from them on top of the static file, so the form's connection picker offers exactly the types the scan covers. The scan processor validates the raw body through `State\InputBodyValidator` (malformed JSON 400, schema violation 422) and resolves the connection through `ProviderConnectionResolver::resolveChat()` (unknown, inactive, ambiguous or wrong-type connection 422) before a job of type `provider_model_scan` is created (`{}` or `{"apiConfiguration": "<uuid>"}`, `Provider\ProviderModelScanQueue`) and dispatched to `async`. While a `pending` or `running` scan job with the same input exists, that job is answered instead of a new one.

The job processor `JobProcessor\ProviderModelScanJobProcessor` runs `Provider\ProviderModelScanner` and stores its summary as the job's `resultData`, shaped like the tool catalogue's but with `unavailable` instead of `removed` (`created`, `updated`, `unavailable`, `unchanged`, `scanned`, `skipped`, `failed`, one entry per connection with `status` `ok`, `skipped` or `error`, `error` and `warnings`). Per connection the scanner:

- skips inactive connections without any request and reports a non-provider connection as an error;
- describes the models with the stored digests: an Ollama model whose digest is unchanged and whose capabilities are known is not described again, so only the first scan of a server costs one `/show` per model (at most `provider_models.max_describe_calls`, worst case that many × 5 s per connection, below the worker's time limit);
- creates missing rows, updates changed rows (key order and capability order do not count), and marks rows of models the connection no longer offers `available = false` with `lastError` `Model is no longer offered by the connection`, counted once as `unavailable`. Rows are never deleted, so references to a catalogue entry stay valid; a model offered again gets its old row back (same id) with `available = true` and counts as `updated`, also with an unchanged digest;
- gives a described row `syncedAt = now`, `lastError = null` and the current endpoint fingerprint; a row that was not described this time (digest unchanged, budget reached, `/show` failed or blocked by a gateway) keeps capabilities, context length, output limit, sampling support, `syncedAt` and fingerprint and gets only the listing fields refreshed, plus `lastError` when the describe failed; an unchanged digest (`ModelDescription->unchanged`) also clears a `lastError` of an earlier scan, because the stored description is current and the listing worked, while a budget or breaker skip leaves it;
- keeps the rows of a connection that cannot be described and sets their `lastError`; the other connections go on;
- adds the describer's warnings (duplicate names, budget reached, `/show not permitted by the endpoint (HTTP 403); capabilities not refreshed`, `/show aborted: endpoint unreachable`) to the summary.

The job fails (and messenger may retry it) only when the referenced connection no longer exists or every scanned connection failed.

**Concurrency.** Scan and sync of one connection hold the non-blocking lock `neuron.provider_model.<connection uuid>` from the app's `lock.factory` for the whole describe and write, and load the stored rows only after acquiring it; its TTL is `max_describe_calls` × 5 s + 60 s. A scan that finds the lock held reports the connection as `skipped` with the warning `another scan of this connection is running`, a sync answers 409 (the console twin exits non-zero). The lock is only as strong as the lock store: use a shared store (Redis) when several hosts run workers.

**Sync** describes exactly one row afresh (ignoring the digest): a described model refreshes the row; a failed describe keeps the stored values and sets `lastError`; a model the connection no longer offers gets `available = false` and `lastError` `Model is no longer offered by the connection`; a connection failure or an inactive connection sets `lastError` as well. The answer is 200 in all these cases. Sync never creates or deletes rows.

**Stale rows.** Each describe stores a SHA-256 of the connection's normalised base URL (`url`, Anthropic `base_url` or the public API; `Provider\EndpointFingerprint`). When the connection is pointed to another server, its rows turn `stale: true` until the next scan describes them again; a stale row counts as unknown wherever capabilities are evaluated. The fingerprint itself is not serialised.

**Scans triggered by connection changes.** Creating an active `ollama` or `anthropic` connection, activating one, or changing its `url` or `base_url` through the API queues a scan of that connection (`State\ProviderConnectionScanTrigger`, a decoration of API Platform's Doctrine persist processor). Renames and other changes queue nothing, and nothing outside the API write path triggers a scan: the seed command, application code and the console stay free of side effects. The trigger reuses only a `pending` scan job of the connection, never a `running` one, because a running scan may already have read the old URL and would leave the rows stale. The worker consumes jobs one after the other, so the new job runs after the running one; with parallel workers it can find the per-connection lock still held, reports the connection as `skipped`, and a manual scan is needed. A failure to queue is logged and does not fail the write.

Console twins run synchronously, without a job:

```bash
bin/console neuron:provider-models:scan                             # all active provider connections
bin/console neuron:provider-models:scan --connection=ollama-local   # one connection: UUID, partial UUID, IRI or name
bin/console neuron:provider-models:sync <model-uuid>
```

`neuron:provider-models:scan` prints the summary and its warnings and exits non-zero when a connection failed; `neuron:provider-models:sync` prints the row (capabilities, context length, `stale`, `lastError`) and exits non-zero when it had to set `lastError` or a scan holds the lock.

Reading the rows of one connection:

```php
$models = $entityManager->getRepository(ProviderModel::class)
    ->findBy(['apiConfiguration' => $connection], ['name' => 'ASC']);
```

The catalogue is a snapshot: apart from the trigger above nothing rescans automatically, and a re-pulled model shows its old capabilities until the next scan or a sync. The capability `tools` says only that the model's template renders tools; it says nothing about how well the model uses them (`llama3.1:8b` reports `tools`).

**Options schema.** `GET /api/refs/provider_models/{id}/options_schema` (`State\ProviderModelOptionsSchemaProvider`) answers `{model, connection, type, stale, schema}`, where `schema` is the JSON schema (2020-12) of the `modelOptions` an agent on this model may set, built by `Provider\ModelOptionsSchema` exactly as the [publish](#publish) checks them (see [Model options](#model-options)). A form loads it after the model is chosen, because the static candidate schema cannot depend on another field's value. A stale row gives the options of the provider without the limits of the model.

**Capability check.** `Provider\ModelCapabilityCheck::verdict(ApiConfiguration $connection, string $model, array $required)` answers from the catalogue without network I/O: `supported`, `missing` (with the missing and the known capabilities and the row's id) or `unknown` (`Provider\CapabilityVerdict`). It takes the connection's row with exactly that name, else one with an equivalent name (`x` vs. `x:latest`, `Provider\ModelName::equivalent()`). No row, `capabilities` null or a stale row is `unknown`. `Provider\ToolModelCheck::violation($connection, $model, $toolsField)` builds on it and is the one check of "may this model take MCP tools": it requires `completion` and `tools` and returns the violation text (naming `$toolsField` and the `neuron:provider-models:sync <id>` command for a re-pulled model), or null for a supported or unknown model. The agent [publish](#publish) and the stateless [chat](#chat-tools-from-the-catalogue) run it. Agent chat, anonymous creation and rollback do not, because they run a published version; conversations (also those created without an agent, with `mcpConnections`) are not checked either, a model without tools fails there at the first turn. The check is only as good as the last scan: a changed connection URL disables it for that connection until the next scan, and Anthropic models always have `completion` and `tools`, so they always pass.

**Model references.** A field that chooses a model by its catalogue entry instead of a connection and a model name takes the entry's IRI (`/api/refs/provider_models/<uuid>`, `ProviderModel::iri()`) or its UUID (full, or a prefix of at least 8 hex digits); the connection is the entry's `apiConfiguration`. Names are no references, because a name is unique per connection only. `Provider\ProviderModelResolver::resolve(?string $reference, string $capability)` returns the `ProviderModel` or throws `Provider\ProviderModelReferenceException` (422) with a `reason`: `empty`, `unknown` (no entry, an IRI of another resource, or a name), `ambiguous` (partial UUID), `unavailable` (`available` false), `inactive` (the connection) or `missing_capability` (the catalogue knows the model lacks `$capability`, or the connection type cannot serve it: `embedding` only from `ollama`); `check(ProviderModel $model, string $capability)` runs the same checks on an entry the caller already holds. Collections add the reason `unregistered` (`Embeddings\EmbeddingModelResolver`: the entry's name matches no model of the [embedding model registry](#embedding-model-registry)). Unknown capabilities and stale entries pass, as in the capability check; the live `ModelAvailabilityCheck` stays the caller's job (`assertAvailable($model->getApiConfiguration(), $model->getName())`). `resolveField($reference, $capability, $field, $root)` throws an API Platform `ValidationException` with the violation at `$field`; `ProviderModelReferenceException::violationAt($path, $root)` serves callers that collect the violations of several fields. A form's model picker takes the keywords of `ProviderModel::modelPicker($capability)`: `format: iri-reference`, `x-label-property: name` and `x-search-property: search`, without the first of which the admin form's type-ahead (`EditorIriReference` of dmstr/jedison-extender-bundle) does not resolve and a plain text input is shown, and `ProviderModel::modelCollectionUrl($capability)` as `x-collection` (as constants for attribute metadata `CHAT_MODEL_PICKER` and `EMBEDDING_MODEL_PICKER`; for a static input schema `OpenApi\PickerSchemaResolver` adds them per operation and field): `/api/refs/provider_models?available=true&apiConfiguration.active=true&capability[]=<capability>`. Typing searches with `search=<input>`, so a field finds an entry by its name and by its UUID. A scan leaves the rows of an inactive connection alone, so they keep `available` true; the filter `apiConfiguration.active` drops them, which the resolver would reject as `inactive`. The capability filter drops entries with unknown capabilities, so a never-described model is accepted as a reference but not offered by the picker; scan the connection to offer it.

### Allow-list of tool models

The `tools` capability says only that a model accepts tool definitions; small models with `tools` (such as `llama3.1:8b`) still call tools unreliably. A provider connection can therefore release models for tool use explicitly with `configJson.tool_models`, a list of model names (unique, each 1 to 190 characters) in the schema of both `ollama` and `anthropic`:

```json
{"ollama": true, "type": "ollama", "endpoint_type": "rest", "url": "https://ollama-gpu.example.org/api", "tool_models": ["qwen3:14b", "gemma4:26b"]}
```

- An empty or missing list allows every model (the default).
- With a list, `Provider\ToolModelCheck::violation()` rejects every other model: publishing a candidate with `mcpTools` answers 422 at `candidate/model`, `POST /api/chat` with `mcpConnections` at `model`, for example `Model "llama3.1:8b" is not released for tools on connection "ollama-gpu" (tool_models: qwen3:14b, gemma4:26b); choose one of these models or remove mcpTools.` Names match like the live check (`llama3.2` matches `llama3.2:latest`, `Provider\ModelName::equivalent()`).
- The capability verdict comes first: a listed model that the catalogue knows to lack `tools` gets the capability message. The allow-list applies regardless of the catalogue, also to unscanned models and on stale rows.
- Candidates and chats without tools are not affected. Like the capability check it runs at publish and in the stateless chat only: published versions, agent chat, conversations, anonymous creation and rollback are not re-checked, so restricting the list later does not stop a version published before.

`configJson` is replaced as a whole on `PATCH`; send the complete configuration with the new `tool_models` (secrets as `********` keep their stored value). `ToolModelCheck::allowedModels($connection)` returns the list.

### Seeding provider connections

```bash
bin/console neuron:provider:connection:seed <name> --type=ollama|anthropic --config='<JSON object>'
bin/console neuron:provider:connection:seed --file=<seed file>

bin/console neuron:provider:connection:seed ollama-local --type=ollama --config='{"url": "http://localhost:11434/api"}'
bin/console neuron:provider:connection:seed --file=config/provider-connections.json
```

The seed file is a JSON list of `{"type", "name", "config"}` objects; `config` holds the type's fields without the discriminators. The command is idempotent like the `mcp` seed (created, updated in place with the `active` flag kept, or "unchanged"), compares against the decrypted stored configuration, validates every entry before writing, never contacts a provider and never prints a configuration or a key. An existing connection of the other provider type with the same name is an error. Keep keys in the seed file rather than on the command line. Authenticated `ollama` connections (`auth_type` `bearer` or `client_credentials`) are not seeded: create them and set their secrets over the REST API (see [Authentication of ollama connections](#authentication-of-ollama-connections)).

Keep a seed file with keys out of version control (for example a gitignored file next to a committed template).

Seeding never fills the [model catalogue](#model-catalogue) and never contacts a provider: run `bin/console neuron:provider-models:scan` (or `POST /api/refs/provider_models/scan`) afterwards.

### Test seam for provider connections

Under `when@test` the host application aliases `Provider\ModelListerInterface` to `Testing\FakeModelLister`: every model is available unless a test scripts `offer([...], 'name')`, `unreachable('name')` or `unauthenticated()`. It aliases `Provider\ModelDescriberInterface` to `Testing\FakeModelDescriber`, which offers **no** models by default (never a wildcard) and plays `describe('name', ['name' => 'llama3.1:8b', 'capabilities' => ['completion', 'tools']], new ModelDescription(...))`, `failModel('name', 'model', 'reason')` (`described = false` with an error), `unreachable('name')`, `unauthenticated()` and `reset()`; `getDescribedConnections()` records the calls. `Testing\FakeProviderFactory::chatProvider()` records `['provider' => <type>, 'connection' => <name>, 'model', 'temperature', 'maxTokens']` and declines a tool choice for `ollama`; `embeddingsProvider()` records in `getRequestedEmbeddings()`. `Testing\ProviderConnectionFixtures` builds valid connections (constants `FakeProviderFactory::OLLAMA_CONNECTION`, `ANTHROPIC_CONNECTION`), `Testing\ConfigSecretsFixture` a real `ConfigSecrets` with the insecure test key for kernel-free tests. Tests that reference a model need a usable catalogue entry per connection and model name; a small test helper of the host application can create or reuse one (lookup as `ModelCapabilityCheck::find()`, exact name first, then `x` vs. `x:latest`).

## Connection secrets and encryption

The keys a type schema marks with `"writeOnly": true` are secrets: `mcp` `token`, `anthropic` `api_key`, `ollama` `token` and `client_secret` and `firecrawl` `api_key`. For them:

- they are stored encrypted (`enc:v1:…`) by the bundle's Doctrine `onFlush` listener, whoever writes the connection (API, seed commands, application code); seeds write clear values;
- API responses and the validator's logs show `********`; on update an omitted secret or the mask keeps the stored value, `null` removes it;
- reading connections (`GET` on the resource and on `/health`) needs the permission `apiconfig:read`;
- code that reads a secret from the entity decrypts with `ConfigSecrets::decrypt()` and never uses `getConfigJson()` directly for it (`McpToolFactory`, `McpToolScanner`, the services of other connection types such as dmstr/firecrawl-bundle, `ProviderFactory`, the model listers); health clients get the decrypted configuration from the bundle.

The key is `dmstr_api_platform_utils.credential_encryption.key: '%env(base64:CREDENTIALS_ENCRYPTION_KEY)%'`, 32 random bytes in base64 (for example `base64_encode(sodium_crypto_secretbox_keygen())`). Without a usable key, writing or using a secret fails with `SecretEncryptionException`. Tests may use a fixed, publicly known test key (`when@test`, for example `Testing\ConfigSecretsFixture::INSECURE_TEST_KEY`) that must never protect real credentials. Changing or losing the key makes every stored secret unreadable; existing clear-text rows are encrypted with `app:api-configuration:encrypt-secrets` (`--dry-run` first).

## Artifact tools

Agents can keep files across conversations with five scoped file tools of neuron-ai's `FileSystemToolkit`: `read_file`, `grep_file_content`, `glob_path`, `write_file` and `edit_file` (namespace `Artifact\`). `bash`, `delete_file` and `parse_file` are never offered. `ArtifactToolkitFactory::forAccess(ArtifactAccess)` builds the toolkit of one turn; `ArtifactScope::ensure($ownerSubject, $conversationId)` creates the folders first.

- **Scope**: `<artifacts.root>/<ownerSubject>` is the user root, the scope of all tools; `<conversationId>/` below it is the working folder of a conversation, where new files go. Files of earlier conversations of the same user can be read and edited, files of other users never. The owner subject must match `^[A-Za-z0-9._-]{1,128}$`, otherwise there are no tools. `..`, absolute paths outside the scope, symlinks pointing outside and dangling symlinks are refused by the toolkit; the wrapper (`ArtifactTool`) also refuses NUL bytes, paths over 1024 bytes and names over 255 bytes.
- **No server path**: the tool guidelines and every tool result, success or error, use paths relative to the user root.
- **Limits** (port `ArtifactLimitsInterface`, bundle default `DefaultArtifactLimits`; an app alias replaces it): 512 KiB per file, 50 files per working folder, 50 MiB per user, 50 000 characters per `read_file` (larger files: `grep_file_content`). Checks, write and bookkeeping run under the lock `artifact:<ownerSubject>` from `lock.factory`, so parallel conversations of one user cannot pass the quota together; use a store shared by all php containers.
- **Bookkeeping**: table `neuron_artifact` (entity `Entity\Artifact`, migration `Version20261016090000`), one row per file, unique per owner and lower-cased path, so names differing only in case are refused (`name differs only in case from <path>`). Rows and files outlive their conversation (`SET NULL`).
- **Writing** needs the permission `neuronai:artifact:write` (mapped to roles by the application); without it only the read tools are offered. The decision is taken when the tools are built, not when they run.
- **Turn gate**: a turn of an agent conversation gets the tools when its pinned version has `artifactTools` and `ArtifactAccessResolver` admits the turn: not anonymous, no `origin` (`eval`, `replay`), not under the system actor, and the token user is the conversation's owner; anything else drops the tools with a warning instead of failing the turn. The resolver creates the working folder under the turn lock; `VersionTurnMapper::map(..., artifacts: $access)` appends the tools after the MCP tools (an MCP tool of the same name is a 422) and the guidelines to the instructions. The stateless agent chat, the judge, pinning and the conversation start never pass an access.
- **Configuration**: `artifacts.root` (default `%kernel.project_dir%/var/artifacts`, for example `%env(ARTIFACT_STORAGE_DIR)%` on a persistent volume). The root must exist and be writable by php-fpm; it is never created, so a missing volume means no artifact tools.

Known limits: the toolkit checks paths before its file I/O without `O_NOFOLLOW` (a symlink swapped in between is not caught; there is no tool that creates symlinks), writes of one user are serialised by the lock, but `write_file` overwrites unconditionally, so when two conversations change the same file the last write wins.

## Conversations

A conversation is a persisted chat of one owner. Its configuration is fixed at creation; every message of every turn is stored as its own record and replayed into the next turn. The stateless `ChatService` (see [Chat tools from the catalogue](#chat-tools-from-the-catalogue)) runs on the same turn runner.

### Records

`Entity\Conversation` (table `neuron_conversation`, audited with Gedmo loggable on create and remove and on changes of `status`, `closedAt` and `closeReason`, so a close is an `update` entry): `ownerSubject` (the user identifier of the token, for a JWT its subject; `anon:<id>` for anonymous conversations), `title` (set from the first user message, trimmed at a word boundary to 80 characters, when empty), `status` (`active`, `archived` or `closed`; only `active` accepts turns), `model` (the entry of the [model catalogue](#model-catalogue) the turns run on: written as IRI or full or partial UUID, required without an agent and checked live at creation, stored as `provider_model_id` with a foreign key without cascade, read back as the entry's IRI; NOT NULL, the migration that introduced the column aborts while a conversation without a provider connection exists, see [Migrations](#migrations); `x-collection` of the available chat models), `modelName` (read-only, the entry's name, column `model`, the name the provider is called with), `connection` (read-only, the name of the entry's [provider connection](#provider-connections), stored as `provider_connection_id` with a foreign key without cascade; a request that sends it answers 422), `provider` (read-only, the connection's type), `collections` and `mcpConnections` (stored as full UUID lists, resolved at creation and again at each turn), `retrievalDepth` (default `store.top_k`), `language` (with an agent the effective tag the turns use, validated against the version; without an agent stored as given and without effect), `variables` (start parameters of an agent conversation, JSON column, migration `Version20261003132755`; item GET only and never audited, see [Creation with an agent](#creation-with-an-agent)), `agentVersion` (IRI of an [agent version](#agent-records), read-only, set at creation from the write-only `agent`, see [Creation with an agent](#creation-with-an-agent)), `maxUserTurns` (integer of at least 1 or null = unlimited, column `max_user_turns`, migration `Version20261003065857`; fixed at creation, see [User-turn limit](#user-turn-limit)), `anonymous` (filterable with `?anonymous=true|false`), `turnCount` (every accepted user turn, including turns that ended `error` or `aborted`; compared with `maxUserTurns` and, for anonymous conversations, with the anonymous turn limit) and `tokensUsed` (input plus output tokens of all turns; kept for every conversation, limited only for anonymous ones), `closedAt` and `closeReason` (`turn_limit`, `token_limit`, `retention`, `user_turn_limit`), `createdAt`, `updatedAt`. Never serialized: `tokenHash` (SHA-256 of the capability token) and the sequence counter; `addressHash` only in the group `conversation:admin` (see [Anonymous access](#anonymous-access)). The item GET adds a read-only `running` flag derived from the conversation's advisory lock. `cancelRequestedAt` (column `cancel_requested_at`) is the cancel flag of a running streamed turn; it is neither readable nor writable over the API.

`Entity\Message` (table `neuron_message`, deleted with its conversation by the database cascade, not audited): `sequence` (gap-free per conversation, unique with the conversation), `step` (index of the provider response within the turn; groups reasoning, text and parallel tool calls), `role` (`user`, `assistant`), `kind`, `status`, `content`, `provider`, `model` (the IRI of the model catalogue entry of an assistant record, column `provider_model_id`, `ON DELETE SET NULL`), `modelName` (the name the provider was called with, column `model`; plus the id of the provider connection in the column `connection_id`, not serialized), `usage`, `sources` (the context chunks sent), the tool fields `toolName`, `toolCallId`, `toolInput`, `toolResult`, `toolError`, `durationMs`, plus `error`, `closeReason`, `responseSchema`, `structured`, `frame`, `followUps` and `providerPayload`.

| Kind | Record |
|---|---|
| `text` | user message or final assistant answer |
| `reasoning` | reasoning block of an assistant response (Anthropic thinking, with its signature in the payload) |
| `tool_call` | one tool call with input, result, duration and `toolError` |
| `schema` | final assistant answer of a turn with `responseSchema` |
| `limit` | the tool-call bound of the turn was reached and the next step was forced to answer, or the turn is the last allowed user turn and is forced from its first step (see [The turn](#the-turn)); role `assistant`, status `complete`, the reason in `content` (e.g. "Tool-call limit of 2 reached after 3 calls; the model was asked to answer." or "User-turn limit of 5 reached: this is the last turn of the conversation; the model was asked to answer."), `providerPayload` with `step` and `part` only (the bound) or `step`, `part` and `limit: "user_turns"` (the last user turn), no usage; not replayed. On an agent version with the template `limitReason` or `turnLimitReason` the content is that template's text (see [Limit texts](#limit-texts)) |
| `rule_answer` | a [pre-answer rule](#pre-answer-rules-and-templates) answered the turn with a fixed text, without retrieval or model call; role `assistant`, status `complete`, step 1, the rendered template text in `content`, `closeReason` `rule_answer`, `sources` `[]`, no usage, `providerPayload` `{step: 1, part: "rule", rule: {index, name, match, outcome, template, language}, message}`; replayed as a normal user/assistant pair |
| `rule_refusal` | a pre-answer rule refused the turn; as `rule_answer` with `closeReason` `rule_refusal` and without `message` in `providerPayload`; the turn is never replayed to the model |
| `injection_refusal` | the [prompt-injection guard](#prompt-injection-guard) of the agent version refused the turn, without retrieval or answer call; role `assistant`, status `complete`, step 1, the refusal text (template slot `injectionRefusal`) in `content`, `closeReason` `injection_refusal`, `sources` `[]`, no `frame`, `usage` the guard's tokens with their share under `usage.gates`, `providerPayload` `{step: 1, part: "gate", gate: "injection", gates: {…}}` without `message`; the turn is never replayed to the model |
| `unanswerable_refusal` | the [answerability check](#answerability) of the agent version refused the turn after retrieval, without answer call; as `injection_refusal` with `closeReason` `unanswerable_refusal`, the text of the slot `unanswerableRefusal`, the retrieved chunks in `sources`, `providerPayload.gate` `answerability` and the check's tokens under `usage.gates.answerability`; the turn is never replayed to the model |

Statuses: `streaming` (the final record of a streamed turn while it runs), `complete`, `aborted` (a cancelled streamed turn, with the text sent so far in `content`; or a record left `streaming` by a dead worker and swept by the next turn), `error` (provider failure, failed retrieval or passed deadline; the text is in `error`, a streamed turn keeps the text sent so far in `content`).

`closeReason` (column `close_reason`, `VARCHAR(20)`, nullable, added by the migration `Version20261002060217`) says why an answer was closed early: `forced_bound` on the final `text` or `schema` record of a turn whose tool-call bound forced the answer, `terminal_tool` on the final `text` or `schema` record of a turn whose answer is the result of a terminal tool (also when the terminal call was made in the forced step; see [The turn](#the-turn)), `user_turn_limit` on the final `text` or `schema` record of the last allowed user turn of a conversation with `maxUserTurns` (see [User-turn limit](#user-turn-limit)), `rule_answer` and `rule_refusal` on the record of a turn a [pre-answer rule](#pre-answer-rules-and-templates) decided (equal to its kind, also on a ruled last allowed turn), `injection_refusal` or `unanswerable_refusal` on the record of a turn an [answer gate](#answer-gates) refused (equal to its kind, also on a refused last allowed turn), null on every other record, including the `error` and `aborted` records of a turn that failed after reaching the bound and all records written before the column existed. The `text` record of a [forced-close fallback](#forced-close-fallback) carries `forced_bound` or `user_turn_limit` like a forced answer. Precedence when several apply: `terminal_tool` > `user_turn_limit` > `forced_bound`. It is in the read group, so everybody who may read the message sees it, unlike `providerPayload`. Not to be confused with the conversation's `closeReason`, which says why a conversation was closed.

`frame` (column `frame`, JSON, nullable, added by the migration `Version20261003203019`) is `{prefix, suffix}`, the rendered `frame` template of the agent version split at its `{{answer}}` (see [Frame](#frame)). It is set only on the final record of a framed turn: kind `text`, status `complete` and a close reason other than `terminal_tool`, which includes forced answers and the forced-close fallback; never on `schema`, terminal-tool, rule, gate refusal, `error` or `aborted` records, and null without a `frame` template and on all records written before the column existed. `content` stays unframed; clients show `prefix + content + suffix`. It is in the read group like `closeReason`.

`followUps` (column `follow_ups`, JSON, nullable, added by the migration `Version20261004140000`) is the list of suggested follow-up questions of an agent version with `followUps` on (see [Follow-up suggestions](#follow-up-suggestions)), set only on the final record of a turn that ended in a complete text answer: null when the follow-up gate did not run (gate off, a turn without an agent, a rule, gate refusal, schema, terminal-tool, forced-close fallback, failed or empty answer, every record but the final one and all records written before the column existed), `[]` when it ran and failed or produced nothing, else at most three strings. It is in the read group like `frame`, so message lists, the turn response and capability-token holders see it; the gate's failure note stays in `providerPayload.gates.followUps`. The suggestions are never replayed into the history of later turns.

`Entity\StreamEventRecord` (table `neuron_stream_event`, deleted with its conversation by the database cascade) maps the stored events of a streamed turn for the schema only; it is no API resource and is written and read through `Stream\StreamEventStore` (DBAL). See [Streaming](#streaming).

### API

| Operation | Access |
|---|---|
| `GET /conversations` | own conversations; all with `neuronai:conversation:read_any` |
| `POST /conversations` | `neuronai:conversation:create`; `model` (required without `agent`: IRI or full or partial UUID of an entry of the [model catalogue](#model-catalogue), which names the connection), `collections` (UUID, IRI or name), `mcpConnections` (IRI, full or partial UUID, name), `retrievalDepth`, `title`, `maxUserTurns` (integer of at least 1 or null), `language`, `agent` (write-only) and `variables` (only with `agent`); with `agent` the version fixes everything but `title`, `maxUserTurns`, `language` and `variables` (see [Creation with an agent](#creation-with-an-agent)); `provider` and `connection` are read-only (422 when sent); 422 at `model` for a missing, unknown or ambiguous model reference, an unavailable entry, an entry of an inactive connection or one known to lack `completion`, a model the connection does not offer or that cannot be verified because the provider is unreachable; 422 for an unknown reference, a `maxUserTurns` of 0 or less, an unknown, disabled or unpublished agent, a key the agent version fixes, an unsupported language, invalid variables or `variables` without an agent; 403 for a [restricted](#configuration-schema) MCP connection in `mcpConnections` without `neuronai:mcp_connection:use_restricted` (an agent conversation uses the connections of its version unchecked) |
| `POST /conversations/anonymous` | public; body `{}` only; returns the conversation and its capability token (see [Anonymous access](#anonymous-access)) |
| `GET /conversations/{id}` | owner or `neuronai:conversation:read_any` |
| `DELETE /conversations/{id}` | owner or `neuronai:conversation:delete_any` |
| `POST /conversations/{id}/messages` | owner only (the turn) |
| `POST /conversations/{id}/stream` | owner only (the streamed turn, see [Streaming](#streaming)) |
| `GET /conversations/{id}/stream` | owner or `neuronai:conversation:read_any` (resume) |
| `POST /conversations/{id}/cancel` | owner only (`conversation_cancel`) |
| `GET /conversations/{id}/messages` | owner or `neuronai:conversation:read_any`; 404 otherwise |
| `POST /conversations/{id}/replay` | `neuronai:eval:write` (`conversation_replay`, asynchronous; see [Conversation replay](#conversation-replay)) |
| `GET /conversations/{id}/replay_run` | readers of the replay conversation `{id}` (the replay record) |
| `POST /conversations/{id}/replay_run/cancel` | `neuronai:eval:write` (`conversation_replay_cancel`) |
| `GET /messages?conversation=<iri>`, `GET /messages/{id}` | records of readable conversations, ordered by `sequence`; the list needs `neuronai:conversation:list` |

The item operations, the turn and the message sub-resource also accept the capability token of an anonymous conversation for exactly that conversation; the lists answer it with 403.

Unknown conversations answer 404. A foreign conversation answers 403 on the item operations (the security expression runs on the loaded entity) and 404 on the message sub-resource, so the sub-resource does not reveal that it exists; lists contain only the conversations and records the user may read. A partial UUID is accepted by the resolver but answers 404 over HTTP for everybody, because API Platform converts the URI variable to a `Uuid` before any provider runs (the same platform limitation as `/admin/collections/{id}`).

### The turn

`POST /conversations/{id}/messages` with `{"content": "…", "responseSchema": {…}, "maxToolCalls": 4, "terminalTools": ["submit_answer"]}` (`content` required and non-empty, `responseSchema` optional, `maxToolCalls` optional, an integer of at least 1 or null, where 0, a negative number or a non-integer answers 422; `terminalTools` optional, a list of unique non-empty strings or null, where a duplicate or a non-string entry answers 422; no further keys; validated against `Entity/Conversation/turn.input.json`, which is also the OpenAPI request body) runs one turn and returns the final assistant record with 201. An archived or closed conversation is refused first with 422 and a `code` (a conversation closed by its user-turn limit with `user_turn_limit`, see [User-turn limit](#user-turn-limit)), and an anonymous conversation is checked against its limits (see [Limits](#limits)); nothing reaches the provider then.

1. The advisory lock of the conversation is taken; another running turn answers 409.
2. Records left `streaming` by a dead worker become `aborted`.
3. The stored collections and connections are resolved; one that is gone or rejected answers 422.
4. The history is rebuilt from the complete earlier turns, the user record is stored, context is retrieved with the user message as query and capped, and the agent runs with the conversation's provider connection, model and tools.
5. Every response of the run is stored: reasoning, tool calls (a failing tool becomes a `tool_call` record with `toolError` true and the error text as result; the turn continues) and the final `text` or `schema` record.

Every run builds its own agent (`Conversation\TurnAgent`, or `Conversation\TerminalToolAgent` with terminal tools) and binds it to the conversation's UUID as its neuron-ai thread id; neuron-ai 4.x runs an agent only with a bound thread and never rebinds one, so the agent is not shared between turns. A run without a conversation (the [stateless chat](#chat-tools-from-the-catalogue), evaluation) binds a fresh UUID v4. The rebuilt history goes into the in-memory message store of that agent.

The result of an MCP tool is neuron-ai's `ToolOutput`. The turn keeps only its text: the `tool_call` record and the `tool_result` stream event carry the text blocks joined by a blank line (image and audio blocks are dropped), not the JSON of the MCP content list, and a result the server flags `isError` makes the call a tool error (`toolError` true, `error` true in the event), like a failing tool. A JSON-RPC error of `tools/call` is thrown by the client and recorded as a tool error with the server's message.

A provider failure or a passed deadline does not change the status code: the turn answers 201 with an assistant record of status `error`, and the user record keeps its sequence. A stored model that the connection has meanwhile stopped offering yields such an `error` record as well (the model is checked live only at creation). A conversation whose connection is missing, deleted or inactive is refused with 422 before the turn runs. Conversations without an agent have no instructions of their own; the system prompt holds the retrieved context and, with `responseSchema`, the schema instruction. An agent conversation adds the instructions of its pinned version and takes provider connection, model, collections and tools from the version instead of its columns (see [Turns of an agent conversation](#turns-of-an-agent-conversation)).

With `responseSchema` the final record has kind `schema`. The schema is requested in the instructions and, where the provider can enforce it, also sent to the API (see [Structured outputs](#structured-outputs)). A matching answer is decoded into `structured`. A mismatch keeps status `complete`, leaves `structured` null, puts the message into `error` and the raw validation errors into `providerPayload.schema_errors`; there is no separate column for them.

`turn.deadline_seconds` (default 120, at least 1) is the wall-clock deadline of a turn and the HTTP timeout of each provider call: an observer stops the run before the next provider or tool call once the deadline has passed, so the worst case is the deadline plus one call in flight. Turns are meant to run in a php-fpm pool without `request_terminate_timeout`, where this deadline is the only cap. `turn.context_max_chunks` (8) and `turn.context_max_chars` (6000) cap the retrieved context of conversations without an agent and of agent versions stored without a cap of their own; `sources` lists exactly the chunks sent, with `content` as sent. Agent versions cap with their own `contextMaxChunks` and `contextMaxChars` and widen hits from uploaded documents by their neighbours, and `turn.context_instruction` is the instruction text in front of the context (see [Context shaping](#context-shaping)). `turn.retrieval_history_messages` (default 2, 0 = off) adds that many earlier user messages of the conversation to the search text (`Conversation\RetrievalQuery`), newest first after the current one, so a follow-up ("and is there an example?") or a submitted form finds the passages of its topic; a form submission `{"form": …, "value": {…}}` is searched by its values. The same holds for the stateless chat and the agent chat with earlier `messages`; the evaluation's retrieval measurement searches the question alone. `turn.history_context_window` (200000 tokens) is the window of the in-memory history the turns are replayed into.

**Tool-call bound.** neuron-ai stops a tool loop only per tool (the maximum runs of one tool) and the turn only at its deadline, so a model can keep calling tools until the deadline passes. `maxToolCalls` of the request, or else `turn.max_tool_calls_default`, bounds the executed tool calls of one turn of a conversation without an agent (the request value may also raise the bound above the default); for a conversation created with an agent the bound is `LimitResolver::resolve(version maxToolCalls, settings max_tool_calls, turn.max_tool_calls_default, request maxToolCalls)`, so the request may only lower it (see [Limit defaults](#limit-defaults)); with no bound at all the turn is unbounded, no middleware is registered and the records are byte-identical to those of a turn before the bound existed. With a bound, neuron-ai's own limit of runs per tool (10 by default) is lifted, so it never refuses a call below the bound. `Conversation\ToolCallBoundMiddleware` is registered on the agent's tool node through the public `addMiddleware()` API (the node match is subclass-aware, so the parallel tool node is covered too) and counts every executed call, each call of a parallel response separately. The bound is checked after a response's tool calls have run, not between them: the whole batch that reaches or crosses the bound still executes (a bound of 1 and a response with two parallel calls runs both, the reason then reads "Tool-call limit of 1 reached after 2 calls; …"). The next inference is the forced step:

- on a provider that supports the tool choice `none` (Anthropic, see [Forced tool choice](#forced-tool-choice)) it is sent with `tool_choice` `none` and the closing instruction `ForcedClose::INSTRUCTION` appended once to the system prompt (without it a model stopped mid-research often answered with an empty text); the tool definitions stay in the request, so the earlier tool calls and results in the history stay valid;
- otherwise (Ollama, or a provider that declines `none`) the fallback applies: the tools are withdrawn from the request and the closing instruction `ForcedClose::INSTRUCTION` ("The tool-call limit of this turn is reached. Do not call any further tool; answer the user now with the information you already have.") is appended once to the system prompt.

The turn writes a `limit` record with the step index of the forced inference, directly before the forced answer, and the forced answer (`text` or `schema`) gets `closeReason` `forced_bound`, in both modes. A forced inference that answers with another tool call fails the turn with `ForcedCloseFailedException` before any tool of that response runs; the records are then the earlier steps, the `limit` record and an `error` record with "The forced close did not produce an answer." and no close reason; text the forced inference wrote before that tool call is kept as a `complete` `text` record of the forced step without close reason, between the `limit` and the `error` record. On an agent version with the template `forcedCloseFallback`, a failed forced close and an empty forced answer are answered with that text instead, without an `error` record (see [Forced-close fallback](#forced-close-fallback)). The deadline observer still applies to the forced step: a deadline that passes before it ends the turn with the `limit` record followed by the `error` record. A tool choice set for the forced step is cleared when the run ends, so a provider instance that outlives the run does not force the next one. The history replay of the next turn drops `limit` records and replays the forced answer like any final answer. The streamed turn applies the same bound (`maxToolCalls` in the stream body) and stores the same records; it reports the bound with a `limit` event before the forced answer and the close reason in `closeReason` of the `done` event (see [Tool-call bound in the stream](#tool-call-bound-in-the-stream)).

**Terminal tools.** `terminalTools` of the request names offered tools whose call ends the turn: when the model calls one, the tool runs, the turn ends without a further model call and the tool's result becomes the answer. Without `terminalTools` (null or `[]`) the turn runs, records and streams like a turn without them. Every name must be a tool offered to the turn, i.e. a catalogued tool of the conversation's connections that `Mcp\McpToolFactory` built (a row the factory skipped counts as unknown); an unknown name answers 422 with the name in the message, before any provider call. The synchronous turn, the stream body and `/chat` (see [Chat tools from the catalogue](#chat-tools-from-the-catalogue)) accept the field alike. The rule lives in `Conversation\TerminalClose`. Only when the request names terminal tools, the turn runs `Conversation\TerminalToolAgent`, which installs `Conversation\TerminalToolNode`: the node runs the batch as upstream does, commits the tool results to the chat history and stops the run when the batch contains a terminal call. These two classes use protected neuron-ai 4.x API: `TerminalToolAgent::nodes()` builds the agent graph with `TerminalToolNode` in place of upstream's tool node, and the node ends the run with an `AgentOutputEvent` when the batch contains a terminal call; together with the history trimmer of the replay (see [Limitations](#limitations-1)) they are the adapter to upstream internals; neuron-ai is not patched.

- **Which call answers.** Every call of the batch runs; the first terminal call in call order whose execution did not fail answers. A terminal call whose execution failed (the tool error handler turned the exception into the result, `toolError` true) is not terminal: the loop continues as without terminal tools, the call counts towards the bound, and the model may retry or answer; in the forced step this is a failed forced close. A terminal call in the batch that reaches or crosses the bound wins: no `limit` record, no `limit` event, `limitReached` false.
- **The answer.** The result of a catalogue tool is the JSON-encoded MCP content list (`[{"type": "text", "text": "…"}]`), because neuron-ai encodes the content blocks it receives from `tools/call`. When the raw result decodes to such a list (a non-empty JSON list whose every item has a string `type`), the answer text is the `text` of its `text` items joined by a blank line (other item types are ignored; empty when there is none); any other result, including an empty list `[]` and a list with an item without a string `type`, is the answer text as is. With `responseSchema` the answer text is validated like a model answer and the final record has kind `schema`: a matching answer is decoded into `structured`, invalid JSON or a schema violation gives the soft failure described above (status `complete`, `structured` null, the message in `error`, `providerPayload.schema_errors`). Without a schema the final record has kind `text`, and `structured` holds the decoded answer text when it decodes to a JSON object or list, else null (also for a JSON scalar); `providerPayload` then has no `schema_errors` key, as for a model's text answer.
- **Records.** The records of the terminal step are the reasoning (if any), the text the model wrote before the call (if any, a `text` record of that step), the `tool_call` records with the raw result, and the final record: kind `text` or `schema` at the step index of the tool-call step, status `complete`, the answer text in `content`, `closeReason` `terminal_tool`, `durationMs` of the terminal call, no usage, and `providerPayload` `{step, part: "terminal", tool: {name, callId}}` without a message payload. The history replay of the next turn recognises such a turn by the `closeReason` of the final record and replays the tool call and its result as the end of the turn, without a synthetic assistant message.
- **Forced close.** When the tool-call bound is reached and the request names terminal tools, the forced step is sent with the tool choice `tool` on the first terminal name in request order where the provider supports it (`limit` mode `terminal_tool`); otherwise exactly as without terminal tools, with `none` where supported (mode `tool_choice`), else the fallback (mode `fallback`). A forced-step batch is accepted when it contains a terminal call and then runs whole; the final record gets `closeReason` `terminal_tool`, not `forced_bound`, and the `limit` record still precedes the step. With `none` or the fallback the model cannot call the terminal tool, and the answer is a forced text with `forced_bound`. When the terminal call of the forced step fails (the tool error handler turned the exception into the result), the batch does not end the turn, and the turn fails with `ForcedCloseFailedException` ("The forced close did not produce an answer: the terminal tool call failed."); the records are then `[…, limit, tool_call (forced step), error]`.

`/chat` returns the name of the terminal tool that answered (see [Chat tools from the catalogue](#chat-tools-from-the-catalogue)); the streamed turn sends a `terminal_tool` event before `done` (see [Streaming](#streaming)).

Every round of a tool loop re-sends instructions, tools and history; on Anthropic they are read from the prompt cache (see [Structured outputs](#structured-outputs)). Not part of the bound yet: handling of extended thinking beyond the guard described below. The bound per agent and the application default apply to conversations created with an agent only (see above).

#### Creation with an agent

`POST /conversations` accepts the write-only field `agent`: the IRI `/api/agents/<uuid>`, a full UUID or a slug, resolved in that order by `Agent\AgentReferenceResolver` (a UUID that matches no agent is tried as a slug; partial UUIDs are not accepted). Such a conversation is an **agent conversation**: it pins the agent's **current published version** as `agentVersion` and runs every turn on it (see [Turns of an agent conversation](#turns-of-an-agent-conversation)); later publishes and rollbacks of the agent do not change it.

The version fixes the configuration. Besides `agent` the body may carry only:

- `title`;
- `maxUserTurns`, which may only lower the limit of the version or the settings default (see [User-turn limit](#user-turn-limit));
- `language`, one of the version's `languages`; another tag answers 422 `language: "<tag>" is not supported by agent "<slug>" (supported: <list>).`, and an omitted one means the version's `defaultLanguage`. The effective tag is stored;
- `variables`, the start parameters: a JSON object of scalar values, validated against the version's `startSchema` (see [Placeholders and variables](#placeholders-and-variables)). Omitted or null means none; a JSON list answers 422 `"variables" must be a JSON object.`, a string or number 400 (the serializer refuses it before the processor runs).

`instructions`, `model`, `collections`, `mcpConnections`, `retrievalDepth`, `hybrid`, `hybridWeight`, `responseSchema`, `terminalTools`, `rules` and `templates` answer 422 `"<key>" is fixed by the agent version and cannot be set when creating a conversation with an agent.` (`connection` answers 422 with or without `agent`: it is read-only.); the check runs on the raw request body before anything else, because API Platform has already denormalised the conversation's own properties. `variables` without `agent` answers 422 `"variables" require an agent.`

The creation maps the version exactly as a turn will (`Agent\VersionTurnMapper`), so a combination that could never run is refused at creation and not at the first turn: an unsupported language, invalid variables and a reference of the version that no longer resolves (422 with its path, see [Turns of an agent conversation](#turns-of-an-agent-conversation)) store nothing. The version values are then copied into the columns for the representation: the model catalogue entry (`model`, with `provider`, `connection` and `modelName` from it), `collections`, `retrievalDepth`, the effective `language`, `mcpConnections` (the unique connection UUIDs of the version's `mcpTools`, in the order of first use) and `variables` (empty when none were given); `maxUserTurns` is resolved by `Agent\LimitResolver`. The turns do not read these columns; they map the pinned version again.

An unknown, disabled or unpublished agent answers 422 with one of these details: `Unknown agent "<ref>".`, `Agent "<ref>" is disabled.`, `Agent "<ref>" has no published version.` `enabled` is thus the creation gate for agent conversations; a conversation created before the agent was disabled keeps running. The stateless `POST /agents/{id}/chat` honours `enabled` as well (see [Agent chat](#agent-chat)); there is no `ask` action. Anonymous conversations get their agent from the access policy, never from the body (see [Access policy](#access-policy)).

**Exposure of `variables`.** Start parameters can hold personal data (a customer name, a case number). They are part of the item group only: the item GET shows them to the owner and to holders of `neuronai:conversation:read_any`, like message content. They are never in a list, in the creation response or in the turn response, and the column is not audited, so they never reach `dmstr_log_entries`.

```bash
curl --request POST \
  --header "Authorization: Bearer …" \
  --header "Accept: application/ld+json" \
  --header "Content-Type: application/ld+json" \
  --data '{"agent":"support-de","maxUserTurns":5,"language":"de","variables":{"customer":"ACME GmbH"}}' \
  https://…/api/conversations
```

#### Start turn

A version with a `startMessage` (per language tag, placeholders as in `instructions`) starts the first turn itself: the agent acts before the user types anything, e.g. it starts a job from the start parameters.

- **Creation.** After the conversation is stored, `Conversation\ConversationStarter` renders the start message for the effective language and the `variables` and runs `StreamingTurnService::begin()`: the user record (the start message), the `streaming` placeholder and the turn lock exist before the 201 answer is sent. The answer is the same as without a start message.
- **Run.** On `kernel.terminate`, after the answer has gone out (the starter calls `fastcgi_finish_request()` itself, because the Symfony runtime skips it in debug mode), the turn runs to its end with a sink that discards its output; its events are stored as for every streamed turn.
- **Client.** It follows the turn with `GET /conversations/{id}/stream` (resume without `Last-Event-ID`): the stored events, then the live ones until `done`. A client that does not stream reads the records once the turn is done; a `POST …/messages` or `…/stream` while it runs answers 409.
- **Why in the request.** The MCP tools of `forward_jwt` connections need the caller's JWT, which exists only in the authenticated request, and the turn lock is a session lock of the database connection, so `begin()` and `run()` share one process. `POST /api/conversations` must therefore be served by a pool without a run-time limit (the same pool as the streamed turn, see Routing in [Streaming](#streaming)).
- **Failures.** A start that fails in `begin()` is logged and leaves the conversation without a first turn; the creation is not undone. A failure of the turn itself ends as an `error` record and event, like any streamed turn.

#### Turns of an agent conversation

Every turn of an agent conversation, synchronous and streamed, maps the pinned version with the stored `variables` and `language` (`TurnPersistence::agentConfigurationFor()`, under the turn lock) and takes everything from the result: the instructions of the language (with the placeholders filled) as system prompt, provider, model, response schema, collections, retrieval depth and hybrid search (`hybrid`, `hybridWeight`, see [Hybrid search](#hybrid-search)), the tool subset of the version's `mcpTools` (exactly the referenced tools of each connection, not every tool of the connection), its terminal tools, the tool-call bound and sampling (`temperature`, `maxTokens`; wired into the provider request, not verified against live providers). With a response schema the user record stores the schema in `responseSchema` and the final record (in a streamed turn already its placeholder) has kind `schema`, as in a turn with `responseSchema`. The conversation's own columns are not used for the run.

- `responseSchema` and `terminalTools` in the turn body (also `[]`) answer 422 `"<key>" is fixed by the agent version and cannot be set on a turn of an agent conversation.`, before the anonymous rules and before any provider call.
- `maxToolCalls` may only lower the bound resolved from the version, the settings default and `turn.max_tool_calls_default` (clamped silently, see [Limit defaults](#limit-defaults)).
- A reference of the version that no longer resolves (a deleted collection, an inactive connection, a tool that left the catalogue or that `Mcp\McpToolFactory` cannot build, a model catalogue entry that no longer exists, a provider connection of the entry that was deactivated or is no provider connection, a version without a model entry) answers 422 before any record is stored, with the detail `Agent version <n> of "<slug>" (<iri>): <path>: <reason>` and the path `configuration/collections/<i>`, `configuration/mcpTools/<i>/connection`, `configuration/mcpTools/<i>/name` or `configuration/model`. The model is not checked live per turn; a model the connection has stopped offering fails in the provider call (`error` record). The turn never runs with fewer tools than the version references.
- The version's [pre-answer rules](#pre-answer-rules-and-templates) are evaluated on the user message after the version is mapped (so a stale reference still answers 422) and before history, retrieval and any provider call; a rule that answers or refuses stores the user record and one rule record (`rule_answer` or `rule_refusal`) and ends the turn without a model call. The turn response is that record with 201; the streamed turn sends `sources`, `rule` and `done` (see [Rules in the stream](#rules-in-the-stream)).
- When no rule decided, the version's [answer gates](#answer-gates) run: with `injectionGuard` on, the prompt-injection guard checks the user message before retrieval and before any answer call; a refusal stores the user record and one `injection_refusal` record and ends the turn without retrieval or answer call. The turn response is that record with 201; the streamed turn sends `sources`, `gate` and `done` (see [Gates in the stream](#gates-in-the-stream)).
- Publishing or rolling back the agent after the creation changes nothing for a running conversation, and neither does disabling the agent: the conversation keeps its version and runs until it is closed.

#### Placeholders and variables

Instructions may contain placeholders `{{name}}` (whitespace inside the braces is tolerated; a name starts with a letter or underscore). At every turn, and in the [agent chat](#agent-chat), they are replaced by plain text from the caller's variables and three system values: `{{date}}` (today in UTC, evaluated per turn, so a conversation that spans midnight sees the new date), `{{language}}` (the effective language tag) and `{{agentName}}` (the agent's name). When the version has no instructions text for the effective language, the text of its `defaultLanguage` applies.

The variables are checked against the version's `startSchema`; every error answers 422 at `variables/<name>`: a system value name (`"date" is a system value and cannot be supplied.`), a value that is not a string, number or boolean, a variable the schema does not declare (`not declared by the agent version.`; without a `startSchema` no variable may be given), a schema violation, and a placeholder of the instructions without a value (`required by the instructions.`). Output templates use the same placeholder syntax with a different rule for a missing value: it renders as empty text instead of answering 422 (see [Placeholders in templates](#placeholders-in-templates)).

#### User-turn limit

`maxUserTurns` limits the user turns a conversation accepts (null = unlimited; 0 or a negative value answers 422). It is fixed at creation; there is no PATCH. The effective value is resolved once at creation by `Agent\LimitResolver`:

- **With an agent:** the base is the first non-null of the version's `maxUserTurns` and the settings default (`agent_limits.max_user_turns`, see [Limit defaults](#limit-defaults)); there is no bundle default. A request value may only lower the base and is clamped silently, so the representation shows the effective limit; without a base the request value applies.
- **Without an agent:** the request value as given; the settings default does not apply.
- **Anonymous conversations** take no creation value over the API (the anonymous endpoint takes `{}`). With an agent in the access policy the limit is resolved from the version and the settings default like any agent conversation, otherwise there is none (see [Access policy](#access-policy)). A `maxUserTurns` set this way, or directly on the entity, works alongside the anonymous limits: the smaller limit closes the conversation with its own reason and code, and on a tie `user_turn_limit` wins.

**The last allowed turn** (`turnCount + 1 >= maxUserTurns`, decided under the turn lock on the freshly read counter) is answered under a forced close from its **first** inference, synchronous and streamed alike: there is no tool loop, also without a tool-call bound. The modes are those of the bound, in the same order: `terminal_tool` (the request names terminal tools and the provider supports `tool:<name>`; the first terminal tool is forced regardless of what the user asked, as at the bound), `tool_choice` (the provider supports `none`; also for conversations without tools where supported), `fallback` (the tools are withdrawn). With `tool_choice` and `fallback`, `ForcedClose::TURN_LIMIT_INSTRUCTION` ("This is the last turn of this conversation; the user cannot reply again. Do not call any tool; give a complete, final answer now.") is appended once to the system prompt instead of the instruction of the bound.

The turn writes a `limit` record at step 1 with the content "User-turn limit of N reached: this is the last turn of the conversation; the model was asked to answer." (or the version's `turnLimitReason` template, see [Limit texts](#limit-texts)) and `providerPayload` `{step, part: "limit", limit: "user_turns"}` (the `limit` record of the bound has no `limit` key), then the answer with `closeReason` `user_turn_limit`; an answer given by a terminal tool keeps `closeReason` `terminal_tool`. A forced step that answers with a non-terminal tool call fails the turn with `ForcedCloseFailedException` before any tool runs: the records are `[limit (step 1), error (step 2)]`. A failed forced terminal call fails the turn as well. With a `forcedCloseFallback` template both, and an empty forced answer, are answered with the fallback text instead (see [Forced-close fallback](#forced-close-fallback)). An `error` or `aborted` last turn still counts and closes the conversation: the user loses the last turn without an answer.

**Closing.** The transaction that stores the final record and counts the turn also closes the conversation with `closeReason` `user_turn_limit` once `turnCount >= maxUserTurns` (checked before the anonymous limits). Every further turn, synchronous or streamed, answers 422 with `code` `user_turn_limit` (problem type `/conversation_errors/user_turn_limit`, title `The conversation reached its user-turn limit.`); the stream route answers it as `application/problem+json` before any `event:` byte. The gate also guards active conversations whose `turnCount` already reached `maxUserTurns` (the DBAL fallback of a failed final flush counts without closing): such a conversation is closed at its next turn's gate, and that turn answers 422 `user_turn_limit`. Records a dead worker left `streaming` become `aborted` and are never counted.

The streamed last turn announces itself with a `turn_limit` event (see [User-turn limit in the stream](#user-turn-limit-in-the-stream)).

**A ruled last turn.** When a [pre-answer rule](#pre-answer-rules-and-templates) answers or refuses the last allowed turn, no inference is forced: the turn stores the user record and the rule record only, without a `limit` record, and the streamed turn sends no `turn_limit` event. The turn still counts (+1 turn, +0 tokens) and closes the conversation with `closeReason` `user_turn_limit`, while the rule record and `done.closeReason` carry `rule_answer` or `rule_refusal`; every further turn answers 422 `user_turn_limit` as usual. The same holds for the anonymous turn limit: a ruled turn that reaches `maxTurnsPerConversation` closes the conversation with `turn_limit`.

**A refused last turn.** A last allowed turn that an [answer gate](#answer-gates) refused behaves like a ruled one: no inference is forced, there is no `limit` record and no `turn_limit` event, the turn counts (+1 turn, + the gate's tokens) and closes the conversation with `closeReason` `user_turn_limit`, while the refusal record and `done.closeReason` carry `injection_refusal`.

### Streaming

The streamed turn is the same turn as `POST /conversations/{id}/messages` (gate, lock, orphan sweep, resolution, history, user record, retrieval, run, records) with a different delivery: the answer arrives as a Server-Sent-Events stream while it is generated. Three routes:

| Route | Access | Answers |
|---|---|---|
| `POST /conversations/{id}/stream` | `neuronai:conversation:turn_own` (owner) | 200 `text/event-stream`; body as for the turn (`content`, optional `responseSchema`, optional `maxToolCalls`, optional `terminalTools`, validated against `turn.input.json`; without `maxToolCalls` the default `turn.max_tool_calls_default` applies, unbounded unless configured, or for a conversation created with an agent the bound resolved from its version, see [The turn](#the-turn)); 400 malformed JSON, 403 foreign conversation, 404 unknown id, 409 another turn runs, 422 schema violation, a conversation that accepts no turn (with a `code`, see [Limits](#limits) and [User-turn limit](#user-turn-limit)), `responseSchema` on an anonymous conversation, `responseSchema` or `terminalTools` on an agent conversation, a reference that is gone (for an agent conversation a reference of its version, with its path) or a terminal tool name that is not an offered tool, 503 anonymous conversation without access policy |
| `GET /conversations/{id}/stream` | `neuronai:conversation:read_own` (owner or `neuronai:conversation:read_any`) | 200 `text/event-stream` (resume, optional header `Last-Event-ID`); 400 for a `Last-Event-ID` that is not a non-negative integer, 403, 404 when the conversation is unknown or has neither stored events nor a running turn |
| `POST /conversations/{id}/cancel` | `neuronai:conversation:turn_own` (owner) | 200 with the conversation including `running`; no body; 403 foreign conversation |

Every client error is answered before the stream starts; once the stream runs, failures are events. The two stream routes are a classic controller (`Controller\ConversationStreamController`, routes in `config/routes.php`, imported by the app with prefix `/api`) returning a `StreamedResponse`, because an API Platform processor cannot stream: they are not part of the OpenAPI document or the Hydra documentation. The cancel action is a regular API Platform operation.

**Protocol.** Every event is a block `id: <int>`, `event: <type>`, `data: <json>` (one line of JSON); `: ping` comment lines keep an idle resume alive. Order: `sources` first; on a turn a [pre-answer rule](#pre-answer-rules-and-templates) decided then exactly one `rule` and `done` (see [Rules in the stream](#rules-in-the-stream)); on a turn an [answer gate](#answer-gates) refused then exactly one `gate` and `done` (see [Gates in the stream](#gates-in-the-stream)); otherwise at most one `turn_limit` (see [User-turn limit in the stream](#user-turn-limit-in-the-stream)), then any number of `reasoning`, `token`, `tool_call` and `tool_result` with at most one `limit` among them (see [Tool-call bound in the stream](#tool-call-bound-in-the-stream)), then at most one `terminal_tool` (see [Terminal tools in the stream](#terminal-tools-in-the-stream)), then at most one `frame` (see [Frame in the stream](#frame-in-the-stream)), then at most one `follow_ups` (see [Follow-up suggestions](#follow-up-suggestions)), then exactly one `done` or `error`.

| Event | Payload |
|---|---|
| `sources` | `messageId` (the id of the final assistant record), `sources` (the context chunks sent, `[]` when nothing was retrieved) |
| `token` | `text`, a delta of the answer |
| `reasoning` | `text`, a delta of a reasoning block (never merged into `token`) |
| `tool_call` | `name`, `callId`, `input` (an object) |
| `tool_result` | `name`, `callId`, `result` (for an MCP tool the text of its output, see [The turn](#the-turn)), `error` (bool), `durationMs` |
| `limit` | `messageId` (the id of the final assistant record, as in `sources`), `step` (index of the forced step), `reason` (the text of the `limit` record), `maxToolCalls` (the bound), `toolCalls` (executed calls when the bound was reached, parallel calls counted), `mode` (`tool_choice`, `terminal_tool` or `fallback`); all keys always present |
| `terminal_tool` | `messageId` (the id of the final assistant record), `step` (index of the tool-call step), `name` and `callId` (the terminal call that answered), `kind` (`text` or `schema`), `answer` (the answer text), `structured` (or null), `schemaErrors` (or null); all keys always present |
| `turn_limit` | `messageId` (the id of the final assistant record), `step` (index of the forced inference, 1), `reason` (the text of the `limit` record), `maxUserTurns` (the conversation's limit), `turn` (the 1-based number of this turn, equal to `maxUserTurns`), `mode` (`tool_choice`, `terminal_tool` or `fallback`); all keys always present |
| `rule` | `messageId` (the id of the rule record), `index` (0-based position of the deciding rule in `rules`), `rule` (its name or null), `match` (`keywords`, `pattern` or `language`), `outcome` (`answer` or `refuse`), `template` (the template name), `language` (the effective language), `message` (the rendered text, equal to the record's `content`); all keys always present |
| `frame` | `messageId` (the id of the final assistant record, which carries the same object in `frame`), `prefix`, `suffix` (the rendered `frame` template of the agent version split at its `{{answer}}`; either may be empty); all keys always present |
| `gate` | `messageId` (the id of the refusal record), `gate` (`injection` or `answerability`), `language` (the effective language), `message` (the refusal text, equal to the record's `content`); all keys always present; the gate's reason is never sent |
| `follow_ups` | `messageId` (the id of the final assistant record, which carries the same list in `followUps`), `followUps` (list of 1 to 3 strings); all keys always present; sent only when the stored final record is `complete` and has a non-empty `followUps` list |
| `done` | `messageId`, `status` (`complete` or `aborted`), `provider` (the connection's type), `model`, `connection` (the name of the provider connection the turn ran on), `usage` (summed over the turn's responses and its [answer gates](#answer-gates), with the gates' share under `usage.gates` whenever a gate ran; null when the provider reports none and no gate ran, and when a rule decided the turn), `closeReason` (`forced_bound` when the answer was forced by the tool-call bound, `terminal_tool` when a terminal tool answered, `user_turn_limit` for the forced answer of the last allowed user turn (both also for a [forced-close fallback](#forced-close-fallback) answer), `rule_answer` or `rule_refusal` when a pre-answer rule decided the turn, `injection_refusal` when the prompt-injection guard refused it, `unanswerable_refusal` when the answerability check refused it, else null; always present) |
| `error` | `messageId`, `status` (`error`), `message` |

Deltas are batched: one open buffer per type, flushed when 100 ms passed since the last flush or 200 characters are buffered, before any event of another type, at the end of a provider response and before the terminal event. A failure in the middle of a stream sends the buffered text first, so the client has exactly the text the record keeps.

**Records.** After the user record the final assistant record is stored at once as a placeholder with status `streaming` and the next sequence; its id is `sources.messageId` and `done.messageId`. At the end the step records (reasoning, text and tool calls of earlier responses) are inserted below it in one transaction and the placeholder moves to the turn's last sequence, so the layout and `nextSequence` equal those of the non-streaming turn. The `done` or `error` event is sent only after the record is final. Should the entity manager fail, the placeholder's status, error and content are set with a plain DBAL update, so no record stays `streaming`; a worker that dies leaves it to the next turn's sweep.

**Cancel.** `POST /conversations/{id}/cancel` sets `cancel_requested_at` while a turn holds the conversation's lock and is a no-op otherwise (also after the turn finished); the response says whether a turn is `running`. The running turn reads the flag before every batch flush (at most one query per 250 ms) and at the start of every provider call, before and after every tool call. It therefore stops at the next delta, at the end of the current tool call or before the next inference; a provider call or tool call in flight is not interrupted. The turn ends with `done` and `status: "aborted"`; the final record is `aborted` with the text sent for the step in flight as `content`, and the completed steps keep their records. The history of later turns skips the aborted turn like any incomplete one. The next turn clears the flag. The non-streaming turn is not cancellable.

**Resume.** A client that lost the connection reconnects with `GET /conversations/{id}/stream` and the id of the last event it received in `Last-Event-ID`. The server replays the stored events with a higher id, `turn_limit`, `limit`, `terminal_tool`, `rule`, `gate` and `frame` included (all of them without the header or for an id older than the stored ones), then, while the turn still runs, polls for new events every 200 ms (with a `: ping` every 15 s) until `done` or `error`. When the turn's worker died without a terminal event, the stream ends with an unstored `error` event whose `messageId` is null and whose message is `The turn ended without a result.`. Every event is stored before it is sent (`neuron_stream_event`, written with DBAL in autocommit, ids monotonic across turns); a failed write is logged and the event is sent without `id:`. The events of a conversation are kept until its next turn starts (streamed or not) or the conversation is deleted.

**Disconnect.** A client that goes away without cancelling does not stop the turn: the controller ignores the abort, stops writing and lets the turn finish and store its records; the deadline still bounds it.

**Routing.** Serve `POST` and `GET /api/conversations/{id}/stream` from a PHP-FPM pool of their own with output buffering off and no `request_terminate_timeout`, and let the reverse proxy flush every write (for example Caddy's `flush_interval -1`), so every event reaches the client when it is written. Route the cancel to the regular pool on purpose: when all workers of the stream pool are busy with streams, a cancel there could not get a worker exactly when it is needed.

Consuming the stream with curl (`--no-buffer` disables curl's own output buffering):

```bash
curl --no-buffer \
  --header "Authorization: Bearer …" \
  --header "Accept: text/event-stream" \
  --header "Content-Type: application/json" \
  --request POST \
  --data '{"content":"…"}' \
  https://…/api/conversations/<id>/stream

# resume after event 12
curl --no-buffer \
  --header "Authorization: Bearer …" \
  --header "Accept: text/event-stream" \
  --header "Last-Event-ID: 12" \
  https://…/api/conversations/<id>/stream

# cancel
curl --request POST \
  --header "Authorization: Bearer …" \
  --header "Accept: application/ld+json" \
  https://…/api/conversations/<id>/cancel
```

Browsers consume the stream with a streamed `fetch()`, because `EventSource` cannot send an `Authorization` header; there is no query-string token.

Limits:

- **Worker occupancy.** Every open stream and every live resume holds one worker of the stream pool for its whole duration, shared with the non-streaming turn and `/chat`; further requests wait in the pool's backlog.
- **Provider timeout.** The HTTP timeout of a provider call equals `turn.deadline_seconds` and covers the whole streamed response, so a single response longer than the deadline is cut by the HTTP client and ends the turn with an `error` record.
- **HTTP/1.1.** Without TLS a browser opens at most six connections per origin, and every open stream takes one; serve the streams over HTTP/2.

#### Tool-call bound in the stream

When the tool-call bound of [The turn](#the-turn) is reached, the stream sends exactly one `limit` event: after the flushed deltas and the `tool_call`/`tool_result` events of the batch that reached the bound, and before the first delta of the forced step. A bounded turn therefore reads `sources, [tool events of the allowed batches], limit, token…, done` with `done.closeReason` `forced_bound`; a turn that does not reach its bound, or has none, sends exactly the events it sent before the bound existed plus `done.closeReason` null. `limit` is not a delta: it never counts towards the text of an aborted record. It is also sent when the turn ends without a delta of the forced step:

- **Empty forced answer:** `…, limit, done`; with a `forcedCloseFallback` template `…, limit, token (the fallback), [frame], done` (see [Forced-close fallback](#forced-close-fallback)).
- **Failed forced close** (the forced inference answers with another tool call): `…, limit, [token… of the text written before that call], error` with the message `The forced close did not produce an answer.`; no `tool_call` event is sent for the discarded call, because it fails before the tool node runs. The records are `[…, limit N, text N, error N+1]`, where the `text` record of the forced step (status `complete`, no close reason) exists only when the model wrote text before the call, and the `error` record has no content. With a `forcedCloseFallback` template the turn does not fail: `…, limit, [token… of the text written before that call], token (the fallback), [frame], done` with `status` `complete` and `closeReason` `forced_bound`.
- **Deadline** passed before the forced step: `limit`, then `error`; the records end with the `limit` and the `error` record.
- **Cancellation** at the forced step: `limit`, the tokens of the forced answer sent so far (none when the turn stops before the forced inference), then `done` with `status: "aborted"`; the `limit` record precedes the `aborted` record.

The detection relies on the public behaviour of neuron-ai's workflow executor, which yields every chunk of a node as the node produces it and runs the node's `after()` middleware only once the node generator has returned: `ToolCallBoundMiddleware::after()` reports the limit after the last `tool_result` of the batch and before the forced inference starts, and the runner sends `limit` at its next chunk or on its exit. neuron-ai is not patched for this; should an update change that order, the event would move (see [Limitations](#limitations-1)).

#### Terminal tools in the stream

When a terminal tool of [The turn](#the-turn) answers, the stream sends exactly one `terminal_tool` event: after the `tool_call`/`tool_result` events of the batch, and after `limit` when the step was forced, before `done`. A terminal turn therefore reads `sources, [tool events], terminal_tool, done`, or `…, limit, tool_call, tool_result, terminal_tool, done` when the terminal call was made in the forced step, with `done.closeReason` `terminal_tool`. `terminal_tool` is not terminal and not a delta; it is stored and replayed on resume like every event. Its `answer`, `structured` and `schemaErrors` equal the final record's `content`, `structured` and schema errors. A turn without terminal tools sends exactly the events it sent before.

#### User-turn limit in the stream

The streamed last allowed user turn of a conversation with `maxUserTurns` (see [User-turn limit](#user-turn-limit)) sends exactly one `turn_limit` event: after `sources` and before the first model event (`reasoning`, `token`, `tool_call`, `tool_result`), also when the forced answer is empty, when the turn fails and when it is cancelled; should both ever occur, `turn_limit` comes before `limit`. It is not terminal and not a delta, and it is stored and replayed by `GET /conversations/{id}/stream` like every event. The order on the wire is `sources, turn_limit, [model events], [terminal_tool], done` or `…, error`; `done.closeReason` is `user_turn_limit`, or `terminal_tool` when a terminal tool answered. A failed forced close reads `sources, turn_limit, [token…], error`, or with a `forcedCloseFallback` template `sources, turn_limit, [token…], token (the fallback), [frame], done` with `closeReason` `user_turn_limit`. Turns before the last one send exactly the events they sent before.

**Homonym.** `turn_limit` is both the name of this event and the close reason and 422 `code` of the anonymous turn quota (see [Limits](#limits)); the two are unrelated. The event announces the forced last turn of a conversation with `maxUserTurns`; that conversation is then closed with the reason and code `user_turn_limit`, which the event does not share. Clients that switch on the string `turn_limit` as a 422 code do not see the new code.

#### Rules in the stream

A streamed turn that a [pre-answer rule](#pre-answer-rules-and-templates) answered or refused runs no retrieval and no model call and sends exactly three events: `sources` with `{messageId, sources: []}`, `rule` (payload in the table above) and `done` with `status` `complete`, the version's `provider`, `connection` and `model`, `usage` null and `closeReason` `rule_answer` or `rule_refusal`. There is no `token`, `turn_limit` or `limit` event on such a turn, also not on a ruled last allowed turn (see [User-turn limit](#user-turn-limit)); the answer is only in `rule.message`. `rule` is not terminal and not a delta; it is stored and replayed by `GET /conversations/{id}/stream` like every event, so a resume after `sources` yields the same `rule` and `done`. The placeholder record becomes the rule record, so the sequence layout equals that of the synchronous turn.

The stream vocabulary (`Stream\StreamEvent`) has fourteen types: `sources`, `token`, `reasoning`, `tool_call`, `tool_result`, `done`, `error`, `limit`, `terminal_tool`, `turn_limit`, `rule`, `frame` (see [Frame in the stream](#frame-in-the-stream)), `gate` (see [Gates in the stream](#gates-in-the-stream)) and `follow_ups` (see [Follow-up suggestions](#follow-up-suggestions)). `StreamEvent::RESERVED`, the list of names announced but not sent yet, is empty: every type of the vocabulary is sent.

**Clients.** A client that rejects unknown event types must accept all fourteen; clients that ignore unknown types and unknown keys keep working, but show the answer of a terminal, ruled or refused turn only when they render `terminal_tool`, `rule` or `gate`, because such an answer is not streamed as `token` deltas, the frame only when they render `frame`, and suggestions only when they render `follow_ups` or read `followUps` from the record. `done.closeReason` takes the values `forced_bound`, `terminal_tool`, `user_turn_limit`, `rule_answer`, `rule_refusal`, `injection_refusal` and `unanswerable_refusal`, or null.

#### Frame in the stream

On an agent version with a `frame` template (see [Frame](#frame)) the streamed turn sends exactly one `frame` event `{messageId, prefix, suffix}` once the final record is stored: after the run's last event (after `limit` or `turn_limit` and after the `token` of a [forced-close fallback](#forced-close-fallback)) and before `done`. It is sent only when the final record is kind `text`, status `complete` and its close reason is not `terminal_tool`, i.e. for a normal, a forced and a fallback answer; never on a turn ending with `terminal_tool`, a schema answer, a ruled, failed or cancelled turn, and not at all without a `frame` template. The final record carries the same `{prefix, suffix}` in `frame`. A framed turn therefore reads `sources, [model events], frame, done`, or `…, limit, token…, frame, done` for a forced answer. `frame` is not terminal and not a delta; it is stored and replayed by `GET /conversations/{id}/stream` like every event.

The `token` deltas carry the unframed answer: the frame cannot be sent before the first token, because while the tokens stream it is not yet known which step is the final one (text before a tool call is streamed as `token` too), and the bundle does not buffer the answer to find out. Clients compose `prefix + streamed answer + suffix` when `frame` arrives.

#### Gates in the stream

The [answer gates](#answer-gates) of the streamed turn run inside the turn, after the placeholder record exists and before the retrieval, so `sources` stays the first event and the response starts without waiting for a gate call. A turn the prompt-injection guard refused runs no retrieval and no answer call and sends exactly three events: `sources` with `{messageId, sources: []}`, `gate` `{messageId, gate, language, message}` and `done` with `status` `complete`, the version's `provider` and `model`, `usage` with the guard's tokens under `usage.gates` and `closeReason` `injection_refusal`. There is no `token`, `turn_limit`, `limit` or `frame` event on such a turn; the answer is only in `gate.message`, and the gate's reason is never sent. `gate` is not terminal and not a delta; it is stored and replayed by `GET /conversations/{id}/stream` like every event. The placeholder record becomes the refusal record, so the sequence layout equals that of the synchronous turn. A turn whose gates passed or failed open streams exactly as without gates, with the gate tokens in `done.usage`.

The documented end of a complete text answer is `frame` (if any), then `follow_ups` (if any), then `done`. With `followUps` on, the follow-up call runs after the answer and before the records are final, so `token` deltas are not delayed, but `frame`, `follow_ups` and `done` wait for it. `follow_ups` `{messageId, followUps}` is sent at most once, only when the stored final record is `complete` and its `followUps` is a non-empty list; a failed follow-up call or an empty result sends no `follow_ups`, and the record keeps `[]`. It is not terminal and not a delta, and it is stored and replayed by `GET /conversations/{id}/stream` like every event (see [Follow-up suggestions](#follow-up-suggestions)). A turn the answerability check refused runs the retrieval but no answer call and sends exactly three events as well: `sources` with the retrieved chunks, `gate` with `gate` `answerability` and `done` with `closeReason` `unanswerable_refusal` and the check's tokens under `usage.gates.answerability`.

### Forced tool choice

neuron-ai has no `tool_choice` (3.17, and still 4.1.11): a request either offers tools, and the model decides whether to call one, or offers none. Forcing the final answer of a bounded turn, or the call of a terminal tool in its forced step, needs that request parameter, so the bundle adds it as an optional provider capability in `Provider\`. It is built on top of upstream without patching `vendor/` and is shaped as a proposal for an upstream pull request: one interface, one value object and one overridden method per provider.

- **`Provider\ToolChoice`** is a value object with four choices that mirror the common denominator of the major chat APIs (Anthropic `tool_choice`, OpenAI `tool_choice`, Gemini `function_calling_config`): `auto()` (the model decides, the API default), `any()` (the model must call one of the offered tools), `none()` (the model must not call a tool; the tool definitions stay in the request, so earlier tool calls and results in the history remain valid) and `tool($name)` (the model must call the named tool; an empty name throws). Compare with `equals()`, not `===`; the string form is `auto`, `any`, `none` or `tool:<name>`.
- **`Provider\ToolChoiceAwareProviderInterface`** has three methods: `supportsToolChoice(ToolChoice): bool` (whether this instance, with its model and parameters, can send the choice without the API rejecting the request), `setToolChoice(?ToolChoice): static` and `getToolChoice(): ?ToolChoice`. It is a separate interface rather than new methods on `AIProviderInterface`, so existing providers stay valid and callers detect the capability with `instanceof`. `setToolChoice()` mutates the instance and returns it, like `setTools()` and `systemPrompt()`, instead of returning a `with*` clone, because the agent's inference nodes resolve the provider once and keep that instance for the whole run: a clone would never reach the next request. The choice applies to every following request until it is cleared with null, and the caller that sets it clears it (the turn runner does so when the run ends).
- **`Provider\AnthropicWithToolChoice`** extends neuron-ai's `Anthropic` and overrides only the protected `requestBody()` method, the seam that both the chat request (`HandleChat::chat()`) and the stream request (`HandleStream::stream()`) call, so both carry the same `tool_choice` without touching either trait. `auto`, `any` and `none` become `{"type": "<type>"}`, a forced tool becomes `{"type": "tool", "name": "<name>"}`. The field is sent only when the body offers a non-empty `tools` list, because the Messages API rejects `tool_choice` without tools; with no choice set the body is exactly the parent's. `ProviderFactory` returns this class for `anthropic`.
- **Support per model.** `auto` and `none` are accepted on every model. The forcing choices `any` and `tool` are answered with HTTP 400 on some model lines, so `supportsToolChoice()` declines them when the model name matches one of the PCRE patterns of `AnthropicWithToolChoice::UNSUPPORTED_FORCING_MODELS` (`/^claude-fable-/`, `/^claude-opus-5(-|$)/`, `/^claude-sonnet-5(-|$)/`); `claude-haiku-4-5-20251001` accepts them. The list is the maintenance point: add a pattern when a new model line declines forcing, or pass an own list as the constructor argument `unsupportedForcingModels`. A caller can thus choose its fallback before the request, instead of after a 400.
- **Extended thinking.** The Messages API also rejects `any` and `tool` while extended thinking is enabled, so they are declined whenever the provider parameter `thinking` is set to anything but `{"type": "disabled"}`. The bundle enables no thinking today; the check is a guard.
- **Providers without the capability.** Ollama's chat API has no `tool_choice`, so the Ollama provider stays the upstream class. A caller checks `instanceof ToolChoiceAwareProviderInterface` and `supportsToolChoice()` and otherwise uses its own strategy; the tool-call bound withdraws the tools and appends a closing instruction (see [The turn](#the-turn)).

The turn uses `none` for the forced step of a bounded turn and for the last allowed user turn, and `tool` on the first terminal name when the request names terminal tools and the provider supports it (see [The turn](#the-turn) and [User-turn limit](#user-turn-limit)); `any` is not used by any caller yet.

- **Unknown tool names.** A model sometimes calls a tool that was not offered (seen: `json` when it tried to deliver a JSON answer, a name consisting of a line break). neuron-ai's Anthropic provider throws on such a call and the turn failed; `AnthropicWithToolChoice::newToolCall()` keeps the call instead, the tool node cannot resolve it and hands the error to the tool error handler, so the model gets a tool error and answers on.

### Structured outputs

Two more Anthropic request features, also on `Provider\AnthropicWithToolChoice`:

- **Prompt caching.** `ProviderFactory` sends the top-level `cache_control` `{"type": "ephemeral"}` with every Anthropic request (automatic caching). Every round of a tool loop re-sends the same instructions, tools and history, which are then read from the cache; `usage.cached_input_tokens` of the records shows the share. The retrieved context is part of the instructions, so the cache helps within a turn, not across questions.
- **Response format.** `Provider\ResponseFormatAwareProviderInterface::setResponseFormat(?array $schema): bool` is an optional capability like the tool choice. The turn runner sets the request's `responseSchema` on a provider that has it; `AnthropicWithToolChoice` then sends `output_config.format` `{"type": "json_schema", "schema": …}` (merged with an `effort` of the model options), so the API constrains the final text to the schema. It declines (returns false, the prompt instruction alone applies) for a model that matches none of `RESPONSE_FORMAT_MODELS` and for a schema that `Provider\AnthropicJsonSchema` cannot make compatible: every object needs `additionalProperties: false`. Keywords the API does not support (`minLength`, `maxLength`, `pattern`, `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf`, `minProperties`, `maxProperties`, `minItems`, `maxItems`, `uniqueItems`) and `x-*` keys are left out of the API copy; the answer is still validated against the full schema. A schema that needs free keys (for example a form whose field names are keys) has to describe them as a list to qualify.

Tests assert the choice through the fake provider: `Testing\RecordingFakeAIProvider` implements the interface and records every call as a `Testing\ToolChoiceRequestRecord` carrying the `toolChoice` set at call time; its `declineToolChoice()` makes it decline every choice, its `declineForcingChoices()` makes it decline `any` and `tool` while accepting `auto` and `none`, and `FakeProviderFactory` declines for `ollama` (see [Test seams](#test-seams)). The request bodies of `AnthropicWithToolChoice` itself are tested against a capturing HTTP client, for chat and stream.

### Permissions

The bundle names no role. Every right it checks is a permission of the form `neuronai:<resource>:<action>` (`Security\Permissions`), registered under the container tag `za7_core.permission` (`config/services/security.php`); the application maps them to roles in `za7_core.permissions` (see below), and a permission without a map entry is granted to nobody. The voter `Security\ConversationVoter` decides only the three owner attributes `neuronai:conversation:read_own`, `neuronai:conversation:delete_own` and `neuronai:conversation:turn_own`: granted when the conversation's `ownerSubject` equals the user identifier of the token, denied otherwise, also without a user, for a `null` subject and for an empty identifier. They are neither registered nor mapped. The linking "owner or holder of the mapped permission" is the expression constants of `Security\ConversationSecurity` (`READ`, `READ_PARENT`, `DELETE`, `TURN`), which API Platform evaluates as the `security` of the operations and the imperative call sites evaluate with `isGranted(new Expression(...), $conversation)`; there is no `turn_any`, so nobody runs a turn in a foreign conversation. The mapped permissions are `neuronai:conversation:read_any`, `neuronai:conversation:delete_any`, `neuronai:conversation:read_payload` and `neuronai:conversation:read_eval` (read access to every evaluation conversation, `origin` `eval`, and every replay conversation, `origin` `replay`; see [Evaluation conversations](#evaluation-conversations)). The principal of a capability token (`Security\AnonymousConversationUser`, identifier `anon:<id>`, no role) owns exactly its anonymous conversation, so the owner rules grant it read, delete and turn there and nothing anywhere else; as it carries no role, none of the mapped permissions can reach it.

All permissions of the bundle (the application maps each of them to roles under `za7_core.permissions`):

| Permission | Checks |
|---|---|
| `neuronai:conversation:list`, `neuronai:conversation:create` | list and create conversations, list messages |
| `neuronai:conversation:read_any`, `neuronai:conversation:delete_any` | read or delete the conversations of every owner |
| `neuronai:conversation:read_payload` | the `providerPayload` of a message |
| `neuronai:conversation:read_eval` | read evaluation and replay conversations of every owner |
| `neuronai:artifact:write` | the artifact write tools of an agent conversation |
| `neuronai:collection:read`, `write`, `lock`, `unlock` | collections: reads; create, change, delete and reindex; lock; unlock |
| `neuronai:agent:read`, `write`, `chat`, `chat_candidate` | agents and versions: reads; create, change, publish and roll back; chat on the published version; chat on the candidate |
| `neuronai:mcp_connection:use_restricted` | name a restricted MCP connection directly |
| `neuronai:eval:read`, `write`, `rate`, `compare` | evaluation: reads; maintain questions, start runs and replays; rate results; compare runs |
| `neuronai:feedback:read`, `neuronai:feedback:submit_reviewer`, `neuronai:feedback:review` | read feedback; submit feedback as `reviewer`; set the review status and note |
| `neuronai:mcp_tool:read`, `write`; `neuronai:provider_model:read`, `write`; `neuronai:knowledge:read` | the catalogues and the knowledge tools |

The map is plain application configuration, one list of roles per permission:

```yaml
# config/services.yaml
parameters:
    za7_core.permissions:
        'neuronai:conversation:read_any': [<role>]
        'neuronai:agent:read': [<role>, <role>]
```

The bundle ships no voter for the mapped permissions: the host application's permission voter reads the map (and may check its keys against the registered permissions, `za7_core.permissions.registered`). Without such a voter the tags are inert and every mapped permission is denied.

`providerPayload` holds the provider's serialization of a record (reasoning signatures, tool call ids, metadata), which the replay needs. It is serialized only on `GET /messages/{id}` and only for holders of `neuronai:conversation:read_payload`; never in lists and never in the turn response.

### Limitations

- **Advisory lock and connection poolers.** The lock is `pg_try_advisory_lock()` on the default DBAL connection, released in `finally`. It needs a session per worker and does not work behind a pooler in transaction mode (PgBouncer `pool_mode = transaction`).
- **Ollama tool calls are not replayed as such.** Neuron AI's Ollama mapper reads `tool_calls` from the serialized tool-call message, which never provides that key, so an assistant tool-call message goes to Ollama without `tool_calls`, in the live run and in the replay alike; the tool results follow as `tool` messages. The replay reproduces exactly what the live run sends; the gap is the vendor's and not patched here.
- **Only complete turns are replayed.** A turn with any record that is not `complete` (including `error` and `aborted`, i.e. also a cancelled streamed turn) is left out of the history, so the user/assistant alternation the providers require holds.
- **Deleting a conversation during a turn.** The turn's final flush fails on the foreign key and the request answers 500; the lock is released.
- **The `limit` event depends on the executor's order.** The streamed turn finds the reached bound through the tool node's `after()` middleware, which neuron-ai's workflow executor runs after the node's last chunk and before the next node starts (see [Tool-call bound in the stream](#tool-call-bound-in-the-stream)). This is observed public behaviour, not a documented guarantee; a neuron-ai update that changes it would move the event.
- **Forcing is proven against request bodies only.** The turn uses `none` and `tool` (at the bound and for the last allowed user turn); both are tested against the request bodies of `AnthropicWithToolChoice`, not against the live API. Runs against Anthropic and Ollama are manual on a developer machine and not part of CI.
- **The user-turn limit is fixed at creation.** `maxUserTurns` cannot be changed afterwards (no PATCH); `/chat` and `POST /agents/{id}/chat` have no user-turn limit.
- **The last turn can be lost.** An `error` or `aborted` last allowed turn still counts and closes the conversation; the user gets no answer and cannot retry. A last turn with terminal tools on a provider that supports `tool:<name>` is forced into the first terminal tool, whatever the user asked.
- **No forced terminal tool on some Anthropic models.** On `claude-fable-*`, `claude-opus-5*` and `claude-sonnet-5*` the forced step cannot target the terminal tool (the API answers `tool` with HTTP 400, so `supportsToolChoice()` declines it); it falls back to `none`, and the answer is a forced text.
- **An MCP error result is an answer.** A terminal tool whose MCP result has `isError: true` is not a tool error for neuron-ai, so its content becomes the (text) answer of the turn.
- **Upstream internals: the history trimmer.** neuron-ai's `HistoryTrimmer::validateAlternation()` (protected, unchanged from 3.17.0 to 4.1.11) rejects a user message that directly follows a `ToolResultMessage` ("expected role assistant, got user"), which is exactly what the faithful replay of a terminal turn produces. `Conversation\TurnAgent::historyTrimmer()` therefore returns an anonymous `HistoryTrimmer` subclass that splits the messages at each "tool results, then user message" boundary and checks every part with upstream's rule; the agent's `resources()` override installs it on the message store, and `TurnRunner::history()` fills the store through it, so a broken alternation still fails before the run. Histories without that sequence are checked as usual. Over the context window, neuron-ai 4.x cuts the history to half the window. It is the second use of protected upstream API next to `TerminalToolAgent::nodes()`/`TerminalToolNode` (see [The turn](#the-turn)); a neuron-ai update that changes either costs one adapter each.
- **Replay of a terminal turn on Anthropic.** The faithful replay of a terminal turn ends with the tool result, which the Anthropic mapper sends as a user message, so the next user message follows as a second consecutive user message. The Messages API documents that consecutive turns of the same role are merged; this is proven at mapper and request-body level, not verified live. The in-memory history accepts the sequence only through the trimmer override above.
- **Replay of a terminal turn on Ollama.** Ollama replays the tool results as `role: tool` messages without call ids (neuron-ai's Ollama mapper).
- **Terminal markers in the payload.** `providerPayload` (`part: "terminal"`, the `tool` name and call id) is invisible without `neuronai:conversation:read_payload`; the readable markers of a terminal turn are the `closeReason` of the final record and its `tool_call` record.
- **The Ollama fallback is best effort.** Without tools and with the closing instruction the model can only answer with text, never with a call of a terminal tool; how well a local model follows the instruction is not guaranteed.

### Anonymous access

Visitors without an account can start a conversation through a public endpoint and continue it with a capability token. The operator decides what such a conversation may use and how much; anonymous conversations are closed after a retention period and deleted after a deletion delay.

#### Creation and token

`POST /conversations/anonymous` (operation `conversation_anonymous`) takes no credentials and the body `{}` only; any configuration in the body (`provider`, `collections`, `title`, …) answers 422 (validated against `Entity/Conversation/anonymous.input.json`). The conversation is created with the released values of the access policy and `anonymous` true, owned by `anon:<id>`. The response (201) is `{"conversation": {…}, "token": "anon_…"}`.

The token is `anon_` plus 32 random bytes in base64url and is shown only in this response; only its SHA-256 hash is stored (`tokenHash`, never serialized). The client sends it as `Authorization: Bearer anon_…`, never in a URL. The bearer prefix `anon_` is reserved for capability tokens (JWTs start with `eyJ`).

With the token the holder may read the conversation (`GET /conversations/{id}`), its records (`GET /conversations/{id}/messages`, `GET /messages/{id}`), run turns (`POST /conversations/{id}/messages`, streamed with `POST /conversations/{id}/stream`, resumed with `GET /conversations/{id}/stream`, cancelled with `POST /conversations/{id}/cancel`) and delete it (`DELETE /conversations/{id}`). Every list (`GET /conversations`, `GET /messages`), the stateless chat and search, the admin and refs resources answer 403; another conversation answers 403 on the item operations and 404 on the message sub-resource, as for users. An unknown, malformed or deleted token answers 401 without echoing the value.

#### Access policy

The release values and limits are runtime values of the application: it implements `Anonymous\AnonymousAccessPolicyInterface` (`current(): AnonymousAccessPolicy`, called once per request or purge run, never memoized, so a changed value applies to the next request without a deployment) and aliases the interface to its implementation. Without the alias the creation answers 503 and the purge only logs a warning. `AnonymousAccessPolicy` holds:

| Value | Meaning |
|---|---|
| `collections` | references (UUID, IRI or name) every anonymous conversation is created with |
| `mcpConnections` | must be empty: anonymous conversations never get MCP tools, a non-empty list (also with `agent`) makes the creation answer 422 `misconfigured (mcp_connections)` and logs at error level |
| `model` | reference (IRI `/api/refs/provider_models/<uuid>`, full or partial UUID) of the chat model of anonymous conversations without an agent, an entry of the [model catalogue](#model-catalogue) that names its provider connection; null by default and required without an agent, there is no default; resolved by `Conversation\ProviderSelection` and checked live at every creation |
| `agent` | reference (IRI `/api/agents/<uuid>`, full UUID or slug) of an agent, or null; when set, anonymous conversations are agent conversations of its current published version, and `collections` and `model` are ignored; a version with `mcpTools` makes the creation answer 422 `misconfigured (agent)` |
| `retentionDays` (>= 1) | days after `createdAt` until the conversation is closed |
| `creationsPerAddressAndHour` (>= 0) | creations per client address within a sliding hour; 0 disables anonymous creation |
| `maxTurnsPerConversation`, `maxTokensPerConversation` (>= 1) | limits that close the conversation |
| `deletionDelayDays` (>= 0) | days after `closedAt` until the conversation and its messages are deleted |

A release without an agent that lacks `model`, and a released reference that does not resolve (a deleted collection; a model reference that is unknown, unavailable or of an inactive connection, a model the connection does not offer or a provider that cannot be reached for the check: `misconfigured (model)`), is an operator error: the creation answers 503 and logs at error level. A host application typically implements the policy from runtime settings of its own.

**Releasing an agent.** With `agent` the creation resolves the agent like [Creation with an agent](#creation-with-an-agent), maps its current published version with the default language and no variables, pins the version and copies its values into the columns (provider connection and its type, model, collections, retrieval depth, the connections of its tools, the default language, empty `variables`, `maxUserTurns` from the version and the settings default). Every turn then runs on the pinned version as described in [Turns of an agent conversation](#turns-of-an-agent-conversation): the version's instructions, collections and tools, terminal tools included, reach the public endpoint. The body stays `{}`, so a visitor can neither choose the language nor give start parameters; a version whose instructions need a variable can therefore not be released (the mapping fails). Points to keep in mind:

- **No MCP tools.** A released agent must not have `mcpTools`: its version makes the creation answer 422 (`misconfigured (agent)`, logged at error level) before anything is stored, and the turn of an anonymous conversation attaches no MCP tools as a second line (`Conversation\TurnPersistence::offersMcpTools()`, logged at warning level), also for conversations created earlier. A settings schema of the host should not offer MCP connections for anonymous conversations at all.
- **Exposure.** Everybody with the link causes prompt cost (the [answer gates](#answer-gates) of the version add their provider calls to every turn) and reaches the version's collections; the turn, token, retention and creation limits of the policy are the only protection. Settings validation is a JSON schema without database access, so it does not check what the agent exposes.
- **Misconfiguration answers 503.** An unknown reference (a typo), a disabled agent, an agent without a published version and a version that refers to something deleted (`misconfigured (agent)`, logged at error level) make anonymous creation answer 503 until the setting or the agent is fixed. Conversations created earlier keep their pinned version; a stale reference shows there as 422 at the next turn.
- **Turns.** `responseSchema` and `terminalTools` in an anonymous turn answer 422 like in every agent conversation; this check runs before the anonymous limits, so the detail is the agent text, not `Anonymous conversations do not accept a responseSchema.`

#### Limits

The turn of an anonymous conversation is checked before any provider call; a refused turn answers 422 with a stable `code`:

| `code` | When |
|---|---|
| `conversation_closed` | the conversation is closed (or archived) without one of the reasons below |
| `turn_limit` | `turnCount` reached `maxTurnsPerConversation` |
| `token_limit` | `tokensUsed` reached `maxTokensPerConversation` |
| `retention_expired` | `createdAt + retentionDays` has passed |

Reaching a limit closes the conversation (`closeReason` `turn_limit`, `token_limit` or `retention`); it stays readable and deletable. `turnCount` and `tokensUsed` (input plus output tokens of every provider response of the turn, the calls of the version's [answer gates](#answer-gates) included; a turn a gate refused counts as a turn with its gate tokens) are counted in the same transaction as the turn's records; a streamed turn is counted when its final record is stored, also when it was cancelled or failed. The budget is checked before a turn, so the turn that crosses it completes: the budget is soft by one turn. A `responseSchema` in an anonymous turn answers 422, because it would become system-prompt text on a public endpoint. Anonymous conversations have no `maxUserTurns` over the API, only from a released agent; should the entity carry one, the smaller of the two turn limits closes the conversation, with `user_turn_limit` on a tie (see [User-turn limit](#user-turn-limit)).

#### Creation rate limit

Creations are counted per client address in a sliding window of one hour (`creationsPerAddressAndHour`). Above the limit the creation answers 429 with `Retry-After`; a limit of 0 answers 403. The limiter key is the keyed address hash, not the address. The counters live in the cache pool `anonymous.rate_limiter_cache_pool` (default `cache.rate_limiter`, which the app defines), which must be shared by all php containers (Redis); with a `lock.factory` service (symfony/lock on a shared store) the count is atomic under concurrent requests.

#### Address hash

The client address is hashed with HMAC-SHA256 under `anonymous.address_hash_key` (independent of `APP_SECRET`; an empty key makes the creation fail instead of hashing with a guessable key). The hash keys the rate limiter and is stored as `addressHash`; it is serialized only in the group `conversation:admin`, which `GET /conversations` and `GET /conversations/{id}` add for holders of `neuronai:conversation:read_any`, and it is deleted with the conversation. The address itself is never stored.

#### Retention and purge

`Anonymous\AnonymousConversationPurger` runs under the system actor `system:neuron:purge`. One run reads the policy and the time once, then:

1. closes every anonymous conversation that is not closed and was created at least `retentionDays` ago (`closeReason` `retention`, `closedAt` = the run's time), an audited `update`;
2. deletes every closed anonymous conversation whose `closedAt` lies at least `deletionDelayDays` back, through the entity manager (an audited `remove`; the messages go with the database cascade). A closed conversation without `closedAt` is never deleted.

Each conversation is handled under its turn lock: one whose advisory lock another session holds (a running turn) is skipped, counted and taken by a later run; after the lock is taken the row is re-read with its conditions. Candidates are read in pages of 100 ids and every conversation is flushed on its own. A second run directly after the first finds nothing to do. The turn path closes an expired conversation itself, so the retention does not depend on the purge cadence.

The purge runs on the schedule `neuron` (`Anonymous\NeuronScheduleProvider`, tag `scheduler.schedule_provider` with `name: neuron`): a `PurgeAnonymousConversationsMessage` every `anonymous.purge_interval` (default 15 minutes), stateful on `cache.app` (a restarted worker continues from the last run, only the last missed run is processed) and, with a `lock.factory`, locked with `neuron-schedule`, so of several workers only one generates the messages. The framework creates the transport `scheduler_neuron` for it; **a worker of the application must consume it**, e.g. `messenger:consume scheduler_neuron maintenance async`. The handler `PurgeAnonymousConversationsHandler` is tagged `messenger.message_handler`. Cache pool and lock store must be shared by all workers (Redis, PostgreSQL), a per-container `flock` store coordinates nothing.

`bin/console neuron:anonymous:purge` runs the purge once and prints the report (`closed N, deleted N, skipped N`); `--async` dispatches the message on the bus instead, to whatever transport the app routes it to.

#### Audit actors

Audit entries (`dmstr_log_entries.username`) never contain a token. The public creation is logged under `anon:<id>` (through `Anonymous\SystemActor`), actions of the token holder carry its principal `anon:<id>`, and closes and deletions of the purge carry `system:neuron:purge`. Closes in the turn path carry the actor of the turn.

#### Application wiring

- Firewall: the api firewall lists `Dmstr\NeuronAiBundle\Security\CapabilityTokenAuthenticator` under `custom_authenticators` next to `jwt: ~`, with `entry_point: jwt` (two authenticators need an explicit entry point).
- Access control: `{ path: ^/api/conversations/anonymous$, methods: [POST], roles: PUBLIC_ACCESS }` before the conversation row, and `^/api/(conversations|messages)` requires `IS_AUTHENTICATED_FULLY` like every other path; the operations check their own permissions, which the token principal does not hold.
- JWT extractor: with LexikJWTAuthenticationBundle the bundle decorates `lexik_jwt_authentication.extractor.chain_extractor` (`Security\JwtTokenExtractorDecorator`), so Lexik treats `anon_` values as "no token" instead of answering them with its own 401. Lexik is optional; without it the decoration is dropped.
- Policy alias, `anonymous.address_hash_key` from an env var, a shared cache pool for the counters, `cache.app` and `lock.factory` on shared stores, and a worker consuming `scheduler_neuron`.

#### Operator notes

- **Trusted proxies.** The client address is the request's `getClientIp()`, so the reverse proxy must be trusted (`SYMFONY_TRUSTED_PROXIES` and `SYMFONY_TRUSTED_HEADERS`, both required); otherwise all visitors share the proxy's bucket. Behind two proxies (e.g. Traefik in front of Caddy) the inner one must trust the outer one as well, or it overwrites `X-Forwarded-For` (Caddy: the `trusted_proxies` server option).
- **Logs.** Proxy access logs must redact the `Authorization` header (Caddy does unless `log_credentials` is on).
- **Permissions.** The token principal carries no role, so no mapped permission reaches it; keep it that way (never give it a role).
- **Shared storage.** Rate limiter counters, the schedule state and the locks need storage shared by all php and worker containers (Redis); a missing Redis makes creation fail rather than pass unlimited.

#### Limitations

- The token budget is soft by one turn (checked before a turn, counted after it).
- Only input and output tokens are budgeted: cached input tokens (reported by Anthropic outside `input_tokens`) and reasoning tokens a provider reports separately are not.
- Format-suffixed path variants of the public route (`/conversations/anonymous.jsonld`) are not public: the access-control row matches the plain path only, so they answer 401.

## Agents and versions

An agent is a stable record with an editable candidate. Publishing validates the candidate completely and freezes it as the next, immutable version of the agent; rollback points the agent back to an older version. A version carries every field the turn runtime needs, and every field has an effect (see [Where the version fields act](#where-the-version-fields-act)). The current version answers the stateless [agent chat](#agent-chat); a conversation created with an agent pins the current version and runs every turn on it (see [Creation with an agent](#creation-with-an-agent)), and the anonymous access policy can release an agent the same way (see [Access policy](#access-policy)). To try or evaluate a candidate before publishing it, the candidate is frozen as a preview version that never becomes current (see [Preview versions](#preview-versions)).

### Agent records

`Entity\Agent` (table `neuron_agent`, audited with Gedmo loggable on create and on changes of `currentVersionNumber` and `enabled`, so publish and rollback are `update` entries; candidate edits are not logged, otherwise every candidate state would land in the log): `id` (UUID), `name` (at most 190 characters, not blank), `slug` (at most 190 characters, pattern `^[a-z0-9][a-z0-9-]*$` like a collection name, unique `uniq_neuron_agent_slug`), `enabled` (default true), `currentVersion` (the published version in use, null before the first publish, read-only), `currentVersionNumber` (read-only number of that version, set together with it; audited instead of the association, whose UUID identifier cannot be stored in the log), `candidate` (JSON, see [Candidate schema](#candidate-schema)), `createdAt`, `updatedAt`. A new agent starts with the schema defaults, overridden by `Agent::NEW_AGENT_DEFAULTS` (`hybrid` true, `hybridWeight` 0.3, `modelOptions` `{"maxTokens": 2048}`): without `candidate` it gets all of them, a `POST` with a candidate gets those top-level keys it does not send (`State\AgentWriteProcessor`), and the admin form shows them as the field defaults. The schema defaults below stay unchanged, because a stored version without a key takes it from there. There is no delete; an agent is switched off with `enabled: false`.

`Entity\AgentVersion` (table `neuron_agent_version`): `id`, `agent` (foreign key `agent_id`, no cascade), `versionNumber` (1, 2, … per agent for published versions, 0 for every preview; the partial unique index `uniq_neuron_agent_version_agent_number` on `(agent_id, version_number)` covers the published versions only), `configuration` (JSON of the same shape as the candidate, with resolved references), `publishedAt` (for a preview its creation time), `publishedBy` (the user identifier of the token, for a JWT its subject; for a preview the user who created it), `kind` (`published` or `preview`, `Agent\AgentVersionKind`) and `candidateHash` (null for a published version, the sha256 hex of a preview's configuration, unique per agent among the previews through the partial index `uniq_neuron_agent_version_agent_hash`). A version is written once by publish or by the preview factory and never changed: the class has a constructor and getters only, the constructor refuses a number or hash that does not fit the kind, the API has no write operation, and the foreign key has no cascade. Only the purge deletes previews (see [Preview versions](#preview-versions)).

`Conversation::$agentVersion` is a relation to `AgentVersion` (column `agent_version`, nullable, foreign key to `neuron_agent_version`), serialized as IRI and read-only; it is set at creation from the write-only `agent` to the agent's current published version and not changed by later publishes or rollbacks (see [Creation with an agent](#creation-with-an-agent)).

### Agent API

| Route | Role | Behaviour |
|---|---|---|
| `GET /api/agents` | `neuronai:agent:read` | list; filters `slug` and `currentVersion` (exact) and `enabled` (boolean) |
| `POST /api/agents` | `neuronai:agent:write` | create with `name`, `slug`, optional `enabled` and `candidate`; 201; 422 for a duplicate slug, a slug outside the pattern or a candidate that violates the schema |
| `GET /api/agents/{id}` | `neuronai:agent:read` | one agent with `currentVersion` as IRI |
| `PATCH /api/agents/{id}` | `neuronai:agent:write` | change `name`, `slug`, `enabled`, `candidate`; the merge patch replaces `candidate` as a whole, so clients send the complete candidate |
| `POST /api/agents/{id}/publish` | `neuronai:agent:write` | operation `agent_publish`, no body; validates the candidate, answers 201 with the new `AgentVersion` |
| `POST /api/agents/{id}/rollback` | `neuronai:agent:write` | operation `agent_rollback`, body `{"version": "…"}`; answers 200 with the agent |
| `POST /api/agents/{id}/chat` | `neuronai:agent:chat`; `neuronai:agent:chat_candidate` with `target: candidate` | operation `agent_chat`, body `{"target": "published", "messages": […], "variables": {…}, "language": "…", "maxToolCalls": n}`; one stateless turn on the current version, or with `"target": "candidate"` on a preview of the candidate, answers 200 with the chat result plus `agentVersion`, `agentVersionKind`, `language`, `rule`, `frame` and `gate` (see [Agent chat](#agent-chat)) |
| `GET /api/v1/models`, `POST /api/v1/chat/completions` | `neuronai:agent:chat` | OpenAI-compatible twin of `agent_chat` on the published version, plain JSON (see [OpenAI-compatible chat API](#openai-compatible-chat-api)) |
| `POST /api/conversations` with `agent` | `neuronai:conversation:create` | creates an agent conversation: pins the current version, the body may add only `title`, `maxUserTurns` (lower only), `language` and `variables`, and every turn runs on the pinned version (see [Creation with an agent](#creation-with-an-agent)) |
| `GET /api/agent_versions` | `neuronai:agent:read` | list of the published versions; filters `agent` and `kind` (exact, `?kind=preview` lists the previews instead), order `versionNumber` (ascending by default) |
| `GET /api/agent_versions/{id}` | `neuronai:agent:read` | one version, published or preview, with its `kind` |

`PUT` and `DELETE` on `/api/agents/{id}` answer 405; `/api/agent_versions` has no `POST`, `PUT`, `PATCH` or `DELETE` (404 or 405, the row stays unchanged). Anonymous requests answer 401 on all of these routes; the capability token of an anonymous conversation holds none of these permissions and is refused with 403. The permissions are described in [Permissions](#permissions). Unknown agents and versions answer 404; a partial UUID in the path answers 404 as well (the same platform limitation as for conversations).

### Candidate schema

`src/Entity/Agent/candidate.json` (JSON Schema 2020-12, `additionalProperties: false`) is checked on `POST` and `PATCH` by the constraint `Validator\ValidAgentCandidate`: structure and value ranges only, violations at `candidate/<field>` with 422. References are checked at publish, so a candidate may point to collections, connections or tools that do not exist yet. A string in `maxToolCalls` answers 422 here (schema validation), unlike the 400 of `/api/chat`.

The same schema describes the field in the OpenAPI document: `Entity\Agent` and `Entity\AgentVersion` implement `JsonSchemaProviderInterface` of `dmstr/openapi-json-schema-bundle`, and `#[JsonSchema]` on `candidate` and `configuration` makes its `JsonFieldSchemaDecorator` replace the untyped map API Platform derives from a PHP `array` with the properties of `candidate.json`, each with type, range, default, title and description. Generated forms (the admin UI) therefore show every key with its own input instead of a text field per key. The schema description states that a saved candidate takes effect only when it is published. The decorator copies `type`, `description`, `properties`, `required` and `additionalProperties` of the schema and drops `$schema` and the top-level `title`; OpenAPI 3.1 uses the 2020-12 dialect anyway, and `candidate.json` uses no keyword that draft-07 lacks (type lists, `if`/`then`, `const`, `propertyNames`), so the draft-07 schemas of `configJson` and this one render the same way. Limitation: the response schema of `POST /agents/{id}/publish` (component `Agent.AgentVersion.jsonld-agent_version.read`) still shows `configuration` as an untyped map, because the decorator looks for the attribute on the operation's resource class (`Agent`), not on the output class; `GET /agent_versions/{id}` shows the full schema.

| Field | Type, default | Meaning |
|---|---|---|
| `instructions` | object language tag → text, `{}` | system instructions per language |
| `model` | string or null, null | chat model: IRI (`/api/refs/provider_models/<uuid>`) or full or partial UUID of an entry of the [model catalogue](#model-catalogue), which names the provider connection; required to publish; `x-collection` is the picker `ProviderModel::modelCollectionUrl('completion')` (added by `Agent::candidateSchema()`) |
| `collections` | list of strings, `[]` | knowledge collections (UUID, IRI or name); the items carry the picker keywords `format: iri-reference`, `x-collection: /api/admin/collections`, `x-label-property: name` and `x-search-property: search` (added by `Agent::candidateSchema()`) |
| `retrievalDepth` | integer ≥ 1 or null, 10 | retrieved chunks (the pool before the context cap); null is `store.top_k` |
| `hybrid` | boolean, false (new agents: true) | keyword plus vector search, fused by weighted RRF (see [Hybrid search](#hybrid-search)); false is vector search only |
| `hybridWeight` | number 0..1, 0.5 (new agents: 0.3) | keyword share of the fusion; the vector lists weigh `1 − hybridWeight`; ignored without `hybrid` |
| `contextMaxChunks` | integer ≥ 1, 10 | retrieved chunks sent to the model at most, in ranking order (see [Context shaping](#context-shaping)) |
| `contextMaxChars` | integer ≥ 1, 15000 | characters of the retrieved chunks sent at most, applied before the neighbour expansion; a first chunk longer than this is shortened to it |
| `contextExpansionMaxChars` | integer ≥ 0, 6000 | separate character budget of the neighbour passages of hits from uploaded or crawled documents; 0 is no expansion |
| `contextInstructions` | object language tag → text, `{}` | instruction text in front of the retrieved context, used literally; replaces `turn.context_instruction` |
| `mcpTools` | list of `{connection, name, terminal}`, `[]` | catalogued tools: `connection` (IRI, full or partial UUID or name of an `mcp` connection), `name` (tool name in its catalogue), `terminal` (boolean, default false) |
| `responseSchema` | object or null, null | JSON schema of a structured answer |
| `startSchema` | object or null, null | JSON schema (`type: object`) of the start input of a conversation |
| `modelOptions` | object, `{}` (new agents: `{"maxTokens": 2048}`) | options of every provider call of a turn, per provider and model (see [Model options](#model-options)) |
| `maxToolCalls` | integer ≥ 1 or null, null | tool-call bound of a turn |
| `maxUserTurns` | integer ≥ 1 or null, null | user turns a conversation accepts |
| `languages` | list of strings, at least one, `["de"]` | languages the agent answers in |
| `defaultLanguage` | string, `"de"` | language used when a conversation names none |
| `rules` | list of `{name, match, outcome, template}`, `[]` | pre-answer rules, evaluated in order on the user input before retrieval and any model call (see [Pre-answer rules and templates](#pre-answer-rules-and-templates)) |
| `templates` | object template name → (language tag → text), `{}` | texts of the rules' answers and refusals, and the reserved slots `frame`, `limitReason`, `turnLimitReason`, `forcedCloseFallback`, `injectionRefusal` and `unanswerableRefusal` (see [Templates](#templates), [Output templates](#output-templates) and [Answer gates](#answer-gates)) |
| `injectionGuard` | boolean, false | prompt-injection guard on the user message, after the pre-answer rules and before retrieval (see [Answer gates](#answer-gates)); publishing requires an `injectionRefusal` text for `defaultLanguage` |
| `answerabilityCheck` | boolean, false | answerability check between retrieval and answer; publishing requires an `unanswerableRefusal` text for `defaultLanguage` |
| `answerabilityVotes` | 1, 3, 5 or 7, 1 | votes of the answerability check; refuses only on a strict majority of unanswerable votes, a failed vote counts as answerable; ignored without `answerabilityCheck` |
| `followUps` | boolean, false | follow-up suggestions after a complete text answer (see [Follow-up suggestions](#follow-up-suggestions)) |

`Agent\AgentConfiguration` is the value object shared by candidate and version (`fromArray()` fills missing keys with the defaults, `toArray()` always returns all 25 keys and every `mcpTools` entry with `terminal`). A stored configuration that lacks keys of a later schema is read without a data migration: `fromArray()` fills missing `rules` and `templates` with `[]` and `{}`, a missing `hybrid` means vector-only search, and the four gate keys default to off (`answerabilityVotes` 1). The four context keys are different, because their defaults change the context: a version stored without one of them keeps the legacy value per key (see [Stored versions](#stored-versions) under Context shaping).

#### Model options

`modelOptions` replaces the top-level `temperature` and `maxTokens` of before. Every option is optional; a missing one is the provider's default. `Provider\ModelOptions` carries them to `ProviderFactory::chatProvider()`, which maps them to the provider request; wired and proven against request bodies, not verified against live providers.

| Option | Type | Ollama | Anthropic |
|---|---|---|---|
| `temperature` | number 0..2 (Anthropic 0..1) | `options.temperature` | `temperature`, only for a model that takes sampling |
| `topP` | number 0..1 | `options.top_p` | `top_p`, only for a model that takes sampling |
| `topK` | integer ≥ 1 | `options.top_k` | `top_k`, only for a model that takes sampling |
| `maxTokens` | integer ≥ 1 | `options.num_predict` | `max_tokens`; without it the connection's `max_tokens`, else 8192 |
| `repeatPenalty` | number ≥ 0 | `options.repeat_penalty` | – |
| `seed` | integer | `options.seed` | – |
| `numCtx` | integer ≥ 1 | `options.num_ctx`; without it the model's Modelfile `num_ctx`, else the server default | – |
| `think` | boolean | `think` | – |
| `effort` | `low`, `medium`, `high`, `xhigh`, `max` | – | `output_config.effort` |
| `thinkingDisplay` | `omitted`, `summarized` | – | `thinking: {type: adaptive, display}` |

The static candidate schema lists every option with its type and range. Which options one model takes is decided by `Provider\ModelOptionsSchema::of($type, $model, ?ProviderModel $row)`, which narrows the definitions of `candidate.json`:

- the options of the provider type (the columns above);
- sampling (`temperature`, `topP`, `topK`) on Anthropic only for a model that takes it: the catalogue row's `supportsSampling`, else the rule table `Provider\AnthropicSamplingSupport` (current Claude models from Opus 4.7, Fable and Sonnet 5 on reject it; an unknown model counts as accepting); there `temperature` goes up to 1, and `temperature` and `topP` must not be set together (the schema carries `not: {required: [temperature, topP]}`, the publish reports the pair at `candidate/modelOptions/topP`), because Claude 4 models reject the pair;
- with a current catalogue row: `numCtx` up to `maxContextLength`, Anthropic `maxTokens` up to `maxOutputTokens`, `effort` with the levels the Models API reports in `providerData.capabilities.effort` (all five without a report, none when it reports effort as unsupported; without a catalogue row `effort` is offered for every Claude model, although older ones such as Haiku 4.5 reject it, so scan an Anthropic connection before relying on the check), `think` and `thinkingDisplay` only with the capability `thinking` (`thinkingDisplay` also needs adaptive thinking when the Models API reports thinking types).

The [publish](#publish) checks the options against this schema, the same schema `GET /api/refs/provider_models/{id}/options_schema` serves. At runtime `ProviderFactory` drops what the provider does not know and never sends sampling to a Claude model the rule table marks as rejecting it, nor `temperature` and `top_p` together (`temperature` wins), so a version stored before the check still runs. A stored configuration with top-level `temperature` and `maxTokens` (an older schema) is still read: `AgentConfiguration::fromArray()` maps them into the model options of the turn (an option set in `modelOptions` wins).

**Retrieval depth default.** The schema default of `retrievalDepth` is 10, so a new agent, and a `PATCH` of the candidate that omits the key, publishes depth 10. An explicit null means `store.top_k` (default 4), and that fallback is read at runtime by `Agent\RetrievalScope` on every turn, agent chat and measurement, so changing `store.top_k` changes every version that stores null. Conversations without an agent store a concrete depth at creation and are not affected. The context sent is capped per version: a new version sends up to `contextMaxChunks` (10) hits, so with depth 10 the model normally receives the whole pool; a version stored without the context keys keeps the bundle cap of `turn.context_max_chunks` and `turn.context_max_chars` (see [Context shaping](#context-shaping)).

### Publish

`POST /api/agents/{id}/publish` runs `Agent\AgentPublisher`: it locks the agent (`PESSIMISTIC_WRITE`) in a transaction, validates the candidate with `Agent\AgentConfigurationValidator`, stores the result as version `max(versionNumber) + 1` over the published versions (previews do not count), sets `currentVersion` and answers 201 with the version. `publishedBy` is the user identifier of the token. The partial unique index on `(agent_id, version_number)` is the backstop against two concurrent publishes; a violation answers 409.

The validator resolves and freezes the candidate; every violation answers 422 with `violations[].propertyPath` `candidate/<field>` (in lists `candidate/mcpTools/<index>/<key>`):

- `model` is required and must resolve to an entry of the model catalogue (`Provider\ProviderModelResolver::resolve()` with the capability `completion`, see [Model references](#model-catalogue)): missing, unknown (also a model name), ambiguous, unavailable, of an inactive connection or of a connection that is not `ollama` or `anthropic`, or known to lack `completion` answers 422 at `candidate/model`; the version stores the entry's full UUID. The entry's connection and name are then checked live (`Provider\ModelAvailabilityCheck`): a model the connection does not offer, or one that cannot be verified because the provider is unreachable, answers 422 at `candidate/model` with a text that never contains a key. There is no default model; a candidate with the removed key `connection` already fails the structure check (422 at `candidate/connection`).
- `collections` must exist and are stored as full UUIDs.
- every `mcpTools[].connection` must be an active `mcp` connection (`Mcp\McpConnectionResolver`) and is stored as full UUID; `name` must be a catalogue row (`Refs\McpTool`) of that connection, and a tool name may occur only once over all entries (the rule of `Mcp\McpToolFactory`, because the model cannot tell two tools of the same name apart).
- with `mcpTools`, the model must be able to take tool calls (`Provider\ToolModelCheck`, see [Model catalogue](#model-catalogue)) and, when the connection has an [allow-list](#allow-list-of-tool-models) `tool_models`, be on it: a model the catalogue knows to lack `tools` (or `completion`) answers 422 at `candidate/model`, for example `Model "qwen2.5-coder:1.5b" of connection "ollama-local" does not support tool calls (capabilities: completion, insert); choose a model with the tools capability or remove mcpTools. If the model was re-pulled, refresh it with neuron:provider-models:sync <id>.` The check runs only after the model resolved and passed the live check. A model without a catalogue row, with unknown capabilities or with a stale row passes, because the catalogue is a snapshot that only scans fill; a model that reports `tools` passes regardless of how well it uses them.
- `responseSchema` and `startSchema` must be valid JSON schemas (draft 2020-12 when they declare no `$schema`), `startSchema` with `type: object`.
- `defaultLanguage` must be in `languages`; `instructions` must have an entry for `defaultLanguage` and no key outside `languages`.
- the value ranges of the candidate schema (`modelOptions.temperature` 0..2, the integer fields at least 1).
- `modelOptions` must fit the model (`Provider\ModelOptionsSchema`, see [Model options](#model-options)), checked after the model resolved and passed the live check: an option the provider or the model does not take, or a value outside the model's limits, answers 422 at `candidate/modelOptions/<key>`, for example `Model "claude-opus-5-5" of connection "anthropic" does not take the option "temperature" (it takes: maxTokens, effort, thinkingDisplay).` The catalogue row is the resolved entry; with a stale one (or one without a description) only the provider type and the sampling rule table apply.
- `rules` and `templates` as described in [Publish checks of rules and templates](#publish-checks-of-rules-and-templates); all their violations are reported together in the same 422.
- answer gates: with `injectionGuard` on the slot `injectionRefusal`, with `answerabilityCheck` on the slot `unanswerableRefusal` needs a non-empty text for `defaultLanguage` (see [Refusal slots and publish checks](#refusal-slots-and-publish-checks)).

References are checked strictly at publish only. A collection, connection or tool deleted afterwards leaves the version as it is; the agent chat and the turns of an agent conversation report such a stale reference with 422 and its path, the anonymous creation with 503.

### Rollback

`POST /api/agents/{id}/rollback` with `{"version": "<IRI, full or partial UUID>"}` (validated against `src/Entity/Agent/rollback.input.json`, which is also the OpenAPI request body; malformed JSON 400, a schema violation 422) runs `Agent\AgentRollback`: it sets `currentVersion` to the given version and changes no version. A version number is not accepted. The version of another agent and a [preview](#preview-versions) answer 422 with `propertyPath` `version` (a preview was never published, so there is nothing to roll back to), an unknown version 404. Publishing after a rollback creates the next number after the highest existing one, not after the current one. A rollback runs none of the publish checks: it can make a version current again whose model the [tools capability check](#publish) or the [allow-list](#allow-list-of-tool-models) would reject today.

### Preview versions

A preview is the agent's candidate frozen as a version (`kind` `preview`) without publishing it, so an agent administrator can chat with a change or evaluate it before it reaches users. `Agent\AgentPreviewFactory::previewFor()` creates it: the candidate goes through the same check as a publish (`AgentConfigurationValidator::validateForPublish()`, every violation 422 at `candidate/<field>`, nothing is stored then) and is stored in the same resolved shape, so a preview of an unchanged candidate holds exactly what a publish would freeze. A disabled agent answers 422. The agent's current version is not touched, and an agent without any published version can have previews.

Previews are identified per agent by `candidateHash`, the sha256 of the resolved configuration with the keys of every object sorted (lists keep their order). While the candidate is unchanged, every request reuses the same preview; a changed candidate gets a new one, and the old preview stays until the purge removes it. Concurrency follows the publish: an existing preview is returned without a transaction, otherwise the agent row is locked (`PESSIMISTIC_WRITE`), the lookup is repeated under the lock and the preview is created only when still missing, so publishes and preview creations of one agent are serialized. The partial unique index `uniq_neuron_agent_version_agent_hash` is the backstop; a violation answers 409, and a retry of the request reuses the preview.

Previews are created by three requests, never on their own:

- `POST /api/agents/{id}/chat` with `"target": "candidate"` (`neuronai:agent:chat_candidate`, see [Agent chat](#agent-chat));
- `POST /api/eval_runs/start` and `POST /api/eval_questions/{id}/execute` with `"agent": "<IRI, UUID or slug>", "candidate": true` (`neuronai:eval:write`, see [Batch runs](#batch-runs) and [Single execution](#single-execution)).

Guards, because a preview is a test object and not a release:

- A preview never becomes the current version: `Agent::setCurrentVersion()` throws for one, so neither publish nor rollback can point an agent to it, and conversations created with `agent` and the anonymous release keep using the current published version.
- Rollback (`POST /api/agents/{id}/rollback`) and conversation replay (`targetVersion`) refuse a preview with 422; a replay compares against a version that can actually serve chats.
- `GET /api/agent_versions` lists published versions only, because clients that list versions (the admin UI, rollback pickers) expect publishes and would otherwise see a number 0 for every tested candidate (`Doctrine\PublishedVersionExtension`). `?kind=preview` lists the previews, `?kind=published` the published versions explicitly. `GET /api/agent_versions/{id}` reads a preview like any version, with its `kind`.
- The [provider connection delete guard](#provider-connections) counts previews like published versions, because they hold a resolved model reference, and names them as `agent preview of "<slug>"` instead of a version number.
- An evaluation run or single execution may run on a preview of an enabled agent, although the preview is not the current version; the current-version rule applies to published versions only. In a [run comparison](#run-comparison) such a run is named by `versionLabel` (`preview <first 8 hex digits of the hash>`) and `versionKind`.

**Purge.** Previews are throwaway: `Agent\Preview\AgentPreviewPurger` deletes every preview whose creation (`publishedAt`) lies more than the retention back, together with what references it: its evaluation runs (with their results, AI evaluations, suggestions and human ratings, by database cascade) and its conversations (the evaluation conversations of those runs and of single executions, with messages, stream events and feedback), then the version itself. What is worth keeping is tested again on the published version. A preview is skipped, counted per reason and taken by a later run, when it is the target of a conversation replay (only possible for rows written outside the API, the foreign key is `RESTRICT`), when one of its evaluation runs is `pending` or `running`, or when a turn of one of its conversations holds the turn lock. Each preview is deleted in its own transaction that locks the version row and checks the skip rules again, so a run or conversation created on the preview at the same moment is either seen or fails on its foreign key; a failure rolls back that preview only and ends the run. A second run directly after the first finds nothing to do.

- **Retention.** The bundle reads it through the port `Agent\Preview\AgentPreviewRetentionInterface` (`retentionDays(): int`) once per run, so a changed value applies to the next run without a deployment. 0 keeps previews forever. Without an alias to an implementation every run only logs a warning and deletes nothing. A host application typically reads it from a runtime setting (for example `retention_days`, integer, minimum 0).
- **Schedule.** The purge runs on the schedule `neuron` next to the purge of anonymous conversations (see [Retention and purge](#retention-and-purge)): a `PurgeAgentPreviewsMessage` every `agent_previews.purge_interval` (default `1 day`), handled by `PurgeAgentPreviewsHandler`. The worker that consumes `scheduler_neuron` runs it in place. The message routing of the application applies only to a message dispatched on the bus explicitly (`--async` below); route it like the anonymous purge.
- **Command.** `bin/console neuron:agent:previews:purge` runs the purge once and prints the report (`candidates N, deleted N (eval runs N, conversations N), skipped N` with the reasons); `--dry-run` applies the same selection and skip rules and reports what would be deleted without changing anything, and `--async` dispatches the message on the bus instead (not combinable with `--dry-run`).
- **Audit.** Creating a preview writes no audit entry, because versions are not audited (the agent row does not change). The purge runs under the system actor `system:neuron:purge`, shared with the anonymous purge, which is the actor of every conversation it deletes; evaluation runs and the version itself are deleted without an audit entry, as runs and versions are not audited.

Limitations: a start of an evaluation run on `candidate: true` creates (or reuses) the preview before the other checks of the start, so a start refused afterwards, for example because no question is active, leaves the preview behind; the next start of the unchanged candidate reuses it, otherwise the purge removes it. Previews are not shown in the agent's own record; list them with `GET /api/agent_versions?agent=<agent IRI>&kind=preview`.

### Agent chat

`POST /api/agents/{id}/chat` (`agent_chat`, `neuronai:agent:chat`, routed to the `stream` pool like `/chat`) runs one stateless turn on the agent's current version; nothing is stored. The body is validated against `src/Entity/Agent/chat.input.json` (also the OpenAPI request body): `messages` (required, `{role, content}` with role `user` or `assistant`, the last one a non-empty user message), `variables` (values of the `{{name}}` placeholders, checked against the version's `startSchema`), `language` (one of the version's `languages`, default `defaultLanguage`) and `maxToolCalls` (may only lower the resolved bound). Everything else comes from the version through `Agent\VersionTurnMapper`: instructions of the effective language with `{{date}}`, `{{language}}`, `{{agentName}}` and the variables filled in, provider, model, collections, retrieval depth and hybrid search, the tool subset of `mcpTools` with its terminal flags, response schema, sampling (`temperature`, `maxTokens`; wired, not verified against live providers) and the tool-call bound. `Chat\AgentChat` keeps only what needs the request (the `candidate` permission and the preview created by the token's user) and runs the turn on `Chat\AgentRunner` (see [Running an agent without a request](#running-an-agent-without-a-request)), which maps the version, evaluates the pre-answer rules and calls `Chat\ChatService::chatConfigured()`.

**Chat with the candidate.** The optional `target` selects the configuration: `published` (the default) is the current version, `candidate` a [preview](#preview-versions) of the agent's current candidate, so a change can be tried before it is published. `target: candidate` needs `neuronai:agent:chat_candidate` on top of the operation's `neuronai:agent:chat` (403 `Chatting with the candidate of an agent (target "candidate") requires the permission neuronai:agent:chat_candidate.`), because it tests an unpublished configuration, stores a version row and runs the live model check of a publish, which is agent-admin work like the agent writes. The candidate is validated like a publish (422 at `candidate/<field>`, nothing stored), an unchanged candidate reuses its preview, and a concurrent creation answers 409. The agent needs no published version for it; a disabled agent answers 422 for both targets. Everything else works as on the published version: the same body and refused keys, the preview's rules, gates and templates apply, and nothing is stored apart from the preview itself. The response names the preview in `agentVersion` and has `agentVersionKind` `preview` (`published` otherwise).

Errors, all before a provider is contacted: a body key that the version fixes (`instructions`, `connection`, `model`, `collections`, `mcpConnections`, `retrievalDepth`, `hybrid`, `hybridWeight`, `contextMaxChunks`, `contextMaxChars`, `contextExpansionMaxChars`, `contextInstructions`, `responseSchema`, `terminalTools`, `maxUserTurns`, `rules`, `templates`, `injectionGuard`, `answerabilityCheck`, `answerabilityVotes`, `followUps`) answers 422 `"<key>" is fixed by the agent version and cannot be set on this action.`; malformed JSON 400, a schema violation 422; `Agent "<slug>" is disabled.` and `Agent "<slug>" has no published version.` 422; an unsupported language 422 at `language`, an invalid, undeclared, reserved or missing variable 422 at `variables/<name>`; a reference of the version that no longer resolves 422 with the detail `Agent version <n> of "<slug>" (<iri>): <path>: <reason>` (`configuration/collections/<i>`, `configuration/mcpTools/<i>/connection`, `configuration/mcpTools/<i>/name`, `configuration/model`). The response (`Chat\AgentChatResult`) has the fields of the `/chat` result (`answer`, `provider`, `model`, `mcpConnections`, `sources`, `usage`, `structured`, `schemaErrors`, `forced`, `limitReached`, `terminalTool`) plus `agentVersion` (IRI of the version that answered), `agentVersionKind` (`published`, or `preview` for `target: candidate`), `language` (the effective tag), `rule` (see below), `gate` (see below), `followUps` (see below) and `frame` (`{prefix, suffix}` of the version's `frame` template for a text answer, including a forced and a fallback answer; null without the template and for schema, terminal-tool and rule answers; `answer` stays unframed, see [Frame](#frame)). The version's limit texts and forced-close fallback apply as in a turn (see [Output templates](#output-templates)): a failed or empty forced close with a `forcedCloseFallback` template answers 200 with the fallback as `answer` and `forced` true. There is no `ask` action.

Before retrieval and the provider call, the version's [pre-answer rules](#pre-answer-rules-and-templates) are evaluated on the `content` of the last `user` message in the effective language. When a rule answers or refuses, the call still answers 200, without retrieval or provider call: `answer` is the rendered template text, `provider`, `model` and `mcpConnections` are the version's, `sources` is `[]`, `usage`, `structured`, `schemaErrors` and `terminalTool` are null, `forced` and `limitReached` false, `frame` null, and `rule` is `{index, name, match, outcome, template}` of the deciding rule. When no rule decided, `rule` is null (or absent; clients treat both alike). Nothing is stored either way.

When no rule decided, the version's [answer gates](#answer-gates) run; `agent_chat` and the `Chat\AgentRunner` with its default options are the only callers of `ChatService::chatConfigured()` that enable them (the judge does not). When the prompt-injection guard refuses, the call answers 200 without retrieval or answer call: `answer` is the version's `injectionRefusal` text (effective language, else the default language), `gate` is `{gate, kind, template}` (`{"gate": "injection", "kind": "injection_refusal", "template": "injectionRefusal"}`), `sources` is `[]`, `frame`, `rule`, `structured`, `schemaErrors` and `terminalTool` are null, `forced` and `limitReached` false, and `provider`, `model` and `mcpConnections` are the version's. Otherwise `gate` is null. The gate's reason is never returned. Whenever a gate ran (refused, passed or failed open), `usage` includes its tokens in `input_tokens` and `output_tokens` and lists them under `usage.gates.<gate>` as `{input_tokens, output_tokens}`. With `followUps` on, a complete text answer carries the suggestions as `followUps` (see [Follow-up suggestions](#follow-up-suggestions)), `[]` when the follow-up call failed or produced nothing; `followUps` is null when the version has it off and when the gate did not run (a rule, a gate refusal, a schema, terminal-tool, fallback or empty answer).

**Source freshness.** Each entry of `sources` in the agent chat result carries `checkedAt` (an import last found the source) and `changedAt` (its content last changed), both ISO 8601, so a client can show "checked 3 days ago, changed on …". The dates come from the application's `Retrieval\SourceFreshnessInterface` alias (`freshness(list<documentId>)`), asked when the answer is returned rather than stored with the chunk, because an unchanged source is not re-embedded and a stored date would go stale. Without an alias, for a chunk without a document or for an unknown document, both are null. A typical implementation reads the check and change times of the host's document rows.

### Running an agent without a request

`Chat\AgentRunner` (private, service id = class name; inject it by type) runs one stateless turn on an agent version without HTTP request, security token or user, for a queue worker, a console command or another service. It is the turn of `agent_chat` and returns the same `Chat\AgentChatResult`: the disabled and published checks, the mapping by `Agent\VersionTurnMapper`, the pre-answer rules on the last user message, the turn on `ChatService::chatConfigured()` with the version's answer gates, and the source freshness. `Chat\AgentChat` (and with it the [OpenAI-compatible chat API](#openai-compatible-chat-api)) and the [judge](#the-judge) run on it, so the HTTP action and headless callers cannot drift apart. Nothing is stored.

The entries, each one step further down:

- `run(Agent $agent, array $messages, AgentRunOptions $options = new AgentRunOptions()): AgentChatResult` runs the current published version; a disabled agent answers 422 `Agent "<slug>" is disabled.`, an agent without a current version 422 `Agent "<slug>" has no published version.`, as in the agent chat.
- `runVersion(AgentVersion $version, array $messages, AgentRunOptions $options = …)` runs any version, published or preview, without checking the agent's `enabled` flag; `assertEnabled(Agent)` and `publishedVersion(Agent)` are public for callers that pick the version themselves.
- `runConfigured(TurnConfiguration $configuration, array $messages, AgentRunOptions $options = …)` runs an already mapped configuration and ignores `variables`, `language` and `maxToolCalls` of the options. It needs no database beyond what the configuration references, which is how kernel-free tests build a run (see [Test seams](#test-seams)).
- `execute(AgentVersion …)` and `executeConfigured(TurnConfiguration …)` run the same turn but return the raw `Chat\AgentRunResult`: `configuration` (the configuration the turn used; under a [tool argument guard](#tool-argument-guard) the guarded copy) and either `rule` (the `RuleOutcome` of a deciding pre-answer rule, no provider called, `chat` null) or `chat` (the array of `chatConfigured()` with the snake-case keys of `ChatService::chat()`, `rule` null). They touch neither the IRI converter nor the freshness port, for callers that evaluate the answer themselves; the judge uses `execute()`.

`$messages` is a list of `['role' => 'user'|'assistant', 'content' => string]` whose last entry is a user message, as in the agent chat body. `Chat\AgentRunOptions` is a readonly value object; its defaults are those of `agent_chat` without `variables`, `language` and `maxToolCalls`:

| Option | Default | Meaning |
|---|---|---|
| `variables` | `[]` | values of the `{{name}}` placeholders, validated against the version's `startSchema` |
| `language` | null | the effective language; null is the version's `defaultLanguage` |
| `maxToolCalls` | null | request bound; may only lower the resolved tool-call bound |
| `rules` | true | evaluate the version's pre-answer rules on the last user message |
| `gates` | true | run the version's answer gates |
| `toolGuard` | null | a `Chat\ToolArgumentGuard` that checks one argument of every tool call of the run (see [Tool argument guard](#tool-argument-guard)); null runs the tools unchanged |

```php
use Dmstr\NeuronAiBundle\Chat\AgentRunOptions;

// $runner: the injected Chat\AgentRunner, e.g. in a message handler
$result = $runner->run($agent, [['role' => 'user', 'content' => 'Summarise the open issues.']], new AgentRunOptions(
    variables: ['team' => 'backend'],
    language: 'en',
    maxToolCalls: 5,
));
$answer = $result->answer;
```

Errors are the exceptions of the agent chat, also for headless callers, which map them themselves: `UnprocessableEntityHttpException` for a disabled or unpublished agent and for invalid variables or an unsupported language (at `runConfigured()` for an invalid message list or a collection deleted since the mapping), `Agent\StaleReferenceException` for a reference of the version that no longer resolves, and a provider failure unchanged. They stay HTTP exceptions because `agent_chat` passes them through as its responses; the messages are those listed under [Agent chat](#agent-chat).

What stays in `Chat\AgentChat` is what needs the request: the permission of `target: candidate` and the creation of the preview by the token's user. A headless caller that wants to try a candidate passes a preview version it obtained itself to `runVersion()`. MCP tools of a `forward_jwt` connection read the JWT from the security token while the tools are built; a run without one (a worker, a command) gets only the tools whose catalogue annotation `readOnlyHint` is `true`, run with the scan service account (see [Configuration schema](#configuration-schema)).

### Tool argument guard

`Chat\ToolArgumentGuard` is an optional per-run check on one argument of every tool call, for a tool server whose credentials see more tenants than the caller: a connection with a service account can reach every project the account can, while one run should only touch the projects it was started for. The caller names, per tool, the one argument that identifies the tenant or project of a call, and the values the run may use. With the guard as `AgentRunOptions::$toolGuard`, the runner replaces every tool of the run by a `Chat\GuardedTool` (on a copy of the configuration, `TurnConfiguration::withTools()`), which asks the guard before the wrapped tool runs. `agent_chat`, the OpenAI-compatible API and the judge run without a guard; it is meant for callers of the runner that know the allowed values.

```php
use Dmstr\NeuronAiBundle\Chat\AgentRunOptions;
use Dmstr\NeuronAiBundle\Chat\ToolArgumentGuard;

$guard = new ToolArgumentGuard(
    ['list_issues' => 'project', 'get_issue' => 'project_id'], // tool name => argument name
    ['acme/app', 42],                                           // allowed values
);
$result = $runner->run($agent, $messages, new AgentRunOptions(toolGuard: $guard));
```

The decision is fail-closed:

- A tool without an entry in the map is refused, also a tool that has no such argument at all or two of them; there is no exception list, so every tool the run should be able to call needs an entry.
- An argument the tool's schema does not declare is refused, because the framework drops an undeclared value before the call and the check would not see what the tool sends.
- A missing argument (absent or null) is refused.
- Only an `int` or a `string` is a value; booleans, floats, arrays and objects are refused.
- The value, as a string, must equal an allowed value exactly: no trimming, no case folding, no URL decoding. Which spellings are valid is entirely the allowed set (an allowed `42` matches int 42 and string `"42"`; `acme%2Fapp` or `Acme/App` only when listed). An empty set refuses every call.
- The constructor throws `\InvalidArgumentException` for an empty tool or argument name and for an allowed value that is neither int nor string.

A refused call never reaches the wrapped tool: an MCP tool neither connects (no handshake) nor sends `tools/call`. Its result is a tool error (`ToolOutput::error()`) with the reason, for example `Tool "list_issues" is refused: the value of the argument "project" is not allowed in this run.`, never a thrown exception; the reason does not echo the allowed values. The run goes on, so the model can answer without the data, and refused calls count against the tool-call bound like any other call; a refused call of a terminal tool does not end the turn. Every tool of the run is wrapped, MCP or not. A tool name the model invents that is not offered at all never becomes a tool of the run: the provider layer of neuron-ai rejects the response, and the turn fails with a `ProviderException` before any tool runs.

**Cast values.** The check runs on the inputs as the tool has bound them, that is after the cast by its declared property types: a `string` property turns int 42 into `"42"`, an `integer` property turns `"42"` into 42. With `42` allowed, a float `42.0` on a `string` property passes (it becomes `"42"`), and so does `"42"` on an `integer` property, because that is what the tool would send; a float `42.5` stays refused. The guard compares what the tool sends, not what the model wrote.

**Not an access layer for connections or collections.** The guard is unrelated to [restricted connections](#configuration-schema) (`Mcp\McpConnectionRestriction`, permission `neuronai:mcp_connection:use_restricted`) and to retrieval scopes (`Agent\RetrievalScope`, the [scope](#scope) of the RAG tools with `Mcp\Server\CollectionScopeResolver`): it neither decides which connections or collections a version may use nor changes them, it only checks one argument of the calls of one run. The other arguments of a call and the tool's result remain the server's business.

### OpenAI-compatible chat API

`GET /api/v1/models` and `POST /api/v1/chat/completions` (`Controller\OpenAiChatController`, plain Symfony routes, not in the OpenAPI document) make the published agents reachable for any OpenAI client: base URL `https://<host>/api/v1`, the agent slug as model, the usual bearer token. The answers follow the OpenAI chat completions format with a few extensions (below), so an OpenAI client needs only base URL, model and token.

| Route | Permission | What it does |
|---|---|---|
| `GET /api/v1/models` | `neuronai:agent:chat` | One entry per enabled agent with a published version: `id` (slug), `object` `model`, `created` (publish time of the current version), `owned_by` (the bundle option `dmstr_neuron_ai.openai_compatible.owned_by`, default `dmstr`), `name` |
| `POST /api/v1/chat/completions` | `neuronai:agent:chat` | One stateless turn on the current version of the agent named by `model` (slug or id), the same turn as `POST /api/agents/{id}/chat` (`Chat\AgentChat`) |

```bash
curl https://<host>/api/v1/chat/completions \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"model": "handbook", "messages": [{"role": "user", "content": "When must the spindle be serviced?"}]}'
```

**Request.** `messages` with the roles `user` and `assistant` (content as a string or a list of `text` parts) are the history and the question; `system` and `developer` messages are skipped, the version brings its own instructions. Sampling and length parameters (`temperature`, `max_tokens`, …) are ignored, the version fixes them. `tools`, `functions`, `tool_choice`, `function_call`, `n` > 1, `logprobs`, audio and `tool` messages answer 400. A `response_format` must match the version: `text` for a version without `responseSchema`, `json_object` or a `json_schema` with exactly the version's schema for one with it.

**Response.** `choices[0].message.content` is the answer with the version's `frame` applied; a schema answer is the JSON as a string, and one that did not validate has `content` null and a `refusal`. `usage` is in OpenAI form (`prompt_tokens`, `completion_tokens`, `total_tokens`, `prompt_tokens_details.cached_tokens`). On top of the OpenAI format:

- `sources`: only the sources the answer cites as `[n]` or `[n, m]`, one entry per page in the order of the first citation, the markers of the content renumbered to that order (`Chat\CitedSources`; in a schema answer only its string values, so numbers in JSON arrays stay as they are); each with `url`, `title`, `locators` (`link` with text fragment, `quote`, `page`), `synthetic` (no web address, e.g. an upload) and `last_verified_at`, `last_changed_at` (the `checkedAt` and `changedAt` of [Agent chat](#agent-chat));
- `follow_up_questions`: the version's `followUps`, or the `followUps` list of a schema answer;
- `agent_version`: IRI of the version that answered.

`finish_reason` is always `stop`: an answer forced at the tool-call bound (`forced`) is complete, a schema refusal stops normally, and a turn cut at the token bound fails with `server_error` instead of answering.

**Streaming.** `stream: true` sends the finished answer as `chat.completion.chunk` events: the content, a closing chunk with `finish_reason`, `usage`, `sources` and `follow_up_questions`, then `data: [DONE]`. The turn itself is not streamed token by token, so the first byte comes when the answer is complete.

**Errors** are OpenAI error objects `{"error": {"message", "type", "param", "code"}}`: 400 for an invalid or unsupported request (`code` `unsupported_parameter` or `response_format_mismatch`), 404 `model_not_found` for an unknown, disabled or unpublished agent (none of them is listed in `/v1/models`), 403 without the permission, 422 from the turn (stale reference), 500 `server_error` with a generic message when the turn fails (e.g. `maxTokens` reached before an answer; the cause is logged). A missing or invalid token is answered by the firewall before the controller, in its own format (401).

### Pre-answer rules and templates

The candidate keys `rules` and `templates` give an agent fixed answers and refusals that need no model. Every turn on a version, on all four agent paths, evaluates its rules against the user input after the version is mapped and before history, retrieval and any provider call; a rule that decides the turn answers it with the text of a template. Such a turn makes no provider request and embeds nothing. A version with `rules: []` runs without this gate.

```json
{
  "languages": ["de", "en"],
  "defaultLanguage": "de",
  "rules": [
    {"name": "order-status", "match": {"type": "pattern", "pattern": "^bestell(nummer|status)", "flags": "i"}, "outcome": "continue"},
    {"name": "opening-hours", "match": {"type": "keywords", "keywords": ["öffnungszeiten", "opening hours"]}, "outcome": "answer", "template": "hours"},
    {"name": "card-number", "match": {"type": "pattern", "pattern": "\\b(?:\\d[ -]?){13,19}\\b"}, "outcome": "refuse", "template": "refusal"}
  ],
  "templates": {
    "hours": {"de": "Wir sind Montag bis Freitag von 9 bis 17 Uhr erreichbar.", "en": "We are available Monday to Friday, 9 am to 5 pm."},
    "refusal": {"de": "{{agentName}} kann diese Anfrage nicht bearbeiten.", "en": "{{agentName}} cannot handle this request."}
  }
}
```

#### Rules

A rule is `{name, match, outcome, template}`:

- `name`: string of at most 64 characters or null; reported with the decision.
- `match.type` `keywords`: `keywords` (at least one, each 1 to 200 characters) and `wholeWord` (default true). Keywords are literal and case-insensitive with Unicode simple case folding: `ÄPFEL` matches `äpfel`, but `STRASSE` does not match `straße`. Any keyword matches. With `wholeWord` true a keyword matches only where no letter or digit precedes or follows it (`preis` does not match `Preisliste`), with false anywhere in the input (`preis` matches `Preisliste`).
- `match.type` `pattern`: `pattern` (a PCRE without delimiters, 1 to 1000 characters) and `flags` (any of `i`, `m`, `s`, `x`, default `""`; another flag is a schema error). It is compiled as `/<pattern>/<flags>u`: every `/` not preceded by a backslash is escaped, and UTF-8 mode is always on. The pattern is matched against the whole input.
- `match.type` `language`: `languages` (at least one, unique) are the allowed languages; the rule matches when the effective language of the turn is **not** among them. The language of a conversation is fixed at creation, so such a rule decides every turn of the conversation alike; in the agent chat it follows the request's `language`.
- `outcome`: `continue`, `answer` or `refuse`.
- `template`: the name of a template; required for `answer` and `refuse`, null or absent for `continue`.

**Evaluation.** The rules are evaluated in list order against the user input only: the turn's `content`, in the agent chat the `content` of the last `user` message; never the history, the instructions or retrieved context. The first matching rule decides. `continue` ends the evaluation and the turn runs as usual (a later rule that would match is not consulted); `answer` and `refuse` end the turn with the rendered template. Without a match the turn runs as usual.

**Runtime failures.** A rule whose regular expression fails at runtime (PCRE backtrack limit, JIT stack limit, invalid UTF-8) counts as no match, and evaluation continues with the next rule. The failure is logged at warning level with the context `agent` (slug), `version` (number), `ruleIndex`, `ruleName` and `pregError`. The bound is the PHP runtime's configuration, by default `pcre.backtrack_limit` 1000000 and `pcre.jit` 1; the bundle calls no `ini_set()` and never truncates the input. Under JIT a runaway pattern reports the JIT stack limit rather than the backtrack limit; both mean no match. The `maxLength` of 1000 on the pattern is a guard, not a proof against catastrophic backtracking.

#### Templates

`templates` maps a template name to texts per language tag. A name matches `^[A-Za-z_][A-Za-z0-9_]*$`. The names `frame`, `limitReason`, `turnLimitReason`, `forcedCloseFallback`, `injectionRefusal` and `unanswerableRefusal` are reserved slots, used by the runtime itself and never by a rule (a rule that references one is a publish error): a frame around the final answer, the texts of the `limit` records and events, the answer of a failed or empty forced close (see [Output templates](#output-templates)) and the refusal texts of the answer gates (see [Answer gates](#answer-gates)). A version without them behaves as without templates. A rule's template is looked up in the effective language, else in `defaultLanguage`, whose text publish guarantees.

#### Placeholders in templates

Templates use the placeholder syntax of the instructions, `{{name}}`. Allowed names are `date`, `language`, `agentName` and the keys of `startSchema.properties` (not `patternProperties` or `additionalProperties`), plus the context of the template: in every template that is not a reserved slot `rule` (the rule name, empty without one) and `outcome` (`answer` or `refuse`), which take precedence over a variable of the same name; in the slots `frame` → `answer`, `limitReason` → `toolCalls`, `maxToolCalls`, `turnLimitReason` → `turn`, `maxUserTurns`, and `forcedCloseFallback`, `injectionRefusal` and `unanswerableRefusal` → none. Any other name is a publish error. A slot value wins over a caller variable of the same name, as `rule` and `outcome` do.

The two places that take placeholders treat a missing value differently, on purpose: a template is shown to the user, and a declared but optional variable must not turn a fixed answer or a refusal into an error.

| | Instructions | Templates |
|---|---|---|
| Names checked | at the turn only (an unknown name has no value) | at publish, per template and language |
| Declared variable without a value | 422 at `variables/<name>` `required by the instructions.`, before any provider call (see [Placeholders and variables](#placeholders-and-variables)) | renders as empty text; never an error |

#### Publish checks of rules and templates

The candidate schema rejects structure errors on `POST`, `PATCH` and publish (an unknown `match.type` or `outcome`, a flag outside `imsx`, a missing type field, a field of another type, a template name outside the pattern; an empty language map under a template name is reported as a type error). Publish then collects every rule and template violation in one 422:

| `propertyPath` | Violation |
|---|---|
| `candidate/rules/<i>/match/pattern` | the pattern does not compile; the message carries the PCRE error |
| `candidate/rules/<i>/match/languages/<j>` | the language is not one of the agent's `languages` |
| `candidate/rules/<i>/template` | `answer` or `refuse` without a template, a template on `continue`, an unknown template or a reserved slot |
| `candidate/templates/<name>` | no text for `defaultLanguage` |
| `candidate/templates/<name>/<lang>` | a language outside `languages`, or a placeholder not available in this template (the message names it and lists the available ones) |
| `candidate/templates/frame/<lang>` | the text does not contain `{{answer}}` exactly once (`The template "frame" must contain the placeholder "{{answer}}" exactly once (found N).`) |
| `candidate/templates/injectionRefusal`, `candidate/templates/unanswerableRefusal` | the gate (`injectionGuard`, `answerabilityCheck`) is on and the slot has no non-empty text for `defaultLanguage` (see [Answer gates](#answer-gates)) |

#### Behaviour per path

| Path | A turn a rule decided |
|---|---|
| `POST /conversations/{id}/messages` | 201 with the rule record; stored: the user record and the rule record (kind `rule_answer` or `rule_refusal`, see [Records](#records)) |
| `POST /conversations/{id}/stream` | `sources` (empty), `rule`, `done`; the same two records (see [Rules in the stream](#rules-in-the-stream)) |
| anonymous conversation with a released agent | as the two routes above; the anonymous limits apply as usual |
| `POST /agents/{id}/chat` | 200 with the rendered text as `answer` and the `rule` object; nothing stored (see [Agent chat](#agent-chat)) |

**Counting and history.** A ruled turn counts as a turn and uses no tokens: `turnCount` +1, `tokensUsed` +0, and it closes the conversation at `maxUserTurns` or the anonymous turn limit like any turn (see [User-turn limit](#user-turn-limit)). In the history of later turns a refusal is never replayed: the model sees neither the refused input nor the refusal. A fixed answer is replayed as a normal user/assistant pair with the rendered text.

The kinds and close reasons `rule_answer` and `rule_refusal` fit the existing `VARCHAR(20)` columns; the feature needs no migration.

### Output templates

The four reserved slots of `templates` act on every agent path: the synchronous and streamed turns of an agent conversation, anonymous conversations with a released agent and `agent_chat`. Like any template they are looked up in the effective language, else in `defaultLanguage`, and rendered with the placeholders of [Placeholders in templates](#placeholders-in-templates): a missing caller variable renders as empty text, and a slot value wins over a caller variable of the same name. A version without them sends its instructions byte for byte unchanged. Paths without an agent (`/chat`, conversations created without an agent) keep the English texts and have neither frame nor fallback.

```json
{
  "templates": {
    "frame": {"de": "{{answer}}\n\nIhr Team von {{agentName}}", "en": "{{answer}}\n\nYour {{agentName}} team"},
    "limitReason": {"de": "Werkzeuglimit {{maxToolCalls}} nach {{toolCalls}} Aufrufen erreicht.", "en": "Tool limit of {{maxToolCalls}} reached after {{toolCalls}} calls."},
    "turnLimitReason": {"de": "Letzte Runde ({{turn}} von {{maxUserTurns}})."},
    "forcedCloseFallback": {"de": "Dazu kann ich gerade keine Antwort geben.", "en": "I cannot answer this right now."}
  }
}
```

#### Frame

`frame` wraps the final answer. Its text must contain `{{answer}}` exactly once (whitespace inside the braces is tolerated), checked at publish for every language (see [Publish checks of rules and templates](#publish-checks-of-rules-and-templates)). The other placeholders are rendered and the text is split at `{{answer}}` into `prefix` and `suffix`; either may be empty, and a value that itself contains `{{answer}}` never moves the split. The answer itself is never framed: the stored `content`, the message replayed to the model in later turns and the `answer` of `agent_chat` stay unframed, and the frame is delivered next to it:

- the final record gets `frame` `{prefix, suffix}` (see [Records](#records)) when it is kind `text`, status `complete` and its close reason is not `terminal_tool`: a normal answer, a forced answer (`forced_bound`, `user_turn_limit`) and a [fallback answer](#forced-close-fallback) are framed, schema, terminal-tool, rule, `error` and `aborted` records are not;
- the streamed turn sends one `frame` event `{messageId, prefix, suffix}` after the run's last event and before `done` (see [Frame in the stream](#frame-in-the-stream));
- `agent_chat` returns `frame` next to the unframed `answer`, null for schema, terminal-tool and rule answers.

Clients show `prefix + content + suffix`. A client that ignores `frame` shows the unframed answer.

#### Limit texts

`limitReason` replaces the reason of a reached tool-call bound ("Tool-call limit of N reached after M calls; the model was asked to answer.") and takes `toolCalls` (the executed calls when the bound was reached, parallel calls counted) and `maxToolCalls` (the bound). `turnLimitReason` replaces the reason of the last allowed user turn ("User-turn limit of N reached: this is the last turn of the conversation; the model was asked to answer.") and takes `turn` (the 1-based number of this turn) and `maxUserTurns`. The rendered text is the `content` of the `limit` record and the `reason` of the `limit` or `turn_limit` event; without the template the English text stays byte for byte. The closing instruction that a forced close in the modes `tool_choice` and `fallback` appends to the system prompt (`ForcedClose::INSTRUCTION`, `ForcedClose::TURN_LIMIT_INSTRUCTION`, see [The turn](#the-turn)) is addressed to the model and stays English.

#### Forced-close fallback

`forcedCloseFallback` (no slot placeholders) answers a forced close that produced no answer, at the tool-call bound and on the last allowed user turn alike: the forced step called a non-terminal tool or its forced terminal call failed (`ForcedCloseFailedException`), or the forced answer was empty. Instead of failing or ending with an empty answer, the turn succeeds with the rendered fallback:

- **Records:** the earlier steps and the `limit` record as before, the text the forced step wrote before its tool call (if any), then the final record: kind `text` (also on a version with `responseSchema`, with `structured` null), status `complete`, the fallback in `content`, `closeReason` `user_turn_limit` or `forced_bound` by the usual precedence, `providerPayload` `{step, part: "fallback", mode}` without `message`; no `error` record. An empty forced answer is replaced by this record, not kept next to it.
- **History:** the turn is not replayed to the model in later turns (there is no message to replay, and a discarded tool call has no result), as for a failed turn.
- **Stream:** `…, limit` or `turn_limit`, the `token` events the forced step sent before its tool call, one `token` with the whole fallback (none when it renders empty), `frame` when the version has a `frame` template, `done` with `status` `complete` and the close reason in `closeReason`.
- **`agent_chat`:** 200 with the fallback as `answer`, `forced` true, `structured` and `schemaErrors` null, and `frame` as for any text answer.

Other failures (deadline, cancellation, provider error) keep their path. Without the template a failed forced close still ends with the `error` record, and an empty forced answer stays empty.

The column `neuron_message.frame` comes with the migration `Version20261003203019`; existing records keep null.

### Answer gates

Three optional gates around the answer of an agent turn, each switched per agent version and off by default: the **prompt-injection guard** (`injectionGuard`) before retrieval, the **answerability check** (`answerabilityCheck` with `answerabilityVotes`) between retrieval and answer, and **follow-up suggestions** (`followUps`) after a complete text answer. Each gate is a separate provider call on the version's provider and model. The gates live in `Conversation\Gate\`: `TurnGates` is the single entry point with one hook per gate, and `GateRun` drives the hooks for one turn on every agent path, so a gate cannot be wired into one path only.

#### Configuration

| Candidate key | Type, default | Effect |
|---|---|---|
| `injectionGuard` | boolean, false | the prompt-injection guard runs on every turn (see [Prompt-injection guard](#prompt-injection-guard)) |
| `answerabilityCheck` | boolean, false | the version's model decides whether the retrieved context answers the question (see [Answerability](#answerability)); inactive for a version without collections |
| `answerabilityVotes` | 1, 3, 5 or 7, 1 | votes of the answerability check, a strict majority of unanswerable votes refuses; another value is a schema error (422 at `candidate/answerabilityVotes`) |
| `followUps` | boolean, false | suggested follow-up questions after a complete text answer, stored on the answer record as `followUps`, streamed as `follow_ups` and returned by the agent chat (see [Follow-up suggestions](#follow-up-suggestions)) |

The keys are fixed by the version: in the body of `POST /conversations` with `agent` and of `POST /agents/{id}/chat` each answers 422 like every other version-fixed key (see [Creation with an agent](#creation-with-an-agent) and [Agent chat](#agent-chat)). A version stored without the keys runs without gates: same records, events, usage and provider calls as a version with all gates off.

#### Order within a turn

1. Pre-answer rules (see [Pre-answer rules and templates](#pre-answer-rules-and-templates)); a rule that answers or refuses ends the turn, and no gate runs.
2. Prompt-injection guard.
3. Retrieval.
4. Answerability check.
5. The answer (the model run with tools).
6. Follow-up suggestions, only after a complete text answer: not after a rule, a refusal, a schema or terminal-tool answer, a forced-close fallback, a failed turn or an empty answer.

A refusing gate ends the turn at its step; the later steps do not run.

#### Refusal slots and publish checks

The reserved template slots `injectionRefusal` and `unanswerableRefusal` hold the refusal texts of the two refusing gates. They supply no placeholders of their own; `date`, `language`, `agentName` and the properties of `startSchema` are available as in every template (see [Placeholders in templates](#placeholders-in-templates)). A refusal uses the slot's text in the effective language, else in `defaultLanguage`, else the bundle's English default (`Conversation\Gate\GateClose::DEFAULT_TEXTS`: "I cannot process this request." and "I cannot answer this question from the available sources."), which only a version stored without the slot can reach.

```json
{
  "injectionGuard": true,
  "templates": {
    "injectionRefusal": {"de": "Diese Anfrage kann {{agentName}} nicht bearbeiten.", "en": "{{agentName}} cannot process this request."}
  }
}
```

Publish answers 422 at `candidate/templates/injectionRefusal` when `injectionGuard` is on and the slot has no non-empty text for `defaultLanguage`, and at `candidate/templates/unanswerableRefusal` likewise for `answerabilityCheck`. A slot that exists without a `defaultLanguage` entry is reported once, by the template check at the same path. A rule that references either slot is rejected at `candidate/rules/<i>/template` like any reserved slot. Both names are reserved: a candidate whose rule references a template called `injectionRefusal` or `unanswerableRefusal` does not pass publish.

#### Prompt-injection guard

With `injectionGuard` on, `Conversation\Gate\InjectionGuard` asks the version's model whether the current user message is a prompt-injection attempt: whether it tries to override, ignore or reveal the instructions, change the assistant's role or rules, smuggle in instructions or extract internal data. Ordinary questions, including critical, rude, off-topic or security-related ones, are meant to pass. Its only input is the user message of the turn (in the agent chat the `content` of the last `user` message): no history, no instructions, no retrieved context, no tool results.

The call (`Conversation\Gate\GateCall`) uses `ProviderFactory::chatProvider()` with the version's provider connection and model, the fixed English system prompt `InjectionGuard::SYSTEM_PROMPT`, no tools (a tool choice is cleared), no history and the provider's default sampling (the version's `temperature` and `maxTokens` do not apply). It calls `chat()`, never `structured()`, with one user message: the marker `InjectionGuard::MARKER` (`Message to check for prompt injection:`), a space and the user message. The reply must contain one JSON object `{"injection": true|false, "reason": "…"}`; code fences and text around the object are tolerated, and `injection` must be a boolean.

- **Refusal** (`injection` true): no retrieval (nothing is embedded) and no answer call. The turn stores the user record and one `injection_refusal` record (see [Records](#records)); the synchronous turn answers 201 with it, the streamed turn sends `sources` (empty), `gate` and `done` (see [Gates in the stream](#gates-in-the-stream)), and the agent chat answers 200 with `gate` (see [Agent chat](#agent-chat)).
- **Pass** (`injection` false): the turn continues; the outcome and the guard's tokens go onto the turn's final record.

#### Answerability

With `answerabilityCheck` on and at least one collection in the version, `Conversation\Gate\AnswerabilityCheck` asks the version's model, after retrieval and before the answer, whether the retrieved context suffices to answer the current question. Its input is the current user message (in the agent chat the `content` of the last `user` message), the previous user question (the last earlier user record of the conversation, refused turns excluded because they are never replayed; in the agent chat the `user` message before the last one), so that a follow-up such as "and the second one?" is read in context, and the chunks that would be sent to the answer (after the context cap and the expansion), numbered and with their source. No instructions, no assistant answers and no tool results go in. A version without collections makes no call; a version with collections whose retrieval finds nothing still runs the check, with the context `(none)`.

The calls use `GateCall` like the guard: the version's provider connection and model, the fixed English system prompt `AnswerabilityCheck::SYSTEM_PROMPT`, no tools, no history and the provider's default sampling, not the version's `temperature`. The user message is the preamble (`Previous user question: …` when there is one, then `Retrieved context:` with the chunks), then the marker `AnswerabilityCheck::MARKER` (`Question to check for answerability:`), a space and the current question, last; a fake-provider fixture keyed by the marker, a space and the question answers every vote of that question. The reply must contain one JSON object `{"answerable": true|false, "reason": "…"}`, with the same tolerance as the guard; `answerable` must be a boolean.

- **Votes.** `answerabilityVotes` calls run one after the other, always all of them (no early stop); the deadline and, in the streamed turn, the cancel flag are checked before each one.
- **Majority.** The check refuses only when more than half of the configured votes say unanswerable (2 of 3, 3 of 5, 4 of 7). A failed vote (provider error, unparsable verdict, skipped because the turn asked to stop) counts as answerable, so failures can only tip the check towards the answer; when every vote failed, the check failed open (see [Fail-open](#fail-open)).
- **Refusal:** no answer call. The turn stores the user record and one `unanswerable_refusal` record with the text of the slot `unanswerableRefusal` and `sources` `[]` (a refusal shows no sources: the retrieved chunks did not answer the question; see [Records](#records)); the synchronous turn answers 201 with it, the streamed turn sends `sources` (`[]`), `gate` with `gate` `answerability` and `done` (see [Gates in the stream](#gates-in-the-stream)), and the agent chat answers 200 with `gate` `{gate: "answerability", kind: "unanswerable_refusal", template: "unanswerableRefusal"}` and `sources` `[]` (see [Agent chat](#agent-chat)).
- **Pass:** the turn continues with the same context; the outcome and the check's tokens go onto the turn's final record.

The payload `providerPayload.gates.answerability` is `{verdict, reason, votes, failure?, usage}`: `votes` lists every vote as `{verdict, reason, failure?}` in call order (a failed vote as `pass` with its `failure` and reason null), `reason` is that of the first decided vote on the winning side, `failure` is present only when every vote failed, and `usage` sums the tokens of all votes. A refused turn counts like the guard's refusal (see [Accounting](#accounting)).

#### Follow-up suggestions

With `followUps` on, `Conversation\Gate\FollowUpGenerator` (the hook `TurnGates::afterAnswer()`, driven by `GateRun::finish()`) asks the version's model for at most three questions the user is likely to ask next. It runs after the answer and before the turn's records are built, and only on a complete text answer (`GateRun::isCompleteTextAnswer()`): not on a failed turn, a turn a rule decided or a gate refused, a terminal-tool answer, an answer with a response schema, a forced-close fallback or an empty answer. A forced answer of the tool-call bound or of the last allowed user turn is a text answer and gets suggestions.

The call is a `GateCall` like the guard's: the version's provider connection and model, the fixed English system prompt `FollowUpGenerator::SYSTEM_PROMPT`, no tools, no history and the provider's default sampling, one `chat()` call. Its user message is a preamble (the context chunks sent with the turn, only when there are any, the answer and the line `Language of the follow-up questions: <tag>` with the turn's effective language), a blank line, then the marker `FollowUpGenerator::MARKER` (`Question to suggest follow-up questions for:`), a space and the user message the answer replied to. The prompt asks for questions from the user's point of view in that language, answerable from the context when there is one. The reply must contain one JSON object `{"followUps": ["…", …]}`; code fences and text around the object are tolerated as for the guard.

The list is normalised by `FollowUpGenerator::normalise()`: entries that are not strings are dropped, each entry is trimmed and cut to 200 characters (`MAX_LENGTH`), empty entries and exact duplicates are dropped, and at most 3 (`MAX_FOLLOW_UPS`) are kept in the model's order.

The gate never refuses and never fails the turn. A provider error, a reply without a `followUps` list, or a stop before the call (a passed turn deadline or, in the streamed turn, a cancel request) gives `[]` with a failure note under `providerPayload.gates.followUps.failure` (`provider_error: <message>`, `unparsable_verdict`, `stopped` or `stopped: <message>`), and the answer stays `complete`. A list without a usable entry gives `[]` without a note.

- **Record.** The final record's `followUps` (see [Records](#records)): null when the gate did not run (off, or an ineligible turn), `[]` when it ran and failed or produced nothing, else the list. The synchronous turn returns it with the final record.
- **Stream.** `follow_ups` `{messageId, followUps}` after `frame` (if any) and directly before `done`, only for a `complete` final record with a non-empty list (see [Gates in the stream](#gates-in-the-stream)).
- **Agent chat.** `followUps` in the response, with the same null, `[]` and list semantics (see [Agent chat](#agent-chat)).
- **History.** The suggestions are never replayed into the history of later turns; a client that sends one as the next question sends an ordinary user message.
- **Tokens.** The call's tokens are listed as `usage.gates.followUps` and count like every gate's (see [Accounting](#accounting)), also when the call failed but the provider reported tokens.

#### Records, reason and usage

The refusal record is the turn's only assistant record: kind and `closeReason` `injection_refusal` or `unanswerable_refusal`, status `complete`, the refusal text in `content`, `sources` `[]` for both gates, no `frame`. It is never replayed into the history of later turns, so the model sees neither the refused input nor the refusal, and it is never framed. Both kinds fit the `VARCHAR(20)` columns `kind`, `close_reason` and the stream event `type` (`unanswerable_refusal` has exactly 20 characters); no migration.

The gate's reason is internal. It is stored only on the final record of every turn on which a gate ran (refused, passed or failed open), under `providerPayload.gates.<gate>` (`injection`, `answerability`, `followUps`) as `{verdict, reason, votes?, failure?, usage}`: `verdict` `pass` or `refuse`, `reason` the model's sentence or null, `votes` the single votes of a voting gate (the answerability check), `failure` the note of a gate that failed open, `usage` `{input_tokens, output_tokens}`. `providerPayload` is serialized only on `GET /messages/{id}` for holders of `neuronai:conversation:read_payload` (see [Permissions](#permissions)): never in `message:read` lists, the turn response, the stream, the agent chat response or for a capability token.

The final record's `usage` includes the gate tokens in `input_tokens` and `output_tokens` and lists them per gate under `usage.gates.<gate>` as `{input_tokens, output_tokens}`, so the record usages of a turn sum to the turn total. `done.usage` and the agent chat's `usage` have the same shape.

#### Accounting

Gate tokens are part of the turn total (`TurnResult::totalUsage()`) and count wherever the turn's tokens count: `Conversation.tokensUsed`, the anonymous token budget, `done.usage`, the agent chat's `usage`, the `tokensInput` and `tokensOutput` of an evaluation result (read from the final record, the per-gate share is not added twice) and the run's `tokensTotal`. A refused turn counts like a ruled one plus its gate tokens: `turnCount` +1, closing at `maxUserTurns` with `user_turn_limit` (without a `limit` record, see [User-turn limit](#user-turn-limit)) and at the anonymous turn and token limits with `turn_limit` and `token_limit`. A refused evaluation question is a `completed` result.

Each gate costs provider calls: the guard one per turn, sent before retrieval, so it also delays the first `token` of a streamed turn; the answerability check `answerabilityVotes` calls per turn, sent one after the other after retrieval, so they add up before the first `token`; the follow-up gate one per complete text answer, sent after the answer, so it delays `frame`, `follow_ups` and `done` of a streamed turn and the response of a synchronous turn or agent chat, but not the `token` deltas. A turn with all three gates on makes up to 1 + 7 + 1 extra calls (guard, seven answerability votes, follow-ups).

#### Fail-open

A gate that cannot decide lets the turn continue as if it had passed: a provider error, a reply without a usable verdict, or a gate call skipped because the turn asked to stop. The failure is logged at warning level (`Answer gate "<gate>" failed open: <failure>`, with provider, connection, model and the first 500 characters of an unusable reply) and noted in the payload as `failure`: `provider_error: <message>`, `unparsable_verdict` or `stopped`. Tokens the provider reported for a failed call still count. The guard therefore protects nothing while its provider is unreachable or answers in another shape.

#### Deadline and cancel

The gate calls of a turn share the turn deadline `turn.deadline_seconds`, counted from the start of the turn's gate run, not from the first gate call. Before every gate call the deadline is checked and, in the streamed turn, the cancel flag: a cancel request ends the turn as `aborted` (`done` with `status: "aborted"`), a passed deadline as an `error` turn, as in the run; in the agent chat a passed deadline fails the call like a deadline of the run. The follow-up gate is the exception: it runs after a complete answer, so a cancel request or a passed deadline before its call gives `followUps` `[]` with the failure `stopped` (see [Follow-up suggestions](#follow-up-suggestions)) and the answer stays. A gate call in flight is not interrupted; its HTTP timeout is that of every provider call. The answer run keeps its own deadline observer, started when the run starts, so a turn with gates can last up to the time of its gate calls plus the deadline.

#### Paths

| Path | Gates |
|---|---|
| `POST /conversations/{id}/messages` of an agent conversation | run; a refusal answers 201 with the refusal record |
| `POST /conversations/{id}/stream` of an agent conversation | run inside the streamed turn; a refusal sends `sources`, `gate`, `done` |
| anonymous conversation with a released agent | as the two routes above; the anonymous limits count the gate tokens |
| `POST /agents/{id}/chat` | run; a refusal answers 200 with `gate`; nothing stored |
| `Chat\AgentRunner` without a request | run unless the options set `gates: false`; a refusal returns the result with `gate`, as the agent chat |
| evaluation batch run, single execution, conversation replay | run (they use the synchronous or streamed turn, see [Evaluation](#evaluation)) |
| conversations without an agent, `/chat`, the judge, retrieval-only runs and the retrieval measurement of a run | no gates |

#### Answer gate limitations

- **No claim about effectiveness.** The guard is the version's own model classifying one message with a fixed prompt. Its detection and false-positive rates are not measured on any model, and a determined attacker may phrase an injection that passes; it is a filter, not a security boundary. A false positive refuses an ordinary question; the refusal text is the only feedback the user gets.
- **The user message only.** Injections spread over several turns, hidden in retrieved documents or in tool results (indirect injection) are not checked.
- **Fail-open.** A provider error or an unparsable verdict disables the guard for that turn (see [Fail-open](#fail-open)).
- **English prompt.** The system prompt of the guard is English and not configurable per version; the model's reason is free text in whatever language it chooses and may quote the user message, so `providerPayload` can hold personal data from the input.
- **Answerability sees one question back.** The check reads the current and the previous user question and the retrieved chunks only. An answer that relies on earlier history, on the previous assistant answer or on tool results (MCP) is refused falsely when the chunks do not carry it; a version whose answers come mainly from tools should keep the check off.
- **Empty retrieval usually refuses.** A version with collections whose retrieval finds nothing still runs the check, with the context `(none)`, and the model then mostly votes unanswerable, even for small talk or questions the instructions would cover.
- **Answerability cost.** Up to seven extra sequential calls per turn on the version's model, before the answer; the votes use the provider's default sampling, so a single vote (`answerabilityVotes` 1) can flip between identical turns.
- **Unchecked suggestions.** The follow-up questions are the model's proposal; whether the context can answer them is not verified, and the system prompt is English and not configurable per version.
- **No gate counts.** Evaluation runs do not aggregate refusals per gate; read them from the `closeReason` of the result conversations' final records.

### Limit defaults

`Agent\LimitResolver::resolve(?int $version, ?int $settingsDefault, ?int $bundleDefault, ?int $request): ?int` is a pure function for the limits of an agent conversation (`maxToolCalls`, `maxUserTurns`): the base is the first non-null of version, application default and bundle default; a request may lower the base but never raise it (`min(base, request)`); without a base the request applies, and with all four null the limit is null (unbounded). The application default comes from `Agent\AgentLimitDefaultsInterface` (`maxToolCalls(): ?int`, `maxUserTurns(): ?int`), which the host application implements (for example from runtime settings) and aliases; the bundle treats a missing alias as "no application default". The bundle default for `maxToolCalls` is `turn.max_tool_calls_default`; there is none for `maxUserTurns`. The resolver applies to conversations created with an agent: `maxUserTurns` once at creation (the result is stored on the conversation), `maxToolCalls` at each turn. Conversations without an agent do not use it: their request values apply as given, and the settings defaults are ignored.

### Where the version fields act

Every field of a version has a runtime effect. `Agent\VersionTurnMapper` maps a version for the [agent chat](#agent-chat) and for every turn of an agent conversation (see [Turns of an agent conversation](#turns-of-an-agent-conversation)):

- `instructions`, `languages`, `defaultLanguage` — the system prompt of the effective language, with the placeholders filled (see [Placeholders and variables](#placeholders-and-variables)); the language of a conversation is validated at creation.
- `startSchema` — validates the caller's `variables` (at the creation of a conversation and at every agent chat).
- `model`, `collections`, `retrievalDepth`, `responseSchema` — the turn's provider call (the version's catalogue entry and its provider connection, both resolved from the database on every turn, without a live model check and without the entry's `available` flag; the call uses the entry's name), retrieval and structured answer.
- `hybrid`, `hybridWeight` — whether the turn's retrieval fuses keyword and vector search, and with which weight (see [Hybrid search](#hybrid-search)); the evaluation measurement of the version uses the same setting.
- `contextMaxChunks`, `contextMaxChars`, `contextExpansionMaxChars`, `contextInstructions` — the cap and the neighbour expansion of the context sent and the instruction text in front of it, on the synchronous and the streamed turn and `agent_chat` alike (see [Context shaping](#context-shaping)); the evaluation measurement ignores them, its pool is uncapped and unexpanded.
- `mcpTools` with `terminal` — the tool subset offered to the turn and its terminal tools.
- `modelOptions` — applied to every provider call of a turn on the version (see [Model options](#model-options)); wired, not verified against live providers. A missing option leaves the provider's default (Anthropic `max_tokens`: the connection's `max_tokens`, default 8192; Ollama `num_ctx`: the model's Modelfile value, else the server default). The answer gates and the judge call the provider with its defaults.
- `maxToolCalls` and the application default `max_tool_calls` — the bound of each turn; the request may only lower it.
- `maxUserTurns` and the application default `max_user_turns` — resolved once at the creation of a conversation; the agent chat has no user-turn limit.
- `rules`, `templates` — the pre-answer gate of every turn and of the agent chat, with the template texts of its answers and refusals (see [Pre-answer rules and templates](#pre-answer-rules-and-templates)); the reserved slots of `templates` frame the final answer, replace the limit texts and answer a failed or empty forced close (see [Output templates](#output-templates)), and `injectionRefusal` and `unanswerableRefusal` are the refusal texts of the answer gates.
- `injectionGuard` — the prompt-injection guard of every turn and of the agent chat, before retrieval (see [Answer gates](#answer-gates)).
- `answerabilityCheck`, `answerabilityVotes` — the answerability check of every turn and of the agent chat of a version with collections, after retrieval and before the answer (see [Answerability](#answerability)).
- `followUps` — the follow-up suggestions after a complete text answer of every turn and of the agent chat (see [Follow-up suggestions](#follow-up-suggestions)). The judge never runs any of the three gates, and neither does the retrieval measurement of an evaluation run.

`enabled` (a field of the agent, not of the version) gates the creation of agent conversations and the agent chat with 422, and the anonymous creation with a released agent with 503; running conversations are not affected.

### Agent application wiring

- Firewall: `agents|agent_versions` in the pattern of the protected api firewall, with `access_control` requiring `IS_AUTHENTICATED_FULLY`; without it the routes are not behind a login.
- Access control: `{ path: ^/api, roles: IS_AUTHENTICATED_FULLY }`; the operations check their `neuronai:agent:*` permissions themselves.
- Permission map: the `neuronai:agent:*` and `neuronai:mcp_connection:use_restricted` entries of `za7_core.permissions`.
- Optional: an alias of `Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface` to the app's limit defaults.
- Optional: an alias of `Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewRetentionInterface` to the host's preview retention; without it previews are never purged. The scheduled purge also needs a worker consuming `scheduler_neuron`, as for anonymous conversations; for `neuron:agent:previews:purge --async` the host routes `Agent\Preview\PurgeAgentPreviewsMessage` to a transport that worker consumes (see [Preview versions](#preview-versions)).
- Optional: an alias of `Dmstr\NeuronAiBundle\Retrieval\NeighbourSourceInterface` to the app's neighbour lookup; without it the neighbour expansion is a no-op (see [Neighbour expansion](#neighbour-expansion)).

### Agent limitations

- **A pinned version is final.** An agent conversation never moves to a newer version; to use a newly published version a client starts a new conversation. Its `variables` and `language` cannot be changed after the creation either.
- **Model options are not verified live.** `modelOptions` reach the provider request (proven against request bodies), not verified against live Anthropic or Ollama runs. Thinking on Anthropic (`thinkingDisplay`) combined with a forced tool choice (the tool-call bound, terminal tools) is untested; Anthropic rejects forced tool use with thinking on some models.
- **Sampling rule table.** Whether a Claude model takes sampling comes from `Provider\AnthropicSamplingSupport`, because the Models API does not report it; a new model line needs an entry there.
- **`{{date}}` follows the clock.** The date is evaluated per turn in UTC, not fixed at the creation of the conversation.
- **Candidate replaced as a whole.** `PATCH` replaces the JSON column `candidate`; the stored candidate holds only the keys a client sent, and missing keys count as their defaults at publish.
- **No delete.** Neither agents nor published versions can be deleted; deleting an agent needs a decision about conversations that point to its versions. Previews are deleted only by the [purge](#preview-versions), not through the API.
- **The frame is not streamed.** The `token` deltas carry the unframed answer; a client that renders only them never shows the frame (see [Frame in the stream](#frame-in-the-stream)).
- **A fallback answer is not replayed.** A turn answered by the forced-close fallback is left out of the history of later turns, so the model does not see that it gave no answer of its own.
- **The forcing instructions stay English.** The closing instructions the modes `tool_choice` and `fallback` append to the system prompt (`ForcedClose::INSTRUCTION`, `ForcedClose::TURN_LIMIT_INSTRUCTION`) are not templated.
- **Rules see the user input only.** A rule cannot look at the history or at the retrieved context, and keywords use simple case folding (`ß` and `SS` stay different). A regular expression is bounded by the runtime's PCRE limits, not by a timeout.
- **Stale references.** A version keeps the references it was published with; nothing re-checks them until a turn uses them. Then the turn (or the agent chat) answers 422 with the path of the reference, and the conversation stays unusable until the referenced object exists again: its version cannot be replaced.

## Evaluation

A maintained test set runs as a batch against a published agent version, or against a [preview](#preview-versions) of an agent's candidate before it is published; every answer is a real conversation, an AI judge (itself a platform agent) gives a structured verdict with typed improvement suggestions, and reviewers rate results against a schema the operator defines. Paths below are relative to the app's `/api` prefix. Neuron AI's own evaluation module is not used: it is a dataset runner for the CLI whose judge returns a score and a reasoning, whereas the judge here is a published agent version called through the turn machinery, and its answer is checked against the bundle's own verdict schema.

### Evaluation records

| Record | Table | Content |
|---|---|---|
| `Entity\EvalQuestion` | `neuron_eval_question` | `key` (unique, `^[a-z0-9][a-z0-9._-]{0,99}$`, the upsert key of the import), `question` (the user message of the first turn), `expectedAnswer`, `requestData` (JSON object or null, the start data: passed as the `variables` of the evaluation conversation, see [Batch runs](#batch-runs)), `status` (`active` takes part in new runs, `archived` is kept for its results); `markers` (null or a list of strings, the expected retrieval, see [Retrieval metrics](#retrieval-metrics)) and `negative` (a question that should find nothing; a stored null counts as false), both maintained by the import or through `POST` and `PATCH /eval_questions`, with the same rules (`Validator\EvalQuestionRetrievalRules`) |
| `Entity\EvalRun` | `neuron_eval_run` | `agentVersion` (the version that ran, published or preview, `ON DELETE RESTRICT`; the preview purge deletes the runs of a preview first), `ownerSubject` (who started it; owns the evaluation conversations), `status` (`pending`, `running`, `completed`, `cancelled`, `failed`), `jobId`, `cancelRequestedAt`, `mode` (`retrieval` or `agent`, see [Batch runs](#batch-runs); null for runs started before the mode existed), `retrievalOnly` (set by the start, default false, see [Retrieval-only runs](#batch-runs)), the aggregates `questionsTotal`, `tokensTotal`, `interactionsTotal`, `verdictCounts` and the retrieval aggregates `recall`, `mrr`, `questionsMeasured`, `questionsNegative`, `questionsUnmeasured`, `questionsRetrievalFailed` (see [Run aggregates](#run-aggregates)), `startedAt`, `finishedAt` |
| `Entity\EvalResult` | `neuron_eval_result` | `evalRun` (null for a single execution), `evalQuestion`, `conversation` (the evaluation conversation, set to null when it is deleted, always null in a retrieval-only run; the answer is its final assistant record, not a copy), `source` (`batch` or `single`), `status` (`pending`, `completed`, `failed`), `errorMessage`, the measurements of the turn `durationMs`, `tokensInput`, `tokensOutput`, `interactions` (tool calls; in a retrieval-only run `durationMs` is the search time and the other three are null), `evaluation` (the latest judge evaluation), `humanRating`, `humanRatedBy`, `humanRatedAt`, `questionFingerprint` (sha256 hex of question text, a NUL byte and expected answer when the result was recorded; null for results recorded before the column existed, see [Run comparison](#run-comparison)), and the retrieval measurement `retrievalStatus` (`measured`, `negative`, `unmeasured`, `failed`; null in mode `agent`, for single executions and for results written before the measurement existed), `recallHit` and `firstRelevantRank` (both set only for `measured`, the rank null on a miss; see [Retrieval metrics](#retrieval-metrics)). Unique on (`eval_run_id`, `eval_question_id`), removed with its run |
| `Entity\Evaluation` | `neuron_evaluation` | one judgement of a result: `evalResult`, `status` (`pending`, `completed`, `failed`), `verdict` (`pass`, `mostly_pass`, `mostly_fail`, `fail`), `reasoning`, `judgeAgentVersion`, `model` (the IRI of the judge version's model catalogue entry, column `provider_model_id`, `ON DELETE SET NULL`), `modelName` (the name of the judge call), `tokensInput`, `tokensOutput`, `durationMs`, `errorMessage`, `jobId`, `suggestions`, `finishedAt`; a result may be evaluated several times, earlier evaluations stay reachable through `GET /evaluations?evalResult=…` |
| `Entity\Suggestion` | `neuron_suggestion` | one improvement of an evaluation: `type` (`data`: the knowledge base lacks or misstates something; `prompt`: the agent's instructions should change), `category`, `description`, `action`, `priority` (`low`, `medium`, `high`), and the backlog fields `status` (`open`, `fixed`, `wontfix`) and `assignee` (free text); removed with its evaluation |

All five are API Platform resources. Every field the batch or the judge writes is read-only; `EvalQuestion` is edited with `neuronai:eval:write` (`POST`, `PATCH`, `DELETE`; a question with results cannot be deleted, archive it instead), `Suggestion` only in `status` and `assignee`.

### Importing questions

`bin/console neuron:eval:questions:import <file>` reads a JSON list:

```json
[
    {"key": "support.opening-hours", "question": "When are you open?", "expectedAnswer": "Monday to Friday, 8 to 17.", "markers": ["opening hours", "8 to 17"]},
    {"key": "support.weather", "question": "Will it rain tomorrow?", "expectedAnswer": "The assistant has no weather information.", "negative": true},
    {"key": "support.old-question", "question": "…", "expectedAnswer": "…", "status": "archived", "requestData": {"machine": "X1"}}
]
```

`key`, `question` and `expectedAnswer` are required non-empty strings, `requestData` is an object or null, `status` is `active` or `archived`; other properties are errors. The two retrieval fields (see [Retrieval metrics](#retrieval-metrics)) are optional:

- **`markers`** is null or a non-empty list of at most 50 strings. Each marker is trimmed and must then be non-empty, at most 200 characters long and unique within the question; the trimmed markers are stored. An empty list is an error: omit the property or use null for a question without markers.
- **`negative`** is a boolean and marks a question that should find nothing in the knowledge base, for example one outside its subject. `"negative": true` together with markers that are not null is an error, because such a question has nothing to look for.

The whole file is validated first (JSON syntax, shape, types, key pattern, duplicate keys within the file, the retrieval fields): any problem prints every problem with the zero-based item index, writes nothing and exits with 1. A valid file is upserted by `key` in one transaction: missing questions are created, changed `question`, `expectedAnswer`, `requestData`, `status`, `markers` or `negative` are updated, the rest is counted as unchanged, and the command prints `created: N, updated: N, unchanged: N`. Importing the same file again therefore reports `created: 0` and every question unchanged. The file is authoritative for `requestData`, `markers` and `negative`: a missing `requestData` or `markers` means null and a missing `negative` means false, so removing markers from the file removes them from the question on the next import. A stored null in `negative` (questions imported before the field was used) equals false and is not counted as a change. A missing `status` means `active` for a new question and leaves an existing one's status alone. Questions missing from the file are not touched; archive them with `"status": "archived"`. Only JSON is supported.

`POST` and `PATCH /eval_questions` apply the same rules to `markers` and `negative` (`Validator\EvalQuestionRetrievalRules`, through the entity constraint `Validator\EvalQuestionRetrieval`) and store the trimmed markers. A violation answers 422 on `markers`, or on `negative` for the conflict of `negative: true` with markers; a `PATCH` that adds markers to a stored negative question gets the conflict on `markers`. `markers: null` clears the markers, and a `PATCH` without the two keys keeps them. A `negative` that is not a boolean answers 422.

### Batch runs

`POST /eval_runs/start` (operation and job type `eval_run_start`, `neuronai:eval:write`) takes `{"agentVersion": "<IRI /api/agent_versions/<uuid> or full UUID>", "mode": "retrieval", "retrievalOnly": false}` (schema `Entity/EvalRun/start.input.json`; 400 for malformed JSON, 422 for a missing `agentVersion` or another property). The optional `mode` selects the metric set: `retrieval` measures every question's retrieval (see [Retrieval metrics](#retrieval-metrics)), `agent` measures nothing beyond the turn. When it is omitted, it is derived from the version: `retrieval` when its configuration has knowledge collections, otherwise `agent`. The run always stores the mode, so a run started without one still shows which metric set applied. The optional boolean `retrievalOnly` (default `false`) starts a retrieval-only run, see below; it implies mode `retrieval` and is stored read-only as `retrievalOnly` on the run. The start answers 422, before a run or job exists, for an unknown version, a disabled agent, a published version that is not the agent's current version, for `"mode": "retrieval"` or `"retrievalOnly": true` on a version without knowledge collections, for `"retrievalOnly": true` together with `"mode": "agent"` and when no question is `active`. Otherwise it stores the run (`pending`, `ownerSubject` = the caller, the mode, the retrieval-only flag, `questionsTotal` = the active questions at that moment, set again by the job, see [Run aggregates](#run-aggregates)), queues a job of the job queue bundle with the input `{"run", "ownerSubject"}` and answers 202 with the run and its `jobId`.

**Runs on a candidate.** Instead of `agentVersion` the body may name `{"agent": "<IRI /api/agents/<uuid>, full UUID or slug>", "candidate": true}`: the agent's current candidate is validated like a publish (422 at `candidate/<field>`), frozen as a [preview](#preview-versions) or the existing preview of the unchanged candidate is reused, and the run is created on it; its `agentVersion` is the preview. An unknown or disabled agent answers 422, a concurrent preview creation 409. A preview can also be given directly as `agentVersion`, for example to run the same preview again after the candidate changed: the current-version rule applies to published versions only, so any preview of an enabled agent is startable. Giving both ways in one body (`agentVersion` together with `agent` or `candidate`) answers 422 naming the keys, and `candidate: false` is a schema violation (use `agentVersion` for a published version). The start needs `neuronai:eval:write` only, not the agent permissions. Mode, `retrievalOnly` and every check after the version work the same for previews; their messages name the version as `preview <hash8>` instead of `version <n>`. The preview is created before those checks, so a start refused by them (for example because no question is active) leaves the preview behind, where the next start of the unchanged candidate reuses it or the purge removes it. Runs on a preview are deleted with the preview by the purge, results and ratings included, unless they are still `pending` or `running`.

The job (`JobProcessor\EvalRunJobProcessor` with `Evaluation\BatchRunner`) sets the run `running`, takes its question list and executes the active questions in the order `createdAt`, `key`. Per question it creates an evaluation conversation and runs one turn with the question text through the same `ConversationTurnService::turn()` as `POST /conversations/{id}/messages`: the conversation is owned by the run's `ownerSubject`, pinned to the run's version exactly like a creation with an agent (the question's `requestData` as variables, see below, and the default language; see [Creation with an agent](#creation-with-an-agent)) and has `origin` `eval`. The result is `completed` when the final record is `complete` (also a question the version's [prompt-injection guard](#prompt-injection-guard) refused, like one a rule refused; its gate tokens count in the result's tokens and the run's `tokensTotal`), otherwise `failed` with the record's error; an exception of the creation or the turn (stale reference, lock, gate, refused variables) is a `failed` result too. A failing question never stops the run. In mode `retrieval` the batch measures the question's retrieval after its turn, see below. After every question the run's `tokensTotal` and `interactionsTotal` grow and the job writes `progress` (percent of the questions with a result) and `resultData` (`{completed, failed, skipped, failures: [{question, error}]}`). At the end the run is `completed`.

**Start data of evaluation questions (`requestData`).** An evaluation run and `POST /eval_questions/{id}/execute` pass the question's `requestData` as the `variables` of the evaluation conversation, checked against the pinned version's `startSchema` exactly as `POST /conversations` with `agent` and `variables` checks them (see [Placeholders and variables](#placeholders-and-variables)): system value names (`date`, `language`, `agentName`) cannot be supplied, values must be strings, numbers or booleans, every name must be declared by the `startSchema` (a version without `startSchema` declares none), the object must validate against it (`required` included), and every placeholder of the instructions needs a value. `requestData` must be a JSON object; null or `{}` means no variables. A refusal fails only that question: in a run the result is `failed` with the 422 detail as `errorMessage` (e.g. `variables/vehicle: not declared by the agent version.`) and without conversation, and the run continues and completes; `execute` stores the same `failed` single result and answers 422 before the stream (see [Single execution](#single-execution)). Retrieval-only runs create no conversation and render no instructions, so they neither use nor check `requestData`.

- **Cancel.** `POST /eval_runs/{id}/cancel` (`eval_run_cancel`, `neuronai:eval:write`, no body) sets `cancelRequestedAt` (a second request keeps the first time) and answers 200 with the run; 422 when the run has already ended. The job reads the flag from the database before each question and ends the run `cancelled`; the remaining questions get no result.
- **Redelivery.** A redelivered job of a `pending` or `running` run resumes it: questions that already have a result in the run are skipped (counted in `resultData.skipped` and in `progress` from the job's first write on, so a redelivery that finds every question answered ends at 100), and the unique index on run and question guards the rest. A job for a run that has already ended, or that belongs to another job, is a no-op.
- **Failure of the job.** Only a vanished run or version fails the job; anything else that escapes the batch (a database error outside a question) ends the run `failed` and is rethrown. When that error has closed the entity manager, the final status is written through DBAL (only while the run is still `pending` or `running`), so no run is left without a final status. A database error inside a turn closes the entity manager; the batch resets it and continues with the next question.

**Retrieval measurement.** In mode `retrieval` the batch repeats the turn's retrieval search after each question's turn, whether the turn succeeded or failed, with the question text as query: `ContextRetriever::pool()` over the version's collections at its retrieval depth and with its hybrid setting (`hybrid`, `hybridWeight`), all resolved through `VersionTurnMapper::retrievalScope()` (`Agent\RetrievalScope`) exactly as the turn resolves them. The pool is classified onto the result as `retrievalStatus`, `recallHit` and `firstRelevantRank`. The conversation and its `sources` stay untouched. The measurement also searches when a pre-answer rule decided the turn or an [answer gate](#answer-gates) refused it before retrieval and the turn itself searched nothing, because the pool is defined independently of the answer; the measurement runs no gate. A search that throws, for example because a collection was deleted or the embedding provider failed, makes the measurement `failed` and is logged; the result keeps the turn's status. Mode `agent`, and runs without a mode, measure nothing.

The measurement costs one query embedding per distinct embedding model of the version's collections and no chat call. A normal question in mode `retrieval` therefore makes twice the turn's embedding calls, a question decided by a pre-answer rule or refused by the prompt-injection guard once (only the measurement searches). Mode `agent` makes no extra call.

**Retrieval-only runs.** A run started with `"retrievalOnly": true` measures retrieval without answering. Per question it runs only the search of the measurement above (`ContextRetriever::loadCollections()` and `pool()`, uncapped, with the version's collections, retrieval depth and hybrid setting) and its classification: no conversation, no system actor, no turn, no chat or stream call, and therefore no pre-answer rule and no gate of the turn. The result records the search: `durationMs` is the search time (including loading the collections, when that happens for this question), `tokensInput`, `tokensOutput`, `interactions` and `conversation` stay null, so the run's `tokensTotal` and `interactionsTotal` do not grow. The result is `completed` with its `retrievalStatus`, negative and unmeasured questions included; a search that throws makes the result `failed` with the exception message as `errorMessage` and `retrievalStatus` `failed`, and the run continues. Order, cancel, redelivery, `questionsTotal`, the question fingerprint, the checkpoint, the job processor and the refresh of the aggregates are the same as for every other run. A retrieval-only question costs one query embedding per distinct embedding model of the version's collections and no chat call, half the embedding calls of a normal question in mode `retrieval`; with `hybrid` on the keyword search adds no embedding. Its results cannot be judged (see [The judge](#the-judge)) and have no answer to rate (see [Human rating](#human-rating)).

### Retrieval metrics

A run in mode `retrieval` measures whether the knowledge search finds what a question needs, separately from the answer, so a poor answer can be traced either to a search that missed the relevant chunk or to an answer that used it badly. The definitions:

- **Pool.** The measured pool is the uncapped search result of the question: one query embedding per distinct embedding model, one store query per collection, merged by score descending and cut to the retrieval depth after the merge, not per collection. With the version's `hybrid` on, it is the fused list of [hybrid search](#hybrid-search) instead, cut to the depth after the fusion. The depth is the version's `retrievalDepth`, else `store.top_k` (4). The turn uses the same list as its context, capped and then expanded (`ContextRetriever::retrieve()` is `expand(cap(pool()))`, see [Context shaping](#context-shaping)), so production and measurement share the search, but the pool is neither the capped nor the expanded context the model was sent: a marker that only a neighbour passage contains stays a miss.
- **Relevance.** A chunk is relevant when its content contains at least one of the question's markers, compared case-insensitively with `mb_stripos()`. Only the chunk content counts; file name, source URL and heading path never do.
- **Rank.** `firstRelevantRank` is the 1-based position of the first relevant chunk in the pool, and `recallHit` says whether there is one at all.

Every result of a run in mode `retrieval` gets one `retrievalStatus`:

| Status | Question | `recallHit`, `firstRelevantRank` |
|---|---|---|
| `measured` | has markers | `true` and the rank on a hit, `false` and null on a miss |
| `negative` | `negative` is true; counted, but not part of recall or MRR, its refusal is judged by the AI judge | null |
| `unmeasured` | neither markers nor `negative`; counted, but not part of recall or MRR | null |
| `failed` | the repeated search threw (in a retrieval-only run the result itself is `failed` too) | null |

The run aggregates over these statuses are described in [Run aggregates](#run-aggregates).

**Comparing retrieval settings.** To decide whether a setting such as `hybrid` helps a corpus, publish two versions that differ only in that setting (same collections, same depth), start a retrieval-only run on each over the same active question set, and compare `recall` and `mrr` of the runs and `recallHit` and `firstRelevantRank` per question key. Retrieval-only runs cost no chat call and need no judge, and the measurement uses exactly the hybrid setting the turn would use. The run comparison does not compare retrieval metrics (see [Evaluation limitations](#evaluation-limitations)); read them from the two runs.

### Evaluation conversations

A retrieval-only run creates no conversation. Conversations of every other run carry `origin` `eval` (column `origin`, indexed, set by the bundle only). `Doctrine\OwnedConversationExtension` hides every conversation with an origin from `GET /conversations` and `GET /messages`, also for holders of `neuronai:conversation:read_any`, unless the request filters on it: `GET /conversations?origin=eval` lists the caller's own evaluation conversations, and every evaluation conversation for holders of `neuronai:conversation:read_eval`. The voter grants `neuronai:conversation:read_own` on an evaluation conversation to holders of the mappable attribute `neuronai:conversation:read_eval` (mapped to roles by the application, see [Permissions](#permissions)), whoever owns it, so a reviewer can follow a result's `conversation` IRI and read its messages. `neuronai:conversation:turn_own` stays owner-only, and a capability token never reads an evaluation conversation.

Replay conversations (`origin` `replay`, see [Conversation replay](#conversation-replay)) follow exactly the same rules: hidden from the lists unless `?origin=replay`, listed with that filter for their owner and for every holder of `neuronai:conversation:read_eval`, and readable by those holders, messages included. `neuronai:conversation:read_eval` covers both origins (`Entity\Conversation::EVALUATION_ORIGINS`); a list filtered on several origins at once (`?origin[]=eval&origin[]=replay`) is restricted to the caller's own conversations.

### Single execution

`POST /eval_questions/{id}/execute` (`neuronai:eval:write`, body `{"agentVersion": …}` or `{"agent": …, "candidate": true}` validated against its own schema `Entity/EvalQuestion/execute.input.json`, operation `eval_question_execute`, which accepts nothing else, so run options such as `mode` are rejected with 422; the version is resolved exactly as at run start, so a preview of an enabled agent is accepted as `agentVersion`, and `candidate: true` executes on the preview of the agent's candidate, see [Batch runs](#batch-runs)) executes one question outside a run and streams its first turn with the same Server-Sent Events as `POST /conversations/{id}/stream` (see [Streaming](#streaming)). It is a plain Symfony route in the bundle's `config/routes.php`, not an API Platform operation, so it is not in the OpenAPI document; the reverse proxy must route `^/api/eval_questions/[^/]+/execute$` to the stream pool, like the conversation stream. Before the stream starts it answers as a JSON problem document: 403 without the role, 404 for an unknown question, 400/422 for the body, 422 for an archived question and for a version that is not startable as at run start, 422 or 409 from pinning and the turn's preflight. The conversation is pinned with the question's `requestData` as variables, checked like in a run (see [Batch runs](#batch-runs)); a refusal stores a `failed` single result (`evalRun` null, `source` `single`, the 422 detail as `errorMessage`, no conversation) and answers 422 with the same detail before the stream. The caller owns the new evaluation conversation, and the `EvalResult` (`evalRun` null, `source` `single`) is stored `pending` with it and finalised after the stream (`completed` or `failed`, duration, tokens, tool calls). Unlike the batch, the audit actor is the calling user. The version's [answer gates](#answer-gates) run as in every streamed turn; a question the guard refused streams `sources`, `gate`, `done` and its result is `completed`. A single execution is never measured: its result has `retrievalStatus` null.

### The judge

`POST /eval_results/{id}/evaluate` (operation and job type `eval_result_evaluate`, `neuronai:eval:write`, no body) answers 422 for any result of a retrieval-only run, whatever its status (`Retrieval-only result, nothing to judge: the run measured retrieval without an answer.`, checked first, so it is not reported as a missing conversation), when the result is not `completed`, when its conversation was deleted, and when the judge cannot run (the setting is empty or names an unknown, disabled or unpublished agent; the detail names the setting). Otherwise it stores a `pending` evaluation as the result's `evaluation`, queues the job and answers 202 with the evaluation and its `jobId`.

The judge (`Evaluation\Judge`) is the current published version of the agent named by the setting `evaluation.judge_agent` (slug, UUID or IRI), resolved again when the job runs. Its version runs once on the [agent runner](#running-an-agent-without-a-request) (`Chat\AgentRunner::execute()` with `rules` and `gates` off): mapped like every agent turn (`VersionTurnMapper`, default language, no variables), without the version's pre-answer rules, and called through `ChatService::chatConfigured()` with one user message: the question, the expected answer, a plain-text transcript of the result's conversation (user messages, assistant answers, tool calls with input and result, errors; reasoning and `limit` records left out) and the verdict schema with the instruction to answer only with JSON. The answer is checked in two steps: `schema_errors` of the version's own `responseSchema`, if it has one, and then the bundle's verdict schema `src/Evaluation/verdict.schema.json` (JSON Schema 2020-12: `verdict` one of the four values, `reasoning`, `suggestions[]` with `type` `data`/`prompt`, `category` of at most 100 characters, `description`, `action`, `priority` `low`/`medium`/`high`, no further properties), whatever the version declares. Code fences around the JSON are stripped. The runtime check is the guarantee; the settings bundle cannot check at save time that the named agent answers in this shape.

The judge call runs without [answer gates](#answer-gates) (`AgentRunOptions` with `gates: false`, passed on to `chatConfigured()`), even when the judge version has them on: the transcript under judgement contains the evaluated user input and would trip the injection guard. In the transcript a gate refusal record (`injection_refusal`, `unanswerable_refusal`) is its refusal text followed by `[refused by the <gate> gate: <reason>]` (`[refused by the <gate> gate]` without a reason), with the reason read from `providerPayload.gates`, so the judge can tell a refusal from a failed answer. The reason reaches only the judge's provider, never `message:read`.

Outcomes, written with one flush under the audit actor `system:neuron:eval`:

- **Valid answer:** the evaluation becomes `completed` with `verdict`, `reasoning`, `judgeAgentVersion`, `model`, tokens (from the provider's usage, null without one), `durationMs`, `finishedAt`, and one `Suggestion` (`status` `open`) per entry.
- **Failure** (the judge is not runnable any more, the provider call fails, `schema_errors`, invalid JSON or a verdict-schema violation): the evaluation becomes `failed` with `errorMessage` (at most 2000 characters, the schema errors as JSON pointer => message) and no suggestion; the result keeps everything else.
- A redelivered job for an evaluation that is no longer `pending` is a no-op; only a vanished evaluation fails the job.

Suggestions form the backlog: `GET /suggestions` (filters `evaluation`, `type`, `status`, `assignee`, `priority`) for `neuronai:eval:read`, `PATCH /suggestions/{id}` (`application/merge-patch+json`) for `neuronai:eval:write` changes `status` (`open`, `fixed`, `wontfix`) and `assignee` (at most 255 characters, null to unassign). Every other property of the body is ignored, not rejected, so the judge's suggestion itself cannot be changed.

### Human rating

`POST /eval_results/{id}/rate` (`eval_result_rate`, `neuronai:eval:rate`) takes the rating document itself as the body, a JSON object whose shape the operator defines with the JSON Schema of the setting `evaluation.human_rating_schema` (default draft 2020-12). A valid rating replaces the previous one as `humanRating`, with `humanRatedBy` (the reviewer's user identifier) and `humanRatedAt`, and the answer is the result (200). Errors: 400 for malformed JSON or a body that is not an object; 422 naming the setting when it is empty or not a valid JSON Schema; 422 with one violation per validation error when the rating does not match, `propertyPath` being the JSON pointer of the offending value (an extra property under `additionalProperties: false` is reported at its own pointer, e.g. `/extra`). The schema is read on every request; stored ratings are not revalidated after a change.

**Results of retrieval-only runs.** Such a result has no conversation and no answer. The rate operation does not refuse it, but a reviewer has only the retrieval measurement to look at, and feedback (`POST /feedback`) cannot be given on it at all, because feedback needs a conversation (see [Feedback](#feedback)).

**Operators must send the schema whole.** The settings `PATCH` of a section is a JSON merge patch applied key by key, also inside `human_rating_schema`: keys missing from the patch are kept, and `null` removes a key. A partial patch therefore merges into the old schema instead of replacing it; to replace a schema, send `null` first and then the whole new schema. `null` values and empty objects inside a schema cannot be stored faithfully.

**Comparing ratings.** Which property of the rating counts in a [run comparison](#run-comparison), and in which direction, is the separate setting `evaluation.human_rating_comparison`; without it the comparison only reports whether a rating changed.

### Run aggregates

`questionsTotal` is a progress figure. The start stores the number of active questions at that moment; the job sets it again when it takes its question list, to the number of active questions plus the questions already answered in this run that are no longer active. The job's `progress` therefore stays truthful on a redelivery and when questions are archived or activated between start and job, and answered questions that are no longer active are reported as skipped. Before retrieval metrics existed, `questionsTotal` stayed the count at the start. `tokensTotal` (input plus output tokens) and `interactionsTotal` (tool calls) are the sums over the question turns of the run, maintained by the batch; the judge's tokens are on each `Evaluation` and are not added. `verdictCounts` (`Evaluation\RunAggregates`) maps all four verdicts to the number of the run's results whose latest evaluation is `completed` with that verdict: a re-evaluation moves a result from one verdict to another, and a result whose latest evaluation is pending or failed is not counted. It is null until the run completes or a result of it is evaluated; the batch sets all four keys (0 unless results were evaluated meanwhile) when it completes the run, and every evaluate job of a batch result recomputes it, a failed evaluation included. A cancelled run keeps its counts as they are. A retrieval-only run keeps `tokensTotal` and `interactionsTotal` at 0 and, since its results cannot be judged, `verdictCounts` at 0 for all four verdicts. Single executions have no run and no aggregates.

The retrieval aggregates of a run in mode `retrieval` are computed by `RunAggregates::refreshRetrieval()` from the stored results:

- **`questionsMeasured`, `questionsNegative`, `questionsUnmeasured`, `questionsRetrievalFailed`** count the results per `retrievalStatus`.
- **`recall`** is the share of measured results with a hit: hits / `questionsMeasured`.
- **`mrr`** (mean reciprocal rank) is Σ(1 / `firstRelevantRank`) / `questionsMeasured`, a miss counting 0. Hits at ranks 1, 2 and 1 and one miss give recall 0.75 and MRR (1 + 0.5 + 1 + 0) / 4 = 0.625.

Both ratios are null when `questionsMeasured` is 0, and only measured results enter their denominator; negative, unmeasured and failed results are counted but change neither. The denominators come from the stored results, never from `questionsTotal`. The job recomputes all six values from the rows at every end of the run, completed, cancelled, and failed as best effort (skipped and logged when the entity manager is closed), and never increments them, so a cancelled run reports exactly the questions it measured and a redelivered job gives the same values as a single delivery. In mode `agent` all six stay null, as they do while a run is going.

### Run comparison

`GET /eval_runs/{id}/compare/{baselineId}` (operation `eval_run_compare`, `neuronai:eval:compare`) compares the **candidate** run `{id}` with the **baseline** run `{baselineId}`. The caller decides which run is the baseline; every change is read as "the candidate relative to the baseline", so naming the runs the other way round swaps worse and better. The comparison (`Evaluation\RunComparer`) is computed on request and not stored; it is also no record of who looked at it. Both runs must be `completed`: the same run twice, or a run that is `pending`, `running`, `cancelled` or `failed`, answers 422 with the reason in `detail`. An unknown run answers 404, as on `GET /eval_runs/{id}`. Runs on different agents or with different question sets may be compared; the response names both so that the result can be attributed.

Questions are matched by their `key`. Per key the response lists in `questions` (ordered by key):

| Field | Meaning |
|---|---|
| `presence` | `both`, `only_in_baseline` (the question has no result in the candidate, e.g. archived in between: a regression) or `only_in_candidate` (new in the candidate: listed, nothing more) |
| `baseline`, `candidate` | the result of each run (`result` IRI, `status`, `evaluationStatus` of the latest evaluation, `verdict`, `rated`); null on the side where the key is missing |
| `verdictChange` | `worse`, `equal` or `better` by the position in `pass`, `mostly_pass`, `mostly_fail`, `fail`; null unless both sides are rated |
| `newlyFailed` | the candidate's result is `failed`, the baseline's is not (both present) |
| `newlyUnrated` | the baseline's result is rated, the candidate's is not (both present) |
| `questionChanged` | question text or expected answer differ between the two results (see below); null for unknown and for a key in one run only |
| `humanRating` | `field`, `order`, `baseline`, `candidate`, `changed`, `change` (see below); null for a key in one run only |
| `regression`, `regressionReasons` | flagged for `verdict_worse`, `human_rating_worse`, `newly_failed`, `missing_in_candidate` |
| `improvementReasons` | `verdict_better`, `human_rating_better`, `no_longer_failed`; listed, never flagged |

`baseline` and `candidate` at the top level name both runs (`run`, `status`, `agentVersion`, `agent`, `agentSlug`, `versionNumber`, `versionLabel`, `versionKind`, `startedAt`, `finishedAt`, `questionsTotal`, `verdictCounts`), `humanRatingComparison` is the effective setting (null without a usable one), and `summary` counts the classifications (`questions`, `inBoth`, `onlyInBaseline`, `onlyInCandidate`, `regressions`, `improvements`, `verdictWorse`, `verdictEqual`, `verdictBetter`, `newlyFailed`, `newlyUnrated`, `questionChanged`, `questionChangeUnknown`, `humanRatingWorse`, `humanRatingBetter`, `humanRatingChanged`). `versionLabel` is `v<n>` for a published version and `preview <first 8 hex digits of candidateHash>` for a [preview](#preview-versions), whose `versionNumber` is always 0, and `versionKind` is `published` or `preview`; the usual check of a change is a run on the candidate's preview compared with the baseline run on the current version (`preview 1a2b3c4d` against `v3`).

**Regression rules.**

- **Which verdict counts.** Per result only the latest evaluation (`EvalResult.evaluation`) counts, and only once it is `completed`, as for `verdictCounts`: a re-evaluation moves the comparison, and an earlier completed verdict does not count while a newer evaluation is pending. No evaluation, or a pending or failed latest one, is *unrated*: `verdictChange` stays null and it is never a regression, but a question rated in the baseline and unrated in the candidate is reported as `newlyUnrated`.
- **What is a regression.** A worse verdict, a worse human rating (only with the setting), a result that failed in the candidate but not in the baseline, and a question missing in the candidate. A question that exists only in the candidate is never a regression, also when its result failed.
- **Changed questions.** The import updates a question in place, so both runs' results point to the same question row. Every result stores the fingerprint of the question text and expected answer it ran with (`questionFingerprint`, set by the batch and the single execution); different fingerprints mean `questionChanged: true`. For a result without a fingerprint (recorded before the column existed), the first user messages of the two result conversations are compared instead: different texts mean `true`; equal texts mean null (the expected answer may still differ), and so does a deleted result conversation. A changed question does not suppress the other findings; it says that the key no longer means the same question, so treat its regression with care.
- **Human rating.** With the setting `evaluation.human_rating_comparison` (`{field, order, values?}`), `field` names a top-level property of the rating, `baseline` and `candidate` are its values, and `change` is `worse`, `equal`, `better` or `unrated`. Without `values` the field must hold a number (integer or float; a numeric string or a boolean is none) and `order` is `higher_is_better` or `lower_is_better`. With `values` (a list of strings) the field is ranked by its position in that list, which lists the scale from the **lowest** to the **highest** value, and `order` names the better end: `{"field": "grade", "order": "higher_is_better", "values": ["poor", "fair", "good"]}` makes `good` the best grade. A missing rating, a missing field, a value outside `values` or a value that is not a number is `unrated`, never a regression. Without the setting (or with an unusable one) `baseline` and `candidate` are the whole rating documents, `field` and `order` are null and `change` is `changed` or `unchanged` (key order inside the rating does not matter). `changed` is the equality check of the two stated values in both cases.
- **Retrieval-only runs.** Their results are compared like every other result, by presence, status and question fingerprint: `newlyFailed` and `no_longer_failed` follow failed searches, and `questionChanged` works because every such result has a fingerprint. Their results are never judged, so they are always unrated: `verdictChange` stays null, a worse verdict is never reported, and against a judged baseline every rated question becomes `newlyUnrated`. Aspects that need the result conversation are unknown (null), because there is none; this matters for a baseline without fingerprints, whose question change cannot be checked against a retrieval-only candidate. Retrieval metrics are not compared (see [Evaluation limitations](#evaluation-limitations)); compare `recall` and `mrr` of the two runs directly.
- **Improvements** (better verdict, better human rating, a result that no longer fails) are listed in `improvementReasons` and counted in `summary.improvements`, but never set `regression`.

Provider output is non-deterministic, so one regression of a live run is a hint, not proof; fixture runs are deterministic.

### Conversation replay

A conversation replay (operation and job type `conversation_replay`) replays the user messages of a stored conversation on another published agent version, so the counter-check after a prompt or model change is one request. The replay runs on the target version's provider connection and model, whatever the source ran on; a target version whose connection was deactivated or deleted makes the replay `failed` with the stale-reference message. It is not the history replay of a turn, where the stored records of a conversation are sent to the model again (see [The turn](#the-turn)); in code it lives in `Entity\ConversationReplay` and the namespace `Replay\`.

**Trigger.** `POST /conversations/{id}/replay` (`neuronai:eval:write`, `{id}` = the source) takes `{"targetVersion": "<IRI /api/agent_versions/<uuid> or full UUID>", "variables": {…}, "language": "…"}` (schema `Entity/Conversation/replay.input.json`; `variables` and `language` optional, other properties 422, malformed JSON 400). Any conversation can be a source: own or foreign, anonymous, an evaluation or replay conversation, a conversation created without an agent. The target is any published version of an enabled agent, also an older one (unlike the evaluation run start, which takes only the current published version or a preview). In the request, before anything is stored, the replay answers 422 for a source without user messages, an unknown version, a [preview](#preview-versions) (a replay compares against a version that can serve chats), a disabled agent, and everything pinning refuses (see [Creation with an agent](#creation-with-an-agent)): invalid variables at `variables/<name>`, an unsupported language at `language`, a stale reference of the version with its path. Otherwise it creates the replay conversation and the record and queues the job, and answers 202 with the record.

- **Replay conversation.** Created by the request, so there is exactly one per replay whatever the job queue redelivers: owned by the caller, `origin` `replay`, pinned to the target version like a creation with an agent. The target's rules apply: instructions, language, pre-answer rules and templates, output templates, answer gates, tools and limits, including a `maxUserTurns` lower than the source's number of user messages.
- **Variables and language.** `variables` replaces the source's start parameters as a whole, there is no merge; omitted, the source's `variables` apply, and a source without them (a conversation without an agent) gives `{}`. Source variables the target's `startSchema` does not declare are refused like any undeclared variable, so replaying onto a version without a start form needs `"variables": {}`. `language` omitted means the source's language when the target lists it in `languages`, else the target's `defaultLanguage`; the effective language is stored on the record and the conversation.
- **What is replayed.** The ids of the source's user messages, in sequence order, are frozen by the request (`sourceMessageIds`, never serialized): all of them, including those of aborted, failed and rule-refused turns, because the target version decides anew. Assistant records, tool results and the request-level `responseSchema` or `terminalTools` of a conversation without an agent are not replayed; the target version fixes these.

**The job.** `JobProcessor\ConversationReplayJobProcessor` with `Replay\ConversationReplayRunner` sets the record `running` and runs one turn per frozen message through the same `ConversationTurnService::turn()` as `POST /conversations/{id}/messages`, under the audit actor of the triggering user (`Anonymous\SystemActor` with the record's `ownerSubject`): the creation and every turn change of the replay conversation are audited under that user, never under `system:neuron:eval`. A turn whose answer is an `error` record, a rule refusal or a gate refusal counts as replayed. After every turn it writes `turnsReplayed`, the job's `progress` and `resultData` (`{status, turnsReplayed, turnsTotal}`). Final statuses:

| Status | When |
|---|---|
| `completed` | every frozen message was replayed, also when the last turn closed the conversation |
| `partial` | the replay conversation closed before the last message, usually at the target version's user-turn limit (`closeReason` `user_turn_limit`), or the turn gate refused |
| `cancelled` | the cancel flag was set |
| `failed` | a source message no longer exists (the source was deleted during the replay), or a turn threw (a stale reference of the target version, a database error); `errorMessage` says why |

**The record.** `Entity\ConversationReplay` (table `neuron_conversation_replay`, migration `Version20261003220000`, not audited): `id` (the id of the replay conversation, which addresses it), `conversation` (the replay conversation, `ON DELETE CASCADE`), `sourceConversation` (`ON DELETE SET NULL` at database level, because the anonymous purge deletes with DQL and no ORM cascade runs), `targetVersion` (`ON DELETE RESTRICT`), `ownerSubject`, `status`, `language` (effective), `overrides` (`variables` and `language` exactly as the request gave them, null when it gave neither), `jobId`, `cancelRequestedAt`, `turnsReplayed`, `turnsTotal`, `errorMessage`, `startedAt`, `finishedAt`. `GET /conversations/{id}/replay_run` (`{id}` = the replay conversation) returns it to everybody who may read the replay conversation (owner, `neuronai:conversation:read_eval`, `neuronai:conversation:read_any`); a conversation that is no replay conversation answers 404.

- **Cancel.** `POST /conversations/{id}/replay_run/cancel` (`conversation_replay_cancel`, `neuronai:eval:write`, `{id}` = the replay conversation, no body) sets `cancelRequestedAt` (a second request keeps the first time) and answers 200 with the record; 422 once the replay has ended. The job reads the flag from the database before each turn. The granularity is one turn: a running turn completes, with its tools, before the replay ends `cancelled`. The conversation's own `cancelRequestedAt` is not used; it acts on streamed turns only and is cleared by every turn.
- **Redelivery.** A redelivered job of a `pending` or `running` replay resumes it: it skips as many source messages as the replay conversation already holds user messages. If a worker dies after a user record was stored and before the answer, that message counts as replayed: the next turn marks the orphaned turn `aborted`, and it is not repeated. A job for a replay that has ended or belongs to another job is a no-op; a second worker that finds the turn lock held leaves the replay to the first.
- **Manual turns.** While the replay is `pending` or `running`, `POST /conversations/{id}/messages` and `POST /conversations/{id}/stream` on the replay conversation answer 422 with the existing code `conversation_closed` (`Replay\ConversationReplayGuard`), so a manual turn cannot shift the replayed messages; the job calls the turn service directly. Afterwards the owner may continue the conversation like any other while it is active.
- **Deleting.** A deleted source leaves the replay and its conversation intact, `sourceConversation` becomes null; during the replay the next message is missing and the replay ends `failed`. Deleting the replay conversation deletes the record; a running job stops before its next turn.

**Tools run live.** The target version's MCP tools are called again for every replayed turn, with whatever the model decides now. A write-capable tool (creating tickets, sending mail, changing records) acts again in the system behind it; recorded tool results of the source are never reused. Replay only onto versions whose tools are safe to repeat, or onto a version with a test connection.

**Personal data and retention.** Replaying copies the source's user messages and, unless replaced, its `variables` into the replay conversation, and sends them to the target's provider again. An anonymous source is copied into an ordinary conversation of the triggering admin: `AnonymousConversationPurger` purges only `anonymous = true`, so the copy outlives the anonymous retention and stays until it is deleted. The copied `variables` are on the item GET of the replay conversation and in the record's `overrides`, readable by every holder of `neuronai:conversation:read_eval`, i.e. reviewers as well. There is no retention for replays; delete replay conversations that are no longer needed.

#### Conversation replay limitations

- **One conversation per request.** There is no bulk replay; evaluation runs cover question sets.
- **Not judged.** A replay conversation has no evaluation result, so the judge cannot evaluate it.
- **Worker load.** A replay occupies the single queue worker for all its turns, like a batch run (see [Evaluation limitations](#evaluation-limitations)).
- **Non-deterministic output.** Live providers answer differently each time; a difference between source and replay is a hint, not proof.
- **Empty objects.** `"variables": {}` is shown as `[]` in `overrides` (JSON column round trip).

### Evaluation permissions

| Permission | May |
|---|---|
| `neuronai:eval:read` | read questions, runs, results, evaluations and suggestions (`GET` on all five), but not the run comparison |
| `neuronai:eval:write` | create, edit, delete questions; start and cancel runs; execute a single question; request a judge evaluation; edit suggestions; trigger and cancel [conversation replays](#conversation-replay) |
| `neuronai:eval:rate`, `neuronai:eval:compare`, `neuronai:conversation:read_eval`, `neuronai:feedback:read`, `neuronai:feedback:submit_reviewer` | rate results (`rate`); compare runs (`compare`); read every evaluation and replay conversation and the replay records (`read_eval`); read feedback and give `reviewer` feedback (see [Feedback](#feedback)) |

The permissions are independent: holding `neuronai:eval:write` alone gets 403 on the rate operation. The run comparison is the one read operation that needs more than `neuronai:eval:read`: a caller who may only read can read both runs and every result, but `GET /eval_runs/{id}/compare/{baselineId}` answers 403, as it does for a capability-token holder; the permission check comes before the runs are looked up, so the 403 also stands for unknown runs. Which roles hold which permission is the application's `za7_core.permissions`.

### Evaluation settings

The bundle reads its settings through `Evaluation\EvaluationSettingsInterface` (`judgeAgent()`, `humanRatingSchema()`, `feedbackPerSubjectAndHour()`, `humanRatingComparison()`), called on every use, so a changed value applies to the next request; without an implementation there is no judge, no rating schema and no human rating comparison. A host application typically reads these values from a runtime settings section with the keys below; the table shows what each method returns:

| Key | Type | Default | Meaning |
|---|---|---|---|
| `judge_agent` | string or null | null | agent (slug, UUID or IRI) whose current published version judges results; empty: no judge, evaluate answers 422 |
| `human_rating_schema` | object or null | null | JSON Schema of a human rating; empty (or `{}`): rating answers 422 |
| `feedback_per_subject_and_hour` | integer >= 0 | 30 | feedback submissions per user or capability token and hour; 0 disables feedback |
| `human_rating_comparison` | object or null | null | `{field, order, values?}` for the [run comparison](#run-comparison): `field` (non-empty string, required), `order` (`higher_is_better` or `lower_is_better`, required), `values` (optional non-empty list of distinct strings, the scale from the lowest to the highest value); no other keys. Empty: the comparison only reports changed/unchanged |

When the settings are changed by JSON merge patch, a patch `{"human_rating_comparison": {"order": "lower_is_better"}}` keeps `field` and `values`, `"values": null` removes the scale, and `values` itself is a list and is therefore replaced as a whole, never merged; an entry without `field` or `order` is invalid.

### Evaluation application wiring

- Firewall: `eval_questions|eval_runs|eval_results|evaluations|suggestions|feedback` in the pattern of the protected api firewall, with `access_control` requiring `IS_AUTHENTICATED_FULLY`; without it the routes are not behind a login.
- Access control: `{ path: ^/api, roles: IS_AUTHENTICATED_FULLY }` (feedback is reached by capability tokens too); the operations check their `neuronai:eval:*` and `neuronai:feedback:*` permissions themselves.
- Permission map: the `neuronai:eval:*` and `neuronai:feedback:*` entries of `za7_core.permissions`.
- An alias of `Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface` to the application's implementation.
- Routing of `^/api/eval_questions/[^/]+/execute$` to the stream pool in the reverse proxy.
- A messenger worker consuming the transport of the job queue (`async`); it also runs the conversation replays.
- Nothing extra for [conversation replay](#conversation-replay): its operations live under `/api/conversations/{id}/…`, which the api firewall and the access control already cover.

### Audit actor

The worker has no user token, so the batch runs the creation of every evaluation conversation and every turn under the system actor `system:neuron:eval` (`Evaluation\BatchRunner::ACTOR`, through `Anonymous\SystemActor`); the evaluate job writes under the same actor. The audit entries of evaluation conversations created by a run therefore carry `system:neuron:eval`, not the user who started the run (who is the run's `ownerSubject`). A retrieval-only run creates no conversation and runs nothing under the system actor. Single executions are audited under the calling user, and so are conversation replays: the replay job runs its turns under the triggering user (the record's `ownerSubject`), not under `system:neuron:eval`. Questions, runs, results, evaluations, suggestions and replay records themselves are not audited.

### Evaluation limitations

- **Worker starvation.** With a single messenger worker (for example `messenger:consume scheduler_neuron async`) a run occupies it for all its questions, each turn taking up to `turn.deadline_seconds` (120 s by default), so a run of N questions can block embedding, document, reindex, import and judge jobs as well as the scheduled purge for up to N × 120 s. Start large runs off-peak or add a worker for `async`.
- **Readable by every user.** Everybody with `neuronai:eval:read` reads every question with its expected answer and every run, result, evaluation and suggestion; only the evaluation conversations themselves are restricted.
- **Only the current published version or a preview.** A run or single execution refuses an older published version of an agent; evaluating a previous version needs a rollback first. A preview is accepted whatever the current version is.
- **A refused start on a candidate leaves its preview.** `candidate: true` creates the preview before the remaining checks of the start; a start refused afterwards keeps it, and the next start of the unchanged candidate reuses it or the purge removes it.
- **Preview runs are temporary.** The [preview purge](#preview-versions) deletes the runs of a preview with their results, judge evaluations and human ratings after the retention (see [Preview versions](#preview-versions)). Run the published version again to keep a result.
- **No user JWT in batch runs.** Batch runs, on previews as on published versions, run under the system actor without a user JWT, so only the read-only tools of `forward_jwt` MCP connections are built (see [MCP servers as the `mcp` connection type](#mcp-servers-as-the-mcp-connection-type)); a single execution runs as the calling user.
- **Default language only.** Questions run in the version's default language; a question cannot choose another one. Its `requestData` are the only start data (see [Batch runs](#batch-runs)).
- **Usage of the fake provider.** The fake provider reports no usage, so tokens of results and evaluations are null in the DB tests; the token columns are proven only at unit level.
- **A dead evaluate job stays `pending`.** An evaluation whose job died is not re-queued; request a new evaluation of the result.
- **Only the first verdict-schema error.** opis/json-schema reports only the first violation of the judge's answer, so `errorMessage` may name one problem of several.
- **A killed single execution stays `pending`.** A process killed during the stream leaves the result `pending`.
- **Empty objects in a rating.** `{}` inside a rating is stored as `[]` (JSON column round trip).
- **Mutual references.** `neuron_eval_result.evaluation_id` and `neuron_evaluation.eval_result_id` reference each other; deleting a result removes its evaluations by cascade, and deleting an evaluation sets the result's link to null.
- **`ß` and `SS` do not match.** `mb_stripos()` compares case-insensitively character by character, without full case folding, so the marker `Straße` does not find `STRASSE`. List both spellings as markers where it matters.
- **Marker uniqueness is case-sensitive.** The import rejects duplicate markers after trimming, but `Tarif` and `tarif` pass as two markers, although they match the same chunks. This does not change any metric.
- **MRR of versions with several embedding models is indicative.** Scores of different embedding models are not comparable, so the merged order of a version whose collections use several models, and with it the rank, the cut at the depth and therefore recall and MRR, are indicative only.
- **The pool, not the context sent.** The metrics rate the uncapped, unexpanded pool at the retrieval depth, not the context the model received. With the defaults (depth 10 and `contextMaxChunks` 10, or depth 4 and the legacy cap of 8 chunks) both are normally the same chunks; a depth above the version's chunk cap, or hits beyond its character budget, make the metrics include chunks the model never saw. The neighbour passages the model received are never measured.
- **One question set for every version.** A run uses all active questions; questions with markers for another knowledge base show up as misses. Omit the markers of a question that does not belong to every corpus; it is then counted as unmeasured.
- **Single executions are not measured.** Only batch runs in mode `retrieval` measure retrieval.
- **Retrieval-only runs measure raw retrieval.** They skip the turn and with it the pre-answer rules, the gates and any answerability check: a question a rule would answer or refuse is searched anyway, so the numbers say what the search finds, not what the agent would do with it.
- **Retrieval-only results have no answer.** They cannot be judged (422), carry no verdict, and take no feedback because they have no conversation; the rate operation accepts a rating, but there is no answer to rate.
- **Comparison of retrieval-only runs is limited.** Their results are always unrated, so a comparison involving them reports presence, failed searches and question changes only; conversation-based aspects are unknown, and recall and MRR have to be read from the runs themselves.
- **Comparison of results without a fingerprint.** Results recorded before the column existed have no `questionFingerprint`; for them a changed expected answer is not detected (`questionChanged` stays null when the question texts match), and a deleted result conversation leaves the question state unknown.
- **No retrieval metrics in the comparison.** The comparison covers verdicts, result status, presence, question changes and the human rating; retrieval metrics are not compared.
- **Comparison payload.** The comparison lists every question key of both runs in one response, without pagination.
- **No cost aggregation.** Runs aggregate no costs, and a real conversation cannot be turned into a question yet.

## Feedback

End users and reviewers give feedback on a conversation or one of its messages. Resource `Entity\Feedback` (table `neuron_feedback`, API Platform resource `/feedback`):

| Field | Content |
|---|---|
| `id` | UUID |
| `conversation` | the conversation (required, `ON DELETE CASCADE`: feedback is removed with its conversation) |
| `message` | optional message; must belong to `conversation`, otherwise 422 on `message`; set to null when the message is deleted |
| `rating` | `up` or `down`, optional |
| `reason` | free text up to 255 characters, optional |
| `comment` | free text, optional |
| `source` | `enduser` or `reviewer`, derived by the server, never taken from the body |
| `subject` | user identifier of the submitter; for a capability token the conversation's `ownerSubject` (`anon:<id>`) |
| `createdAt` | time of the submission |
| `agent` | the agent of the conversation's agent version, null for a conversation without agent (read only, derived) |
| `status` | review status: `open` on submission, `fixed` or `wontfix` (see [Reviewing feedback](#reviewing-feedback)) |
| `reviewNote` | free note of whoever handles the feedback, optional |

At least one of `rating`, `reason` and `comment` is required, otherwise 422. The feedback itself cannot be changed; a `PATCH` sets only the review fields. There is no `DELETE`.

### Submitting feedback

`POST /feedback` (operation `feedback_submit`) with `{"conversation": "/api/conversations/<uuid>", "message": "/api/messages/<uuid>", "rating": "up", "reason": "…", "comment": "…"}` (all but `conversation` optional) answers 201 with the feedback. The checks run in this order:

1. **Authenticated.** The firewall requires a principal: a user token or a capability token (`Authorization: Bearer anon_…`).
2. **Conversation.** An unknown conversation IRI answers 400: API Platform's denormalization cannot resolve it and refuses the body before the processor runs, so neither a permission check nor the limit applies.
3. **Permission and `source`.** A user holding `neuronai:feedback:submit_reviewer` who may read the conversation (`neuronai:conversation:read_own`: own conversations, evaluation and replay conversations through `neuronai:conversation:read_eval`, every conversation with `neuronai:conversation:read_any`) submits as `reviewer`. Otherwise whoever may run a turn in it (`neuronai:conversation:turn_own`: the owner, or the capability token of exactly this conversation) submits as `enduser`. Everybody else gets 403, including a token holder on another conversation and a reviewer on a foreign ordinary conversation.
4. **Limit.** `FeedbackRateLimiter` counts submissions per user identifier in a sliding window of one hour, on the same storage and lock factory as the anonymous creation limit (`dmstr_neuron_ai.anonymous.rate_limiter_storage`, see [Creation rate limit](#creation-rate-limit)). The limit is `EvaluationSettingsInterface::feedbackPerSubjectAndHour()` (setting `evaluation.feedback_per_subject_and_hour`, default 30). Above it the submission answers 429 with `Retry-After` (seconds); a limit of 0 answers 403 "feedback is disabled", naming the setting. Without the settings adapter (no `EvaluationSettingsInterface` service) the processor falls back to `FeedbackSubmitProcessor::DEFAULT_LIMIT` (30). Every submission that passed the permission check counts against the limit, also one that step 5 then refuses with 422.
5. **Body validation** (rating values, lengths, at least one field, `message` of this conversation): 422.
6. **Persist.**

### Reading feedback

`GET /feedback` and `GET /feedback/{id}` are for holders of `neuronai:feedback:read` only, everybody else gets 403; the list filters `conversation`, `message`, `source`, `rating`, `status` and `conversation.agentVersion.agent` (exact; the agent IRI, e.g. `?conversation.agentVersion.agent=/api/agents/<uuid>&rating=down&status=open` for the open feedback rated down of one agent). The item security denies a null object without throwing, so the Hydra operation filter works.

### Reviewing feedback

Feedback rated down is a backlog, like the judge's [suggestions](#the-judge): `PATCH /feedback/{id}` (operation `feedback_review`, `application/merge-patch+json`) for holders of `neuronai:feedback:review` sets `status` (`open`, `fixed`, `wontfix`; another value answers 422) and `reviewNote` (null or blank clears it). Every other property of the body is ignored, not rejected, so `rating`, `reason` and `comment` keep the submitter's words. The workflow: list the open feedback rated down of an agent, open its conversation, fix the agent (instructions, data, tools), and set `fixed` with a note of what changed.

### Feedback limitations

- **The key is the user identifier.** A user counts across all conversations; a capability token counts as its `anon:<id>`, so every new anonymous conversation starts with a fresh budget.
- **Feedback is not editable.** A submission cannot be corrected or withdrawn through the API; it goes with its conversation. Only its review status and note change.

## Embeddings per model

Embeddings providers are resolved per (embedding connection, model) pair: the connection is the collection's `ollama` [provider connection](#provider-connections) (the connection of its catalogue entry), the model the registered name, the vector label; there is no `EmbeddingsProviderInterface` container service.

- `ProviderFactory::embeddingsProvider(ApiConfiguration $connection, string $model): EmbeddingsProviderInterface` returns the undecorated provider (Ollama at the connection's `url` with the model name and, if set, `options.num_ctx` from `context_length`); a non-`ollama` connection, an unregistered model or a model of another provider throws `InvalidArgumentException`.
- `Embeddings\EmbeddingsProviderLocator::for(ApiConfiguration $connection, string $model): EmbeddingsProviderInterface` wraps it in `Embeddings\PrefixedEmbeddingsProvider` with the model's `query_prefix` and `document_prefix` and caches one instance per (connection, model); an inactive or non-`ollama` connection throws `EmbeddingConnectionException`. Callers use the locator, not the factory. The locator goes through the `ProviderFactory` service id, so an app replacement (such as the testing fake) is used.

`PrefixedEmbeddingsProvider`:

- `embedText()` is meant for search queries and prepends the query prefix.
- `embedDocument()` / `embedDocuments()` embed the document's content with the document prefix prepended and set the vector with `setEmbedding()`. The document's content is not changed, so stored content stays free of prefixes.
- Empty prefixes mean pass-through.

```php
$vector = $locator->for($collection->getEmbeddingProviderConnection(), $collection->getEmbeddingModel())->embedText('hallo');
```

`Retrieval\CollectionRetriever` searches across collections that may use different models:

```php
/** @param list<Collection> $collections @param array<string, scalar> $filters */
public function search(string $query, array $collections, int $topK, array $filters = [], bool $hybrid = false, float $hybridWeight = 0.5): array; // list<Document>
```

Without `$hybrid` (the default) it runs the vector search:

- The query is embedded once per distinct (embedding connection, model) pair, with that model's query prefix, through that connection.
- With collections given, it runs one store query per collection, scoped to the collection's current model and filtered by its metadata `collection_id`; a collection without or with an inactive connection fails the search (422). With an empty list it runs one query per model in use without a collection filter when all collections of that model share one active connection, and one filtered query per collection when collections of the same model use different connections, because the stored rows are labelled by the model only and a query vector of one server must not rank another server's vectors; collections without or with an inactive connection are skipped there. `$filters` are added as metadata containment filters.
- The hits are merged by score descending and cut to `$topK`; every document carries `collection_id` and `model` in its metadata.
- Scores are `1 − cosine distance` within one model. Scores of different models are not comparable, so a merged list across models is only a rough ranking.
- Chunks of a model that no collection uses as its current model (for example left over after a reindex) are invisible.

Changing a model's prefixes or a collection's model changes the vectors: reindex the collection (see [Reindex to another model](#reindex-to-another-model)).

### Hybrid search

With `$hybrid` true and collections given, `CollectionRetriever::search()` combines keyword and vector search:

- **Lists.** Per collection it runs a vector list (`search()` with a `SearchRequest`) and a keyword list (`PgVectorStore::keywordSearch()` in the collection's `keywordLanguage`, terms ORed, ranked by `ts_rank_cd`, see the [store README](https://github.com/dmstr/neuron-pgvector-store#keyword-search)), both scoped to the collection's current model and metadata filters, each with `CollectionRetriever::candidates($topK)` = max(2 × depth, 20) entries. The keyword side needs no embedding: there is still one query embedding per distinct model.
- **Fusion.** All lists of all collections, across languages and embedding models, are fused by weighted reciprocal rank fusion (`Retrieval\RankFusion::fuse()`, a pure function, k = 60): a chunk scores `w / (60 + r_kw) + (1 − w) / (60 + r_vec)`, summed over the lists that contain it, with its 1-based rank in each list and `w` = `hybridWeight` (keyword lists weigh `w`, vector lists `1 − w`). A chunk found by both retrievers is one chunk. Chunks whose fused score is 0 (only in lists of weight 0) are dropped. The result is sorted by score descending, ties by id ascending, and cut to the depth. Weight 0 reproduces the vector ranks, weight 1 the keyword ranks.
- **Scores.** With hybrid on, the `score` of a returned document is its RRF score (a small positive number, at most about 1/61 per list), not a cosine similarity. That value reaches `ContextChunk::$score` and with it `Message.sources` of a turn, the `sources` stream event and the `sources` of `agent_chat`. Without hybrid the scores stay `1 − cosine distance`.
- **Hybrid off is the vector path.** `$hybrid` false runs exactly the vector search above (same ids, order and scores); a hybrid weight is then ignored. A weight outside 0..1 throws `InvalidArgumentException`. Without collections hybrid falls back to the vector search.

**Which paths are hybrid-capable.** The setting comes from an agent version only: `ContextRetriever::pool()` and `retrieve()` take `$hybrid` and `$hybridWeight`, and the synchronous and streamed turns of agent conversations, `agent_chat` and the evaluation measurement (`BatchRunner`, through `Agent\RetrievalScope`) pass the values of the pinned version. Paths without an agent stay vector-only: `/api/search`, the stateless `/chat` and conversations created without an agent.

**Limitations.**

- **Cross-language questions.** A collection is searched in its own text search configuration; a German question finds English keyword hits only through shared tokens such as codes and names.
- **Language per collection.** One language per collection; a mixed-language collection uses one configuration for all its chunks.
- **Context shaping applies after the fusion.** The fused pool is capped and expanded like the vector pool (see [Context shaping](#context-shaping)); the cap does not look at scores.

### Knowledge search

`Retrieval\KnowledgeSearch` is the search composition behind a host application's search operation (for example `POST /api/search`) and the MCP tool `rag_search` (see [RAG tools over MCP](#rag-tools-over-mcp)): it resolves the collection references, runs `CollectionRetriever::search()` and returns the hits as `Conversation\ContextChunk`, the same chunk shape a turn stores in `Message.sources` and the stateless chat returns as `sources`.

```php
/** @param list<string> $collections collection UUIDs or names, empty = all collections */
public function search(string $query, array $collections = [], int $k = 5, ?string $topic = null, bool $hybrid = false, float $hybridWeight = 0.5): array; // list<ContextChunk>
```

- **Filters.** `$topic` becomes the metadata filter `topic`; null or an empty string means no topic filter.
- **Collection names.** A chunk's `collectionName` comes from the resolved collections, and for hits of other collections (search without references) from one name lookup; a hit whose collection no longer exists has no name.
- **No cap, no expansion.** Unlike `ContextRetriever::pool()` the result is only cut to `$k`. `$k` is not clamped; the caller validates it (for example 1 to 50).
- **Errors.** An unknown reference fails before any embedding; the retriever's errors pass through (`EmbeddingConnectionException`, 422; `InvalidArgumentException` for a hybrid weight outside 0..1).

`Retrieval\CollectionReferenceResolver` is the one resolver of collection references, used by the knowledge search, the app's stateless chat and document upload. A reference is a collection UUID (any RFC 4122 spelling) or an exact collection name; IRIs are not references, a caller that accepts them strips the prefix first.

```php
/** @param list<string> $refs */
public function resolve(array $refs): array;           // list<string> collection ids (RFC 4122)
public function collections(array $refs): array;       // list<Collection>
public function collection(string $ref): Collection;
public function namesByIds(iterable $ids): array;       // array<string, string> id => name, one query
```

- `resolve()` and `collections()` return one entry per reference in the given order and keep duplicates.
- A reference that matches no collection throws `Retrieval\UnknownCollectionReferenceException`, an `UnprocessableEntityHttpException` (422) with the detail `Collection "<reference>" not found` and the reference in `$reference`, so a caller can catch it and answer differently (for example "not found" in an MCP tool).
- `namesByIds()` ignores null, non-UUID and deleted ids; without any valid UUID it runs no query.

### Context shaping

An agent version controls the context its turns send: how many retrieved chunks and characters, whether hits from uploaded documents are widened by their neighbours, and the instruction text in front of the context block. `ContextRetriever::retrieve()` is `expand(cap(pool()))`:

1. **Pool.** The search result cut to the retrieval depth (vector or [hybrid](#hybrid-search)), as the evaluation measures it.
2. **Cap.** `ContextRetriever::cap()` keeps chunks in ranking order until `contextMaxChunks` chunks or `contextMaxChars` characters are reached; a first chunk that alone exceeds the character budget is shortened to it, so a turn never loses all context to one oversized chunk.
3. **Expansion.** `Conversation\ContextExpander` widens the kept hits by their neighbours within the separate budget `contextExpansionMaxChars` (see [Neighbour expansion](#neighbour-expansion)).

The version values travel as `Conversation\ContextLimits` (`maxChunks`, `maxChars`, `expansionMaxChars`) on the `TurnConfiguration` that `Agent\VersionTurnMapper` builds, so the synchronous turn, the streamed turn and `agent_chat` of one version send the same context and instruction. The hard total is `contextMaxChars + contextExpansionMaxChars` (21000 with the defaults): the cap never lets the hits exceed the first, and the expansion never adds more than the second. Paths without an agent (conversations created without an agent, the stateless `/chat`) pass no limits: they keep the bundle cap `turn.context_max_chunks`/`turn.context_max_chars` and expand nothing.

| Key | New version (schema default) | Version stored without the key |
|---|---|---|
| `contextMaxChunks` | 10 | `turn.context_max_chunks` (8) |
| `contextMaxChars` | 15000 | `turn.context_max_chars` (6000) |
| `contextExpansionMaxChars` | 6000 | 0, no expansion |
| `contextInstructions` | `{}`, the bundle default | the legacy text `ContextRetriever::LEGACY_INSTRUCTION` |

The four keys are fixed by the version: in the body of `agent_chat` or of the creation of an agent conversation they answer 422 like the other version-fixed keys.

#### Stored versions

`AgentConfiguration::fromArray()` fills a missing key with its schema default, which for these keys would change the context of every version stored without them. `VersionTurnMapper` therefore reads each key on the raw stored configuration (`array_key_exists` on `AgentVersion::getConfiguration()`): a version stored without the key keeps the legacy value of the table above, per key. Such a version sends exactly the context and instruction it sent before, and with an explicit `retrievalDepth` null it still retrieves `store.top_k` chunks (see [Candidate schema](#candidate-schema)). A candidate published now always stores all four keys, so it gets the new defaults unless the candidate sets other values.

#### Neighbour expansion

A hit from an uploaded or crawled document gets the chunk directly before and directly after it in document order (chunk index − 1 and + 1), merged as `before + "\n\n" + hit + "\n\n" + after` with only the parts that were added. The bundle knows only the chunk id of a hit (embedding metadata `chunk_id`), so the lookup is a port: `Retrieval\NeighbourSourceInterface::neighbours(string $chunkId): array{before: ?Neighbour, after: ?Neighbour}`, with `Retrieval\Neighbour` holding the neighbour's chunk id and text. The application implements and aliases it; without an alias the expansion is a no-op. A typical implementation returns neighbours for uploaded and crawled documents and none for chunks without a document order; crawled pages need them as well, because, for example, the date of a calendar entry stands above the event heading and ends the previous chunk. The neighbour text is the neighbour's whole chunk content; other split parts of the hit's own chunk are not neighbours.

- **Order.** Hits are walked in rank order and `before` is tried before `after`, so the budget goes to the best hits first.
- **No duplicates.** A neighbour that is already in the context, as a hit or as a neighbour added for an earlier hit, is skipped.
- **Never cut.** A neighbour costs its length plus the two-character separator; one that does not fit the remaining budget is skipped whole, and a later, smaller neighbour may still fit.
- **The list stays.** Expansion changes only the content of a hit: count, order, score and metadata stay as retrieved. `sources[].content` (in `Message.sources`, the `sources` stream event and the `sources` of `agent_chat`) is the merged text as sent to the model.
- **Edges.** The first and last chunk of a document have one neighbour; a deleted neighbour is missing; an unknown or invalid chunk id has none.

#### Instruction

The context block of the system prompt starts with an instruction text, used literally (no placeholders). The bundle option `turn.context_instruction` (default `ContextRetriever::DEFAULT_INSTRUCTION`: answer only from the context, say so when it does not contain the answer, answer in the language of the question, reference sources as `[n]`) applies to paths without an agent. An agent version takes the text of `contextInstructions` for the effective language of the turn, else for its `defaultLanguage`, else the bundle option; an empty text counts as missing. A version stored without `contextInstructions` keeps the legacy text (`ContextRetriever::LEGACY_INSTRUCTION`), byte for byte.

#### Measurement

The evaluation measures `pool()`: fused, cut to the depth, uncapped and unexpanded (see [Retrieval metrics](#retrieval-metrics)). `Agent\RetrievalScope` carries collections, depth and the hybrid setting, not the cap or the expansion, so a neighbour can never turn a measured miss into a hit, and two versions that differ only in their context keys measure the same recall and MRR.

## Document extraction and chunking

`Dmstr\NeuronAiBundle\Document` turns uploaded files into chunks ready for embedding. It is kernel-free library code: it reads a local file and returns value objects, and knows nothing about storage, entities or jobs. Storing files, persisting chunks and embedding them is the application's job.

```php
$mimeType = $typeDetector->detect($localPath, $originalFilename);
$extracted = $extractorRegistry->extract($mimeType, $localPath, new ExtractionOptions($originalFilename));
if ($extracted->isEmpty()) {
    // no text content
}
$chunks = $markdownChunker->chunk($extracted, ChunkOptions::fromArray($payload, $defaults));
// $extracted->extractorName, e.g. "text" or "pdf"; $extracted->pageCount for PDFs; each Chunk has breadcrumb, body, index
```

Services (private, autowirable by class name): `Document\TypeDetector`, `Document\Extractor\ExtractorRegistry`, `Document\Extractor\TextExtractor`, `Document\Extractor\PdfExtractor`, `Document\Extractor\SpreadsheetExtractor` and `Document\Chunker\MarkdownChunker`.

### Type detection

`TypeDetector::detect(string $path, ?string $filename = null): string` returns a MIME type from the file content (finfo via `league/mime-type-detection`). The extension only refines within the text family: text with a filename ending in `.md` or `.markdown` is `text/markdown`, other text is `text/plain`. A non-text signature is returned as detected, so PNG bytes in a `.md` file stay `image/png` and are rejected as unsupported; an extension never makes binary content look like text. An empty file counts as text and ends up as an empty extraction. Undetectable content is `application/octet-stream`. Spreadsheets get a second narrow refinement, because libmagic recognises OOXML and ODF files only when their ZIP entries come in a particular order (Alpine's libmagic reports an ods written by phpspreadsheet as `application/zip`): a ZIP or undetectable signature with the extension `.xlsx` or `.ods`, and an OLE2 or undetectable signature with `.xls`, become the spreadsheet type of the extension. No other signature is refined, so a PDF, an image or text never becomes a spreadsheet by its name. Pass the original filename, because the file handed over is usually a temporary copy without an extension.

### Extractors

An extractor implements `Document\Extractor\ExtractorInterface`:

```php
public function name(): string;                       // short, stable, e.g. "text"
public function supports(string $mimeType): bool;     // lower case, no parameters
public function extract(string $path, ExtractionOptions $options): ExtractedDocument;
```

`extract()` returns an `ExtractedDocument`: an ordered list of `Section` objects (`text`, plus the 1-based `page` of a PDF page or the `sheet` name of a spreadsheet sheet), `pageCount` (pages of a PDF including those without text, null for other formats), `isEmpty()` and `text()` (sections joined by a blank line). A file without text yields a document without sections; a file that cannot be read or decoded throws `ExtractionException`, whose message is meant for end users.

`ExtractorRegistry` collects all services tagged `dmstr_neuron_ai.document_extractor`. `find(string $mimeType): ?ExtractorInterface` returns the first extractor that supports the type, `extract(string $mimeType, string $path, ExtractionOptions $options)` runs it and sets its `name()` as `extractorName` on the result, and an unsupported type throws `UnsupportedDocumentException` (an `ExtractionException`) with the message `unsupported file type <mime>`. MIME types are lower-cased and stripped of parameters such as `; charset=utf-8` before matching.

The bundle ships three extractors:

| Extractor (`name()`) | MIME types | Output |
| --- | --- | --- |
| `TextExtractor` (`text`) | `text/markdown`, `text/x-markdown`, `text/plain` | the whole text as one section, a YAML frontmatter block of a Markdown file returned apart |
| `PdfExtractor` (`pdf`) | `application/pdf` | one section per page with text, `page` set; `pageCount` |
| `SpreadsheetExtractor` (`spreadsheet`) | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (xlsx), `application/vnd.ms-excel` (xls), `application/vnd.oasis.opendocument.spreadsheet` (ods) | one section per sheet with content, `sheet` set |

`TextExtractor` strips a UTF-8 byte order mark, rejects invalid UTF-8 and NUL bytes, normalises CRLF and CR to LF and returns the whole text as one section; heading structure is left to the chunker.

**Frontmatter.** In a Markdown file (original filename `.md` or `.markdown`, `TypeDetector::isMarkdown()`) a leading block between `---` on the first line and a closing `---` or `...` line that parses as a YAML mapping is cut from the text and returned as `ExtractedDocument::$frontmatter` (JSON-safe: dates as ISO 8601 strings), so it is neither chunked nor embedded. Anything else stays text: a horizontal rule followed by prose, invalid YAML, a list or a scalar, or a block that does not start on the first line. Plain text keeps the block: a `.yaml`, `.txt` or `.csv` file may start with `---` (a multi-document YAML file does). The stored file is not touched, so the document hash still covers the block and a change to it alone is detected. The application records the block in the document metadata (`metadata.frontmatter`, its `title` as `topic` and `title`) and on the embeddings (`frontmatter`, filterable with `{"frontmatter": {"tags": ["…"]}}`).

`PdfExtractor` reads text PDFs with `smalot/pdfparser` (pure PHP, no OCR). Pages without text are dropped but counted in `pageCount`. Page text is cleaned for storage (NUL bytes removed, invalid UTF-8 replaced, LF line endings). It throws a readable `ExtractionException` for an encrypted or password-protected file, a damaged or truncated file, a file above the page limit, and a PDF without any text (scanned or image-only, which would need OCR); the last case throws instead of returning an empty document, so the user learns why.

`SpreadsheetExtractor` reads xlsx, xls and ods with `phpoffice/phpspreadsheet`; only these three readers are tried, so CSV or HTML content is never parsed as a spreadsheet (CSV stays `text/plain` for `TextExtractor`). The reader runs read-only (`setReadDataOnly(true)`, `setReadEmptyCells(false)`): no styles or number formats, so dates appear as serial numbers. A formula cell contributes the cached value stored by the application that saved the file, empty when none was stored; the calculation engine never runs and the formula text is never used. Each sheet's text is a heading with the sheet name followed by a Markdown table whose header is the sheet's first non-empty row; empty rows and columns are dropped, line breaks inside a cell become spaces and `|` is escaped:

```markdown
# Products

| Name | Price | Notes |
| --- | --- | --- |
| Widget | 9.5 | blue \| small |
```

The chunker thus uses the sheet name as breadcrumb and repeats the header row at the top of every chunk of a large sheet. Sheets without content are skipped; a workbook without content yields an empty document. A file that is not a readable workbook, or one above the cell limit, throws a readable `ExtractionException`.

#### Limits and large files

A PHP memory fatal cannot be caught: it ends the worker (for example one started with `--memory-limit=512M`), leaves the job `running` and the document `extracting`, and the transport delivers the message again. The extractors therefore check their limits before the expensive step and fail the document with a readable message instead:

| Limit | Default | Where |
| --- | --- | --- |
| PDF pages | 500 (`PdfExtractor::DEFAULT_MAX_PAGES`) | checked after parsing, before any page text is extracted |
| PDF decompressed stream | 16 MiB per stream (`PdfExtractor::DEFAULT_DECODE_MEMORY_LIMIT`, pdfparser `setDecodeMemoryLimit()`) | while parsing; image streams are not kept (`setRetainImageContent(false)`) |
| Spreadsheet cells | 100,000 (`SpreadsheetExtractor::DEFAULT_MAX_CELLS`), rows x columns of the used range summed over all sheets | from `listWorksheetInfo()` before the workbook is loaded, again per loaded sheet |

A workbook of 5,000 rows x 20 columns (100,000 cells, about 600 KB as xlsx) peaks at about 65 MB during extraction. The limits are constructor arguments; an application that needs other values overrides the service definition, e.g. `Dmstr\NeuronAiBundle\Document\Extractor\PdfExtractor: { arguments: { $maxPages: 200 }, tags: [dmstr_neuron_ai.document_extractor] }`.

Guideline for large files: pdfparser still loads and parses the whole PDF in memory, which takes a multiple of the file size and more for PDFs with many fonts and pages, so keep PDFs well below 20 MB and split long manuals into parts of a few hundred pages. Split workbooks above the cell limit by sheet or remove unused rows and columns: a single cell far below the data counts the whole rectangle up to it. Export scanned PDFs through OCR first, because the extractor reads only embedded text. Keep the application's upload limit (for example 20 MB) in line with the worker's memory limit.

An application adds an extractor by tagging its service; the tag must be explicit, because autoconfiguration does not add it. A higher `priority` puts it in front of the built-in extractor:

```yaml
services:
    Acme\Document\CsvExtractor:
        tags:
            - { name: dmstr_neuron_ai.document_extractor, priority: 10 }
```

### Chunker

`MarkdownChunker::chunk(ExtractedDocument|string $text, ChunkOptions $options): list<Chunk>` splits Markdown and plain text. A `Chunk` has `breadcrumb` (heading path such as `Guide > Setup > Docker`, empty when none), `body` and `index` (0-based, contiguous); `text()` is breadcrumb, blank line and body, `size()` its length in characters.

`ChunkOptions` holds three sizes in characters: `targetSize` (default 1500, maximum chunk body), `overlap` (200) and `minSize` (300). `targetSize` and `minSize` must be positive, `overlap` must not be negative (0 means no overlap) and must be smaller than `targetSize` and `minSize` must not exceed it; otherwise the constructor throws `InvalidArgumentException`. `ChunkOptions::fromArray(array $options, ?ChunkOptions $defaults = null)` reads the API keys `chunkTargetSize`, `chunkOverlap` and `chunkMinSize` (integers or digit strings from form fields) and falls back to `$defaults` for missing keys.

Rules:

1. Line endings become LF and noise runs collapse before chunking: three or more of the same character among `.`, `-`, `_`, `=` and `*` become exactly three (`Chunker\Noise::normalize()`), so dot leaders and rulers do not bloat chunks while table separator rows and thematic breaks stay valid.
2. The text is split into sections at ATX headings `#`, `##` and `###`; deeper levels, setext headings and lines inside fenced code blocks are body text. The heading line stays in its section's body, and the breadcrumb is the heading path.
3. A section consisting only of its heading line is merged into the following section. A section below `minSize` is merged into its neighbour as long as the result stays within `targetSize`; the merged section keeps the more specific breadcrumb.
4. A section above `targetSize` is split by separators in this order: fenced code block and table boundaries, blank line, line, sentence end. Pieces are packed up to `targetSize`, and up to `overlap` characters of a full chunk's end are repeated at the start of the next one.
5. A table larger than `targetSize` is split between rows; a row is never split, and the header and separator rows are repeated at the top of every continuation chunk. Table chunks carry no overlap.
6. As a last resort, a single line without separators (or a single table row) longer than `targetSize` is cut hard, so no chunk body exceeds `targetSize` characters. The size is counted in characters, not tokens: keep the embedding handler's own length guard as the last line of defence against the model's context window.
7. Exact duplicate bodies within one document are dropped, the first occurrence wins, and the indexes are renumbered.

Blank input yields no chunks. A document of heading lines only yields a chunk whose body is the heading lines. An `ExtractedDocument` is chunked as the text of all its sections; page and sheet provenance is not mapped onto chunks yet, but a sheet's name reaches its chunks as the breadcrumb through the sheet heading.

## Test seams

The bundle ships fakes in `Dmstr\NeuronAiBundle\Testing` so tests of a host application run without Ollama or Anthropic (they use PHPUnit assertions, so `phpunit/phpunit` must be installed where they are used):

- `FakeProviderFactory` extends `ProviderFactory` and takes the `EmbeddingModelRegistry`. `chatProvider($connection, $model, ?ModelOptions)` takes any non-empty model (availability is the business of `FakeModelLister`; the constants `ANTHROPIC_MODEL` = `claude-haiku-4-5-20251001` and `OLLAMA_MODEL` = `llama3.2` are the models the tests use by convention, `OLLAMA_CONNECTION` and `ANTHROPIC_CONNECTION` the default names of `ProviderConnectionFixtures`), records the request (`getRequestedProviders()` returns `['provider' => <connection type>, 'connection' => <connection name>, 'model', 'temperature', 'maxTokens']` per call, the last two from the model options) and returns one shared `Testing\RecordingFakeAIProvider`, which `getChatProvider()` returns for assertions (`getRecorded()` lists `Testing\ToolChoiceRequestRecord`s, Neuron `RequestRecord`s with `messages`, `systemPrompt` and `tools` plus the `toolChoice` set at call time). The fake implements `Provider\ToolChoiceAwareProviderInterface` and accepts every tool choice; `chatProvider()` makes it decline every choice for a connection of type `ollama`, as in production, and `FakeProviderFactory::declineToolChoice()` makes it decline for `anthropic` too from the next `chatProvider()` call on, to test the fallback of the tool-call bound on a capable provider. `declineForcingChoices()` (on the fake provider and on `FakeProviderFactory`) makes it decline only the forcing choices `any` and `tool` and accept `auto` and `none`, standing in for `AnthropicWithToolChoice` on the model lines that decline forcing (`claude-fable-*`, `claude-opus-5*`, `claude-sonnet-5*`), so tests cover a forced step with terminal tools that falls back to `none`. The choice changes only the records, not the scripted answers. The script: `queueAnswer(string $text)` appends an assistant answer, `queueReasoning(string $reasoning, string $answer, string $signature = 'sig')` an answer with a reasoning block, `queueToolCall(string $name, array $inputs = [], ?string $callId = null, ?string $reasoning = null, string $signature = 'sig', ?string $text = null)` a tool call (consecutive calls form one response with parallel calls; default call ids are unique within the fake; the tool must be among those offered to the model; `$reasoning` adds a reasoning block and `$text` the text the model writes before its tool calls to that response, a later non-null value in the same response wins; a streamed run yields that text as `TextChunk`s before the tool events, which is how tests cover a failed forced close with leading text), and `queueFailure(string $message)` makes the next provider call throw a `ProviderException`. For streamed runs the fake yields the reasoning blocks of a response as `ReasoningChunk`s before its text as `TextChunk`s (5 characters per chunk, `setChunkSize()`); `queueStreamFailure(string $message, int $afterChunks, ?string $text = null)` lets the next streamed answer throw after `$afterChunks` chunks, `setChunkDelayMs(int $ms)` sleeps before every chunk and `setChunkHook(?\Closure $hook)` runs `(int $index, StreamChunk $chunk)` before each chunk is yielded (index from 0 per provider call), which is how tests act in the middle of a stream (e.g. set the cancel flag). The constructor flags `$echo` (an empty script answers with the last user message instead of throwing) and `$chunkDelayMs` serve out-of-process callers such as php-fpm in the CI stack test; their defaults change nothing.
- `RecordingStreamSink` implements `Stream\StreamEventSinkInterface` in memory: `getEvents()`, `getTypes()`, `textOf($type)`, `pings()` and an `onSend()` hook that runs after every event. `FakeProviderFactory::embeddingsProvider($connection, $model)` returns a `FakeEmbeddingsProvider` with the model's registered dimension (and records `['provider', 'connection', 'model']` in `getRequestedEmbeddings()`); all of them record on one shared `FakeEmbeddingsRecorder`, available through `getEmbeddingsRecorder()`.
- `FakeEmbeddingsProvider($model, $dimensions, $recorder)` returns deterministic vectors with exactly the requested number of dimensions (the upstream fake caps at 32) and records every `embedText()` call as `(model, text)`.
- `FakeModelLister` and `FakeModelDescriber` replace the provider's model endpoints (see [Test seam for provider connections](#test-seam-for-provider-connections)): the lister offers every model unless scripted, the describer offers no model unless scripted.
- `FakeEmbeddingsRecorder` offers `getRecorded()` (list of `['model' => …, 'text' => …]`), `getRecordedTexts(?string $model = null)`, `assertEmbeddedText(string $text, ?string $model = null)`, `assertModelUsed(string $model)`, `assertNothingEmbedded()` and `reset()`.

Wiring in the host application's `config/services.yaml`:

```yaml
when@test:
    services:
        Dmstr\NeuronAiBundle\Testing\FakeProviderFactory:
            autowire: true
            public: true
        Dmstr\NeuronAiBundle\ProviderFactory:
            alias: Dmstr\NeuronAiBundle\Testing\FakeProviderFactory
        Dmstr\NeuronAiBundle\Testing\FakeModelLister:
            public: true
        Dmstr\NeuronAiBundle\Provider\ModelListerInterface:
            alias: Dmstr\NeuronAiBundle\Testing\FakeModelLister
        Dmstr\NeuronAiBundle\Testing\FakeModelDescriber:
            public: true
        Dmstr\NeuronAiBundle\Provider\ModelDescriberInterface:
            alias: Dmstr\NeuronAiBundle\Testing\FakeModelDescriber
```

The `EmbeddingsProviderLocator` still wraps the fakes with the model's task prefixes, so the recorded texts carry the prefixes:

```php
$factory = $container->get(ProviderFactory::class); // FakeProviderFactory
$container->get(EmbeddingsProviderLocator::class)->for('nomic-embed-text')->embedText('hallo');

$recorder = $factory->getEmbeddingsRecorder();
$recorder->assertEmbeddedText('search_query: hallo', 'nomic-embed-text');
$recorder->assertModelUsed('nomic-embed-text');
$recorder->reset();

$factory->queueToolCall('search', ['query' => 'Wartung'])->queueAnswer('Antwort');
// ... code under test runs a turn: the model calls `search`, then answers
$factory->getChatProvider()->assertCallCount(2);
```

Kernel-free tests construct the fake directly, e.g. `new FakeEmbeddingsProvider('nomic-embed-text', 768, $recorder)`, or wrap it with `PrefixedEmbeddingsProvider::forModel($fake, $registry->get('nomic-embed-text'))` to include the prefixes.

A kernel-free test of an agent turn, with or without a [tool argument guard](#tool-argument-guard), runs `Chat\AgentRunner::runConfigured()` (or `executeConfigured()`) on a `TurnConfiguration` built by hand, so neither a database nor a published version is needed. The `ChatService` is the real one on `new TurnRunner($factory, new StructuredAnswerValidator())` with a `FakeProviderFactory` as `$factory` and `new McpToolFactory($entityManager, $fakeTransport)` on a `FakeMcpTransportFactory` (see [Fake transport for tests](#fake-transport-for-tests)); the entity manager, IRI converter and UUID resolver can be PHPUnit stubs, and `ProviderSelection` takes a `FakeModelLister`. The configuration's MCP tools come from `McpServerConnector::forTransport($fakeTransport->create(['url' => …]))->createCatalogueTool($name, $description, $inputSchema)`, one connector per connection. `runConfigured()` never calls the mapper, so the runner's `VersionTurnMapper` argument may be an instance without constructor. The model is scripted with `queueToolCall()` and `queueAnswer()`; `$fakeTransport->getMethods()` shows whether a call reached the server (`[]` for a refused one) and `getChatProvider()->getRecorded()` the tool results the model saw. The bundle's own `tests/Unit/Chat/AgentRunnerTest.php` and `AgentRunnerToolGuardTest.php` are built this way.

### Fixture mode

A batch run interleaves many question turns, judge calls and tool calls, so a script in call order is brittle. Fixture mode is an additional, order-independent way to answer them; it is a mode of the shared fakes, not a replacement, and unmatched calls behave as without fixtures.

- **Chat.** `RecordingFakeAIProvider::useFixtures(array $byKey)` (also on `FakeProviderFactory`, which passes it to the shared fake) takes key phrase => fixture. A `chat()` or `stream()` call is answered by the fixture whose key is contained in the content of the last user message (tool results excluded; case-sensitive substring; the longest matching key wins). A fixture is the answer text or an object with exactly one of `answer` and `failure` (the call throws a `ProviderException`), optionally `toolCalls` (a non-empty list of `{name, inputs?, callId?}`: the first call for the key answers with these parallel tool calls, the next one with `answer` or `failure`, the call after that starts with the tool calls again) and `reasoning` (a reasoning block on the answer). A call without a matching fixture falls through to the queue (`queueAnswer()`, `queueToolCall()`, …) and then to the echo fallback (constructor flag `$echo`, for example enabled through an environment variable of an end-to-end suite) or the empty-queue exception. Fixtures are not consumed; `getFixtureHits()` lists the keys that answered, in call order, and `clearFixtures()` empties the set without touching the queue. `structured()` never consults fixtures, because the turn runner never calls it.
- **Answer gates.** A gate call is a `chat()` call like the answer and is matched the same way. Its user message ends with the gate's marker, a space and the text under test (for the guard `InjectionGuard::MARKER.' '.$question`), so a fixture keyed with the marker and the question, e.g. `{"Message to check for prompt injection: What is the maintenance interval": "{\"injection\": false, \"reason\": \"A maintenance question.\"}"}`, is longer than the question's own key and answers the guard call. Without such a fixture the guard call matches the question's fixture: its answer text is an unparsable verdict, so the guard fails open, and a fixture with `toolCalls` loses its tool-call phase to the guard call. In script mode gate calls take their place in the shared queue (the guard before the answer), and calls counted with `getRequestedProviders()` grow by one per guarded turn. The follow-up call's user message ends with `FollowUpGenerator::MARKER.' '.$question`, so a fixture keyed `Question to suggest follow-up questions for: <question>` answers it with `{\"followUps\": [\"…\"]}`; in script mode its reply follows the answer in the queue, and an eligible turn adds one more requested provider. The echo fallback answers a gate call with an unparsable verdict (for the follow-up gate `followUps` `[]`), so tests must not rely on echo with gates on. Gates are off by default, so existing fixtures and scripts are unaffected.
- **MCP.** `FakeMcpTransportFactory::useFixtures(array $byTool, ?string $url = null)` answers `tools/call` by tool name and arguments: keys are `"<toolName>"` (any arguments) or `"<toolName>#<hash>"` with `<hash>` = `FakeMcpTransportFactory::argumentsHash($arguments)` (sha1 of the canonical JSON, keys sorted recursively); a fixture is a text or `{content: string|list, isError?: bool}`. Lookup order: exact `name#hash`, then bare `name` (each URL-specific before global), then the one-shot queue, then the unscripted behaviour (a JSON-RPC error). Fixtures are not consumed; `clearFixtures()` and `reset()` forget them.
- **Files.** `Testing\FixtureSet::load($path)` (chat) and `FixtureSet::loadMcp($path)` (MCP) read one JSON object of key => fixture and check only that structure; the fakes validate the fixtures when they are set. A typical fixture set is `questions.json` (an import file), `answers.json` (keyed by question text), `judge.json` (keyed by markers such as `[judge:pass]`, which a test puts into the expected answer, so they reach the judge's user message) and `mcp-tools.json`.

```php
$factory = static::getContainer()->get(ProviderFactory::class); // FakeProviderFactory
$factory->useFixtures(FixtureSet::load(__DIR__.'/../Fixtures/evaluation/answers.json')
    + FixtureSet::load(__DIR__.'/../Fixtures/evaluation/judge.json'));
static::getContainer()->get(FakeMcpTransportFactory::class)
    ->useFixtures(FixtureSet::loadMcp(__DIR__.'/../Fixtures/evaluation/mcp-tools.json'));
// ... start a run, run its job, evaluate a result
self::assertContains('[judge:pass]', $factory->getChatProvider()->getFixtureHits());
```

## Console commands

| Command | Purpose |
| --- | --- |
| `neuron:provider:connection:seed` | create or update an `ollama` or `anthropic` connection, see [Seeding provider connections](#seeding-provider-connections) |
| `neuron:provider-models:scan`, `neuron:provider-models:sync` | fill and refresh the [model catalogue](#model-catalogue) |
| `neuron:mcp:connection:seed` | create or update an `mcp` connection, see [Seeding connections](#seeding-connections) |
| `neuron:mcp-tools:scan`, `neuron:mcp-tools:sync` | fill and refresh the [tool catalogue](#tool-catalogue) |
| `neuron:embedding:index:sync` | create the missing per-model vector indexes, see [Store table](#store-table) |
| `neuron:anonymous:purge` | close and delete anonymous conversations, see [Retention and purge](#retention-and-purge) |
| `neuron:agent:previews:purge` | delete agent previews past their retention, see [Preview versions](#preview-versions) |
| `neuron:eval:questions:import` | import evaluation questions, see [Importing questions](#importing-questions) |

## Migrations

The bundle registers its migrations path `./migrations` under the namespace `Dmstr\NeuronAiBundle\Migrations` (prepended, see [Installation](#installation)); they run with the application's migrations (`bin/console doctrine:migrations:migrate`). There are 37 migrations, from `Version20260930054249` to `Version20261120090000` (PostgreSQL). Existing databases store their class names in the migrations table, so the class names never change.

**Tables of the bundle.** The migrations create and change only the `neuron_*` tables: `neuron_collection`, `neuron_mcp_tool`, `neuron_provider_model`, `neuron_conversation`, `neuron_message`, `neuron_stream_event`, `neuron_agent`, `neuron_agent_version`, `neuron_artifact`, `neuron_eval_question`, `neuron_eval_run`, `neuron_eval_result`, `neuron_evaluation`, `neuron_suggestion`, `neuron_conversation_replay` and `neuron_feedback`.

**Tables of the host application.** The documents and chunks of a collection (for example tables `document` and `chunk`) and the vector store table (`store.table`, default `neuron_documents`; for example `embedding`) are not created by this bundle: they belong to the host application, its entities and its migrations. The bundle reads and writes the store table through dmstr/neuron-pgvector-store and reaches documents and chunks only through the [host application ports](#host-application-ports). In a standalone setup without migrations of its own, create the store table once with `PgVectorStore::setupTable()` of dmstr/neuron-pgvector-store (see [Store table](#store-table)).

**Ordering.** Doctrine's default comparator orders migrations by their fully qualified class name, so no custom comparator is needed: the bundle's migrations run in the order of their timestamps, and the only foreign table they reference, `dmstr_api_configuration` (foreign keys of `neuron_mcp_tool`, `neuron_provider_model`, `neuron_conversation` and `neuron_collection`, and data migrations of `ollama` connections), is created by dmstr/api-configuration-bundle, whose namespace `Dmstr\ApiConfiguration\Migrations` sorts before `Dmstr\NeuronAiBundle\Migrations`. A new migration keeps a timestamp later than the existing ones.

**Data migrations that can abort.** `Version20261008190000` and `Version20261008200000` give every conversation and every collection an entry of the [model catalogue](#model-catalogue) and make the reference NOT NULL. They abort, with a query that lists the rows in the way, while a conversation or collection exists that cannot get an entry (no provider connection, a connection type the catalogue does not hold, or a model name too long for an entry); delete such rows (a collection together with its vectors, chunks and documents) before migrating.

## Testing

The PHPUnit suites live in this repository (configuration `./phpunit.dist.xml`, PHPUnit 12 or 13):

- `unit` (`./tests/Unit`, about 2350 tests): no kernel, no database.
- `integration` (`./tests/Integration`): boots the test kernel `./tests/Kernel/TestKernel.php`, a minimal host application with the dependency bundles, the [test seams](#test-seams) as provider, model lister and describer, MCP transport and JWT provider, header-based test authentication (`X-Test-User`, `X-Test-Roles`) with neutral test roles (`tests/Kernel/TestRoles.php`) and a test permission voter that maps the bundle's permissions to them. The [host application ports](#host-application-ports) are in-memory doubles (`./tests/Support/Port`), reset after every test by the PHPUnit extension `ResetTestPortsExtension`. About 90 tests need no database; about 1015 tests are in the group `database` and need PostgreSQL with pgvector from `DATABASE_URL` (default in `phpunit.dist.xml`). `tests/Kernel/TestDatabase.php` creates the schema once per process by running all registered migrations, the bundle's own ones included, and then the store table `embedding` with `PgVectorStore::setupTable()`, because no migration owns it.

Every HTTP request of the test kernel opens the PDO connection of the vector store (the factory service `dmstr_neuron_ai.pdo` is built eagerly through a `kernel.terminate` subscriber), so the HTTP tests need the database.

```bash
composer install
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration --exclude-group database
# with a PostgreSQL server with pgvector (e.g. the image pgvector/pgvector:0.8.6-pg16):
DATABASE_URL='postgresql://app:app@127.0.0.1:5432/neuron_ai_test?serverVersion=16&charset=utf8' vendor/bin/phpunit
```

No test contacts a model server or an MCP server. CI is the GitHub Action `./.github/workflows/tests.yml` (PHP 8.5 with `pdo_pgsql`, service container `pgvector/pgvector:0.8.6-pg16`), which runs both suites.

## License

MIT, see `./LICENSE`.
