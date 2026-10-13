---
status: open
created_on: 2026-10-12
---
# AI module: evaluation gate in CI, run observability, provider hygiene. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Tick each step when it is done, in the same work block. A step dropped on purpose is `- [-]` with its reason on the same line.

**Status:** Open. No task started. Task 1 goes first: it is the cheapest and it protects the other two.

**Split 2026-10-12:** the MCP server was first drafted here as Task 3. It is a Core surface, not an AI one, and its design exists already: `docs/superpowers/specs/2026-09-12-mcp-server-design.md`. It has its own plan, `2026-10-12-core-mcp-server.md`.

**Goal:** make the AI module's quality measurable and reachable. The evaluation baselines that already exist run on every pull request. Every model call leaves a technical record (tokens, latency, outcome) without storing content. A provider defect is removed.

**Source:** review of `Modules/AI` on 2026-10-12. Findings, each checked against the code on that date:

| # | Finding | Evidence |
|---|---------|----------|
| F1 | MCP is designed but not built: spec `2026-09-12-mcp-server-design.md` is a draft with no plan, and `laravel/mcp` v1.0.1 is locked as a **dev** dependency only (via `laravel/boost`). Moved to `2026-10-12-core-mcp-server.md`. | `composer.lock` `packages-dev`; no `Mcp::` in `Modules`, `app`, `routes`; spec open point 2. |
| F2 | No record of tokens, latency or cost per model call. | No usage field in `Modules/AI/app`; the only analytics are the RAG query log and the model switch state. |
| F3 | The application CI does not run the AI baselines. The workflow checks out without submodules, so `Modules/AI/tests` is absent, and it runs PHP 8.4 while `composer.json` requires `>=8.5`. | `.github/workflows/laravel.yml` lines 17 and 21; `Modules/AI` is a submodule with no `.github` of its own. |
| F4 | `ProviderFactory` keeps a hardcoded Anthropic fallback model, `claude-sonnet-4-20250514`, while Settings and `ModelCatalog` already choose models. | `ProviderFactory::ANTHROPIC_DEFAULT_MODEL`. |
| F5 | The module has no hand-parsed model output, so there is nothing to move to structured output. | `json_decode` in `Modules/AI/app` only reads files, HTTP bodies and settings. Recorded so nobody looks again. |

## Global Constraints

- Every PHP file `declare(strict_types=1)`; braces always; explicit types; `#[Override]`; PHPDoc over inline comments; English code and docs.
- Neuron 3.17.2 is installed and is the source of truth for its own API. Before a step relies on a Neuron or `laravel/mcp` class, read the installed source and write the class and method names in the step's note. No name is taken from memory.
- Tests live in `Modules/AI/tests`; support classes in `tests/Stubs`, never declared in a test file. No live provider in any test. Never run tests with a cached config: `php artisan config:clear` first.
- Pint from the laraplate root with explicit files: `vendor/bin/pint --format agent <files>`. Never with an empty list.
- Schema changes fold into the create migration (pre-stable). Five-driver portability. Table names carry the `ai_` prefix.
- A switch or tuning value is a seeded setting (`AIDatabaseSeeder::runtimeSettingDefinitions()`), read as `config('ai....')`, with no env variable. No `env()` outside config files.
- **Privacy stays as the README states it:** the assistant collects no usage data. Task 2 changes the wording only through an explicit, default-off setting and keeps content out of the record.
- No dependency change without the owner's approval.
- Commits go to the repository that owns each file, explicit paths, never `git add -A`.

## Task 1: The evaluation baselines run in CI

**Files:** `.github/workflows/laravel.yml`, `Modules/AI/docs/rag/ASSISTANT_EVALUATION.md`, `Modules/AI/docs/rag/DOCUMENTATION_EVALUATION_DEVELOPER.md`.

- [ ] **Step 1: Read first.** `docs/superpowers/plans/2026-05-21-module-testing-strategy.md` (revised 2026-09-15 to one central runner) and `Modules/AI/docs/rag/ASSISTANT_EVALUATION.md`. The workflow change must follow the central-runner decision, not reopen it. Note the decision in this step.
- [ ] **Step 2: Reproduce F3.** Run the workflow's test step on a checkout without submodules and record that the AI gate tests are not collected. Evidence in the commit message.
- [ ] **Step 3: Make the workflow see the module.** `actions/checkout@v4` with `submodules: recursive`; PHP 8.5; keep SQLite. If the submodule URL needs a token in CI, say so in the step and stop for the owner.
- [ ] **Step 4: A named job for the gates.** A separate `ai-evaluation` job, path-filtered on `Modules/AI/**`, `Modules/Core/app/ApplicationContent/**` and `Modules/*/docs/rag/evaluations/**`. It runs the deterministic gates only: `AssistantBaselineGateTest` and the documentation and application-content baseline tests. No network, no key, no live model.
- [ ] **Step 5: Coverage of the datasets.** List the files in `Modules/*/docs/rag/evaluations/` and map each to a gate test. `assistant-injection.json` and `2026-07-in-app-security.json` are the ones to confirm. A dataset with no gate gets one, or a `- [-]` here with the reason.
- [ ] **Step 6: Readable failure.** A failing gate prints the metric, the baseline value and the measured value. If the existing gates only assert, add the message through the shared `EvaluationStatistics` code, not per test.
- [ ] **Step 7: Baseline update rule.** `ASSISTANT_EVALUATION.md` and `DOCUMENTATION_EVALUATION_DEVELOPER.md` say that a changed baseline is its own commit with the reason in the message. Add the sentence if missing.
- [ ] **Step 8: Run, format, commit.** Push a branch and read the CI result. The job must fail on a deliberately lowered metric (done on a scratch branch, not committed).

**Not in this task:** Level 2 live evaluation (`ai:evaluate-assistant --live`). It needs provider keys in CI secrets. It stays deferred; the owner decides when.

## Task 2: Run observability

**Files:** `Modules/AI/app/Ai/Agents/ChatAgent.php`, new `Modules/AI/app/Services/Observability/*`, new model, seeder settings, create migration, `Modules/AI/README.md` privacy section, `Modules/AI/docs/rag/MODULE.md`.

- [ ] **Step 1: Decide the record, then read Neuron.** Decisions to write into this step:
  Decided 2026-10-13 by the owner: the record is built, switched off by default, technical metadata only, as listed below. The Neuron reading is still to do, so the step stays open.
  - **What is stored:** run id, conversation id (nullable), feature, provider, model, input tokens, output tokens, latency in milliseconds, number of tool calls, tool names (names only, never arguments or results), outcome, created at.
  - **What is never stored:** prompts, answers, citations, tool arguments, tool results, user id, IP.
  - **Link to the user:** only through `conversation_id`. The existing purge observers (`PurgeDeletedConversationObserver`, `PurgeAssistantDataOfDeletedUserObserver`) then cover erasure.
  - **Default off**, retention in days, both seeded settings: `features.observability.enabled`, `features.observability.retention_days`.
  - Read the installed Neuron source: where token usage is exposed on a response, and whether the agent has an observer or event hook. Write the class and method names here. If usage is not exposed for a provider, the column is null for that provider and the step says which.
- [ ] **Step 2: Failing tests.** With the setting on, a scripted run through `ChatAgent` writes one row with tokens, latency and outcome. With it off, no row. A store that throws does not change the answer (the pattern of `RagQueryAnalyticsWriter`: it costs a log line, not an answer). The row has none of the forbidden columns. Deleting the conversation deletes its rows. A policy violation, a tool-runs-exceeded stop and a provider error each map to their own outcome.
- [ ] **Step 3: Table and model.** `ai_agent_runs` in the AI create migration. Indexes on `created_at` and `conversation_id`. Outcome as a backed enum `AgentRunOutcome` with TitleCase keys.
- [ ] **Step 4: One hook for every feature.** The recorder sits where every feature already passes: `ChatAgent` (`ask()` and `chat()`), so assistant turns, text generation, translation, moderation and suggestions are all counted by one change. It does not wrap the callers.
- [ ] **Step 5: Settings, retention and prune.** Seeded settings with the module's definition style. A command `ai:agent-runs:prune` modelled on `PruneRagQueryAnalyticsCommand`, scheduled with the module's other prune commands.
- [ ] **Step 6: A read-only view.** One Filament widget or table of runs per feature, model and outcome, with p50 and p95 latency and tokens per day. Follow `.ai/rules/filament.md` and the `filament-development` skill. If it grows past one widget, cut it and note it as a follow-up here.
- [ ] **Step 7: No price table.** Cost is not computed. Provider prices change and are not in the code. If the owner wants cost, a per-model price setting is a separate step, decided then.
- [ ] **Step 8: Docs.** The README privacy section gets a dated note: with the setting on, the instance keeps technical run metadata and no content. `docs/rag/MODULE.md` and the developer doc describe the columns and the setting. Add the setting to the Filament settings docs where the others are listed.
- [ ] **Step 9: Run the touched tests and the settings tests (`AiRuntimeSettingDefinitionsTest`, `RuntimeSettingNameLengthTest`), format, commit.**

## Task 3: Provider hygiene

**Files:** `Modules/AI/app/Ai/Providers/ProviderFactory.php`, `AiModelChoice`, `AiModelFeature`, tests `AiModelChoiceTest`, `AiModelFeatureWiringTest`.

- [ ] **Step 1: Read first.** Where can `$model` reach `createAnthropic()` as null? `AiModelChoice::parse()` returns a null model for a value with no colon, and `forFeature()` falls back to `AiModelFeature::defaultChoice()`. List each feature's default choice for `anthropic` and the callers that pass no model.
- [ ] **Step 2: Failing test.** Building an Anthropic provider with no resolvable model fails with `ConfigurationException`, as the other providers fail without a key. No hardcoded model id remains in `ProviderFactory`.
- [ ] **Step 3: Remove `ANTHROPIC_DEFAULT_MODEL`.** If Step 1 shows a feature depends on it, that feature's `defaultChoice()` names a model, or the Settings choice is made mandatory for it. The choice is recorded here. A pinned old model id is a stale default: the catalogue refreshed by `ai:models:refresh` is the source.
- [ ] **Step 4: Docs.** `AI_MODEL_SELECTION_DEVELOPER.md` and `_USER.md`, wherever they name the fallback.
- [ ] **Step 5: Prompt caching, measured first.** After Task 2 has run for a week on a real instance, read input tokens per turn. The assistant system prompt is a static policy followed by a per-turn `authorized_context` block (`ChatService::buildProtectedAgent`), so only the static prefix is cacheable. Check in the installed Neuron source whether the Anthropic provider can mark a cache breakpoint. Build it only if the source supports it and the measured input tokens justify it. Otherwise mark this step `- [-]` with the numbers.
- [ ] **Step 6: Run the touched tests, format, commit.**

**Deferred, not planned:** Azure OpenAI. `ProviderFactory` has no case for it, and Neuron's provider list in 3.17.2 has to be read before anyone says it is supported. Enterprise customers ask for it. It becomes its own plan if the owner wants it.

## Task 4: Documentation and closing

- [ ] **Step 1: Module docs.** Every behaviour of Tasks 1 to 3 is in `Modules/AI/docs/rag/` and the README, in the module's existing style. Added settings are listed with their defaults.
- [ ] **Step 2: Index.** Add this plan to `docs/superpowers/plans/INDEX.md` (done when the plan was written) and update its line when it closes.
- [ ] **Step 3: Close.** Add the `## Delivery status (date)` section with every divergence, add the `**Documented in:**` line naming the module docs, set `status: completed`. `ClosedPlansPointToDocumentationTest` enforces the last two.
