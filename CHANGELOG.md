<!-- file generated with AI assistance: Claude Code - 2026-11-25 21:40:43 UTC -->

# Changelog

All notable changes to this bundle. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [SemVer](https://semver.org/) and come from the Git tags.

## [0.1.0] - 2026-11-25

Initial public release.

### Added

- Provider connections of the types `ollama` (chat and embeddings, authentication `none`, `bearer` or `client_credentials`) and `anthropic` (chat) on dmstr/api-configuration-bundle, with health probes, the seed command `neuron:provider:connection:seed`, the delete guard `Doctrine\ProviderConnectionDeleteGuard` and an optional allow-list `tool_models` per connection.
- The model catalogue `Entity\Refs\ProviderModel` under `/api/refs/provider_models` (scan, sync, capabilities, options schema) with the commands `neuron:provider-models:scan` and `neuron:provider-models:sync`.
- The embedding model registry (`embeddings.models`, default `nomic-embed-text`, `mxbai-embed-large`, `bge-m3`) with per-model task prefixes, model-agnostic pgvector store wiring through dmstr/neuron-pgvector-store, per-model vector indexes (`neuron:embedding:index:sync`) and hybrid search (reciprocal rank fusion of keyword and vector lists).
- Knowledge collections under `/api/admin/collections` with lock and unlock, delete with content and reindex to another model.
- The `mcp` connection type (authentication `none`, `bearer`, `header`, `client_credentials`, `forward_jwt`; restricted connections), the tool catalogue `Entity\Refs\McpTool` under `/api/refs/mcp_tools` with scan and sync, the seed command `neuron:mcp:connection:seed`, and the RAG tools `rag_list_collections`, `rag_search` and `rag_get_context` for dmstr/mcp-endpoint-bundle.
- Conversations with persisted messages, a bounded tool loop, terminal tools, a user-turn limit, structured outputs, streaming with resume and cancel, and anonymous conversations with a capability token, limits and a scheduled purge (`neuron:anonymous:purge`).
- Agents and versions with publish, rollback and preview versions (`neuron:agent:previews:purge`), model options, pre-answer rules, output templates, answer gates (prompt-injection guard, answerability check, follow-up suggestions), context shaping, an agent chat and artifact tools.
- An OpenAI-compatible chat API (`GET /api/v1/models`, `POST /api/v1/chat/completions`) on the published agents; the `owned_by` value of the model list is the bundle option `dmstr_neuron_ai.openai_compatible.owned_by` with the default `dmstr`.
- Evaluation of agent versions: questions (`neuron:eval:questions:import`), batch and retrieval-only runs with retrieval metrics, single executions, an AI judge, human ratings, run comparison and conversation replay; feedback on conversations and messages with review.
- The stateless chat service `Chat\ChatService` on the shared turn runner, and kernel-free document extraction (text with frontmatter, PDF, spreadsheets) and heading-aware chunking.
- The host application ports, among them the mandatory `Reindex\ReindexSourceInterface`; see the README.
- The permissions `neuronai:<resource>:<action>` (`Security\Permissions`), announced at the container tag `za7_core.permission`; the host application maps them to roles in `za7_core.permissions` and provides the voter.
- The test seams in `Dmstr\NeuronAiBundle\Testing` (fake provider factory, embeddings, model lister and describer, MCP transport, JWT provider, fixture mode). `Testing\ConfigSecretsFixture` uses a neutral, publicly known insecure test key.
- The 37 migrations of the namespace `Dmstr\NeuronAiBundle\Migrations` (`Version20260930054249` to `Version20261120090000`, PostgreSQL), which create and change only the `neuron_*` tables.
- PHPUnit suites `unit` and `integration` (test kernel with neutral test roles and in-memory port doubles, PostgreSQL with pgvector for the group `database`) and a GitHub Action.

### Requirements

- Licence: MIT.
- PHP >= 8.5, Symfony 7.4, `api-platform/core` >=4.4.8 (^4.4.8), Doctrine ORM 3, Doctrine DBAL 3, PostgreSQL with pgvector >= 0.8, `dmstr/neuron-pgvector-store` ^0.1.
- `neuron-core/neuron-ai` `>=4.1.11 <4.1.17`: 4.1.17 adds `ClosesOpenTurn`, which inserts a synthetic assistant message when a terminal turn is replayed and changes the history the provider sees; not supported yet.
- `dmstr/api-configuration-bundle` ^0.7, `dmstr/api-platform-utils-bundle` ^0.6, `dmstr/openapi-json-schema-bundle` ^0.5, `dmstr/symfony-job-queue-bundle` ^0.6.
- `symfony/lock` is required (no longer optional): the creation limit of anonymous conversations, the artifact quota and the `neuron` schedule use `lock.factory`.
- Suggested: `phpunit/phpunit` for the test helpers in `src/Testing/`, `dmstr/mcp-endpoint-bundle` for the RAG tools, `lexik/jwt-authentication-bundle` for JWT authentication next to capability tokens.

[0.1.0]: https://github.com/dmstr/neuron-ai-bundle/releases/tag/0.1.0
