<!-- file generated with AI assistance: Claude Code - 2026-11-25 21:40:43 UTC -->

# CLAUDE.md

This is a public open-source package maintained under github.com/dmstr.

Symfony bundle integrating the Neuron AI framework into an API Platform application: provider connections (`ollama`, `anthropic`) and their model catalogue, the embedding model registry and pgvector store wiring, knowledge collections, the `mcp` connection type with its tool catalogue and the RAG tools over MCP, conversations with streaming and anonymous access, versioned agents, evaluation and feedback, the OpenAI-compatible chat API, document extraction and chunking, and test seams in `src/Testing/`. The host application owns documents, chunks and the vector store table and connects them through ports. See `README.md` for the concepts.

## Language policy

**Everything in this repository is in English**: commit messages, code, comments, documentation, test names and test data, and all communication about the repository (pull request titles and descriptions, code review, issues, and chat with the coding assistant). These packages are public and English-only; do not reference private projects, internal trackers or company internals.

## Requirements and tests

PHP >= 8.5 (see `composer.json`). The PHPUnit suite (configuration `phpunit.dist.xml`) has two test suites:

- `unit` (`tests/Unit`): no kernel, no database. Run it with `vendor/bin/phpunit --testsuite unit`.
- `integration` (`tests/Integration`): boots the test kernel in `tests/Kernel` with the fakes of `src/Testing/` wired in. Tests in the group `database` need PostgreSQL with pgvector from `DATABASE_URL`; the schema is created by running the migrations, the bundle's own ones included. Without a server, run `vendor/bin/phpunit --testsuite integration --exclude-group database`.

No test contacts a model server or an MCP server. Test helpers live only under `tests/` and `src/Testing/`; `src/Testing/` uses PHPUnit, which is a `suggest` of the package.

## Invariants

- The namespace `Dmstr\NeuronAiBundle`, the bundle class `DmstrNeuronAiBundle`, the configuration root `dmstr_neuron_ai`, the container parameters `dmstr_neuron_ai.*`, routes, operation names, console command names (prefix `neuron:`), stream event types and table names (`neuron_*`) are public API.
- The 37 migration classes `Dmstr\NeuronAiBundle\Migrations\Version20260930054249` to `Version20261120090000` and the migrations path in `DmstrNeuronAiBundle` never change: applications store the class names. The migrations create and change only `neuron_*` tables; the only foreign table they reference is `dmstr_api_configuration`, so they sort by class name after `Dmstr\ApiConfiguration\Migrations` and need no custom comparator. A new migration keeps a timestamp later than the existing ones.
- The bundle owns no document, chunk or vector store table: the store table (`store.table`) belongs to the host application and is excluded from the ORM schema (`Doctrine\ExcludeStoreTableListener`).
- The bundle ships no implementation of `Reindex\ReindexSourceInterface`; the host application aliases it, and the container does not compile without it. Every other port has a default or is injected with `nullOnInvalid()`; keep it that way (see the port table in `README.md`).
- The bundle checks only permissions with `is_granted()`: the constants of `Dmstr\NeuronAiBundle\Security\Permissions`, tagged `za7_core.permission` in `config/services/security.php`, plus the owner attributes decided by `Security\ConversationVoter`. No `ROLE_*` name is checked in `src/` or `config/`; the host application maps permissions to roles through its own voter. The tag `za7_core.permission` and the parameters `za7_core.permissions` and `za7_core.permissions.registered` keep their names; a permission without a map entry is denied. A new permission gets a constant, a tag, a test and a README update in the same change.
- No fake or test fixture contacts a real provider, and secrets never appear in exception messages or logs.
- `neuron-core/neuron-ai` stays at `>=4.1.11 <4.1.17`: 4.1.17 adds `ClosesOpenTurn`, which inserts a synthetic assistant message when a terminal turn is replayed and changes the history the provider sees; lift the bound only together with support for it.
