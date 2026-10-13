# Neuron AI 4 upgrade

**Status:** approved (2026-10-12) — decisions agreed with the owner the same day

**Date:** 2026-10-12

**Modules:** AI (the only module that uses Neuron). Core gains two explicit Composer requirements.

**Plan:** `docs/superpowers/plans/2026-10-12-neuron-ai-4-upgrade.md`

## Problem

`Modules/AI` runs on `neuron-core/neuron-ai` 3.17.1 (constraint `^3.2` in `Modules/AI/composer.json`). Neuron 4 is out (4.1.9 at the time of writing) and 3.x receives no new work: chat history over a message store, semantic memory, the classifier and built-in vector store filtering exist only in 4.x, and the plan of persistent user memory and the guardrail decisions of the AI review were written to use them. The gate the owner set, that `2026-10-06-ai-module-neuron-review.md` be closed first, is satisfied.

## What the upgrade touches

Neuron 4.1.9 ships 57 upgrade guides (`vendor/neuron-core/neuron-ai/upgrade`, one per breaking change). A read-only triage on 2026-10-12 ran every guide's search patterns against the application. Fifteen apply, all inside `Modules/AI`:

| Guide | Change | Where it bites us |
|---|---|---|
| 0 | Agent skills reshaped | No 3.x skills installed; the 4.x set is installed for the work |
| 1 | Composer dependencies | Constraint, Guzzle and PSR packages used but not required |
| 2 | HTTP client layer | `tests/Stubs/RecordingHttpClient.php`, one URI assertion |
| 3 | `Tool` is abstract | `ToolRegistry` builds every tool with `Tool::make()` |
| 4 | `ToolCall` value object | `ChatAgent::resolveToolErrorHandler()`, a fake provider, two tests |
| 5 | Tool properties validate and cast | `SchemaToolProperty` implements `ToolPropertyInterface` |
| 13 | A workflow needs an id | Both agents are built without one; `chat()` and `structured()` throw |
| 17 | Branch runners | One test reflects on the removed `Workflow::$executor` |
| 19 | Vector store `SearchRequest` and filters | `ElasticsearchRagVectorStore` implements the interface |
| 20 | `Document` is final with accessors | About 13 application files and 30 test files use its properties |
| 24 | Instructions are `SystemMessage` | Tests read the recorded system prompt as a string |
| 25 | Configured setters win over hooks | `DocumentationAgent::postProcessors()` spreads the parent's |
| 33 | UUIDv7 ids | New chunk ids; stored as opaque keywords, expected to need nothing |
| 41 | Providers return `ProviderResponse` | A fake provider and one test call a provider directly |
| 56 | Testing fakes | `FakeVectorStore` canned hits in `DocumentationServiceTest` |

The other 42 guides do not apply: the application uses no Neuron workflow, chat history, persistence, streaming adapter, middleware, tool approval, MCP or toolkit.

## Decisions

1. **Scope: the upgrade only.** This spec moves the code to the 4.x API with the same behaviour, plus the baseline that proves it. Running assistant turns as Neuron workflows instead of Laravel jobs, and supporting every provider Neuron offers, are separate later specs.
2. **Baseline first.** Before Composer moves, the full suite, the AI static analysis count and the committed evaluation gates are recorded, so a change in behaviour after the upgrade is measured, not guessed.
3. **Thread id (guide 13): generated in the agent.** `ChatAgent` and `DocumentationAgent` pass a fresh UUIDv7 as `workflowId` to the parent constructor. The assistant is stateless and the application stores conversations in its own `Conversation` and `Message` models, so no Neuron state outlives a call and no call site needs to know the id.
4. **Tool arguments (guide 5): lenient cast.** `SchemaToolProperty::cast()` converts what 3.x let through: a numeric string to an integer or number, a scalar to a string, `"true"`/`"false"`/`1`/`0` to a boolean. A value it cannot convert is returned unchanged for the schema check to refuse. A missing required argument becomes an error the model receives (Neuron's `ToolOutput::error`), not an exception.
5. **Vector store filters (guide 19): minimal contract.** `ElasticsearchRagVectorStore` implements the 4.x interface (`getSchema()`, `delete(FilterExpression)`, `search(SearchRequest)`) for the filters Neuron itself sends. The in-app audience and permission filter stays the application's own Elasticsearch query (`similaritySearchForContext()`), which is security logic the application owns.
6. **Document (guide 20).** The read of `formattedContent` in `SentenceTransformersEmbeddingsProvider` goes: nothing in the application sets it. Its test stub `DocumentWithArrayFormattedContent` and the test that uses it go with it. `ElasticsearchRagVectorStore` refuses a document without an embedding, as the 4.x stores do.
7. **Composer (guide 1).** `guzzlehttp/guzzle` and `psr/http-client` are required by the module whose code imports them (AI and Core), and the test-only PSR packages go in `require-dev` of the modules whose tests use them. The Sentence Transformers provider keeps its Guzzle client.
8. **Agent skills (guide 0).** The 4.x skills are installed in the project (`npx skills add ./vendor/neuron-core/neuron-ai/skills -y`, into the tracked `.agents/skills/`), and the work continues in a new session, as the guide asks.

## Order and verification

The guides are applied one at a time, in their numeric order, as the vendor's `upgrade/AGENTS.md` prescribes, each with its own tests and its own commit, on a branch of `Modules/AI` (and of `laraplate` for the lock). The branch merges when the full suite matches the baseline, static analysis reports no new error in `Modules/AI`, and no committed evaluation report changes.

## Non-goals

- Neuron workflows, chat history or semantic memory in application code (persistent user memory has its own plan).
- New providers, or a change of the default models.
- Changing what the assistant, retrieval, moderation or translation do.
- Laravel 13 (its plan changes the Neuron constraint in its Task 2; that step is dropped after this upgrade).

## Related

- `docs/superpowers/plans/2026-10-06-ai-module-neuron-review.md` (the gate, closed)
- `docs/superpowers/plans/2026-09-16-persistent-user-memory.md` (builds on Neuron 4 after this)
- `docs/superpowers/plans/2026-09-16-laravel-13-upgrade.md` (its Neuron constraint step becomes obsolete)
