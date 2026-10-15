---
status: open
created_on: 2026-10-12
---
# Neuron AI 4 Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `Modules/AI` runs on `neuron-core/neuron-ai` `^4.1` with the same behaviour it has on 3.17.2, proven against a baseline taken before the upgrade.

**Architecture:** The vendor's upgrade guides (`vendor/neuron-core/neuron-ai/upgrade/NN-*.md`, after Task 2) are applied one at a time in numeric order, only the fifteen that apply, each with its own tests and commit. Each task below names its guide: the guide carries the how (patterns, before/after, checklist), this plan carries what we decided where the guide leaves a choice.

**Tech Stack:** PHP 8.5, Laravel 12, Pest, Neuron AI 4.1.x, Elasticsearch 8/9.

**Spec:** `docs/superpowers/specs/2026-10-12-neuron-ai-4-upgrade-design.md`

## Global Constraints

- Work on a branch named `neuron-4` in `Modules/AI` and in `laraplate` (lock and submodule pointer). Merge both only in Task 13, when the suite matches the baseline.
- Apply the guides in numeric order, one per task; never two at once. A symbol another guide migrates is left alone until that guide's task (the guide lists them).
- Behaviour does not change: tool names and descriptions, stored ids and index mappings, prompts, citations and refusals stay as they are on 3.17.2.
- No new dependencies and no `composer.json` change outside `Modules/AI` (spec Decision 7); the root `composer.lock` follows. No new base folders: the skills go in the tracked `.agents/skills/`.
- Never declare a class inside a test file: stubs go in `Modules/AI/tests/Stubs/` (PSR-4 `Modules\AI\Tests\Stubs\`).
- Every PHP file `declare(strict_types=1);`, `#[Override]` where overriding, `final`/`readonly` where siblings use them.
- Tests from the `laraplate` root, never with a cached config: `php artisan test --compact <paths>`; the whole module with `vendor/bin/pest Modules/AI/tests --parallel --compact`. The single-process full suite runs out of memory: use `vendor/bin/pest --parallel --compact`.
- Pint from the root on an explicit file list: `vendor/bin/pint --format agent <files>`. PHPStan: `vendor/bin/phpstan analyse <files>`. Fix errors, never silence them.
- Between Task 2 and Task 13 the module suite is not green: guides not yet applied break other tests. Each task runs its own tests and the tests the guide's checklist names; they must pass.
- Tick each step when it is done, in the same work block.

## Review Focus

- A model sends an integer for a tool argument declared `string` (a CRUD `id`): the tool must still run (Task 6 test).
- The 3.x documentation index, written before the upgrade, is searched by the 4.x store: the hits must come back with their content, source and metadata (Task 9 test).
- Every place that builds an agent (`ChatService`, `LlmSearchService`, `ModerationService`, `NeuronVisionAnalyzer`, `AiTranslationService`, `ConversationTitleService`, `ContextualSuggestionService`, `HandleAiTextGenerationListener`, `MemoryService`, `DocumentationService`) runs a call without "This agent has no thread ID" (Task 7 Step 3: one test per service through its real agent, added where missing).
- A document reaches the Elasticsearch store without an embedding: it is refused, not indexed with an empty vector (Task 10 test).
- A retrieved document with no score: the citation carries `score: null` and nothing fails (Task 10 test).

---

### Task 0: Baseline on 3.17.2

**Files:**
- Modify: this plan (the *Baseline* section at the end)

- [ ] **Step 1:** Record `composer show neuron-core/neuron-ai | grep versions` (expected `3.17.2`).
- [ ] **Step 2:** Run `vendor/bin/pest --parallel --compact` and record the totals (passed, skipped, failed) and the name of any failing test, in *Baseline*.
- [ ] **Step 3:** Run `vendor/bin/phpstan analyse Modules/AI --no-progress --error-format=json` and record the error total in *Baseline*.
- [ ] **Step 4:** Run the evaluation gates and record their result: `php artisan test --compact Modules/AI/tests/Feature/DocumentationBaselineGateTest.php Modules/AI/tests/Feature/DeveloperDocumentationBaselineGateTest.php Modules/AI/tests/Feature/Assistance/AssistantBaselineGateTest.php tests/Integration/ApplicationContent`. The committed reports under `Modules/AI/docs/rag/evaluations` and `docs/rag/evaluations` are the reference: Task 13 checks none changed.
- [ ] **Step 5:** Commit the plan: `docs: neuron 4 upgrade baseline`.

### Task 1: Branch and agent skills (guide 0)

- [ ] **Step 1:** Create the branch `neuron-4` in `Modules/AI` and in `laraplate` from their `master`.
- [ ] **Step 2 (after Task 2's `composer update`, since the skills ship in the 4.x package):** run `npx skills add ./vendor/neuron-core/neuron-ai/skills -y` from the `laraplate` root; the skills land in `.agents/skills/`. Commit them on the branch: `chore: install the Neuron 4 agent skills`.
- [ ] **Step 3:** Guide 0 Step 4: stop and tell the owner to continue in a new session, so the new skills load.

### Task 2: Composer (guide 1)

**Files:**
- Modify: `Modules/AI/composer.json`, `composer.lock`

- [ ] **Step 1:** In `Modules/AI/composer.json`: `"neuron-core/neuron-ai": "^4.1"`. No other `composer.json` changes (spec Decision 7).
- [ ] **Step 2:** From the root: `composer update neuron-core/neuron-ai -W`. Expected: `neuron-core/neuron-ai` 4.1.x installed, `inspector-apm/inspector-php` removed (nothing else requires it), `guzzlehttp/guzzle` still installed (required by `laravel/framework`), nothing else downgraded. Record the package diff of `composer.lock` in the commit message.
- [ ] **Step 3:** Run Task 1 Step 2.
- [ ] **Step 4:** Commit in `Modules/AI` and the root lock: `chore(ai): require neuron-ai 4.1`.

### Task 3: HTTP client layer (guide 2)

**Files:**
- Modify: `Modules/AI/tests/Stubs/RecordingHttpClient.php`, `Modules/AI/tests/Integration/AiTextGenerationModelBindingTest.php`

- [ ] **Step 1:** Apply guide 2 Case 7 to `RecordingHttpClient`: drop `withBaseUri()`, `withHeaders()`, `withTimeout()`; a recorded response with status 400 or above throws `HttpException::statusError()`.
- [ ] **Step 2:** `AiTextGenerationModelBindingTest` asserts the absolute Ollama chat URL instead of `'chat'` (read it from the 4.x Ollama provider, do not guess).
- [ ] **Step 3:** Run `AiTextGenerationModelBindingTest.php`, `AgentRoundTripTest.php`, `AiModelFeatureWiringTest.php`; they pass as far as later guides allow. Commit: `test(ai): the recording HTTP client follows the Neuron 4 contract`.

### Task 4: Tools are classes (guide 3)

**Files:**
- Create: `Modules/AI/app/Services/Tools/CallbackTool.php`
- Modify: `Modules/AI/app/Services/Tools/ToolRegistry.php` (`buildNeuronTool()`, `buildNeuronToolStructure()`)
- Test: `Modules/AI/tests/Integration/ToolRegistryTest.php`

**Interfaces:**
- Produces: `final class CallbackTool extends NeuronAI\Tools\Tool`, constructed with `(string $name, string $description, Closure $handler)`; `public function __invoke(mixed ...$arguments): mixed` calls the handler with the arguments by name. `ToolRegistry::buildNeuronTool(ToolDefinition): Tool` returns a `CallbackTool`, with the same `setMaxRuns()` and `addProperty()` calls as today.

- [ ] **Step 1:** Add to `ToolRegistryTest`: `it('builds every tool as a callable class with the definition name, description and properties')` — for a `ToolDefinition` with two parameters, the built tool is a `CallbackTool`, `getName()`/`getDescription()` equal the definition's, its properties are the two, and executing it with both arguments returns the handler's result.
- [ ] **Step 2:** Run it: fails (`CallbackTool` missing, or `Cannot instantiate abstract class`).
- [ ] **Step 3:** Implement `CallbackTool` and switch `ToolRegistry` to it (guide 3 Case 2). Leave `ChatAgentTest.php:86` to Task 5.
- [ ] **Step 4:** Run `ToolRegistryTest.php` and the guide's checklist; pass. Commit: `refactor(ai): tools are built as a CallbackTool class (Neuron 4)`.

### Task 5: The ToolCall value object (guide 4)

**Files:**
- Modify: `Modules/AI/app/Ai/Agents/ChatAgent.php` (`resolveToolErrorHandler()`), `Modules/AI/tests/Stubs/Assistance/ToolCallingFakeProvider.php`, `Modules/AI/tests/Feature/Assistance/AgentEndpointTest.php`, `Modules/AI/tests/Integration/ChatAgentTest.php`

- [ ] **Step 1:** `ChatAgent::resolveToolErrorHandler()` returns `Closure(Throwable, ToolCall): string|ToolOutput|null`; it still rethrows `AssistancePolicyViolationException` and returns the same safe text for anything else. Drop the `ToolInterface` import.
- [ ] **Step 2:** The fake provider builds its calls with `ToolCall::make(name:, callId:, inputs:)`; `AgentEndpointTest` reads `getToolCalls()[0]`; `ChatAgentTest` builds a `ToolCall` instead of `Tool::make('lookup', …)`.
- [ ] **Step 3:** Run `ChatAgentTest.php` and `AgentEndpointTest.php` (the missing-argument test may still fail until Task 6). Commit: `refactor(ai): tool errors and fake tool calls use Neuron 4's ToolCall`.

### Task 6: Tool properties validate and cast (guide 5)

**Files:**
- Modify: `Modules/AI/app/Services/Tools/SchemaToolProperty.php`
- Test: `Modules/AI/tests/Unit/Services/Tools/SchemaToolPropertyTest.php` (create if absent), `Modules/AI/tests/Feature/Assistance/AgentEndpointTest.php`

**Interfaces:**
- Produces: `SchemaToolProperty::isNullable(): bool` (false: no schema parameter is nullable today) and `SchemaToolProperty::cast(mixed $input): mixed`, lenient as spec Decision 4 states.

- [ ] **Step 1:** Write the tests: `cast()` turns `'42'` into `42` for `integer`, `'1.5'` into `1.5` for `number`, `42` into `'42'` for `string`, `'true'`/`1` into `true` and `'false'`/`0` into `false` for `boolean`; it returns `'abc'` unchanged for `integer`; arrays and objects pass through. The Review Focus case: a tool whose `id` is declared `string` runs when the model sends `42`.
- [ ] **Step 2:** Run: fail. Implement `isNullable()` and `cast()`. Run: pass.
- [ ] **Step 3:** `AgentEndpointTest` "tool without a required argument": the model now receives Neuron's error output for the missing parameter and the error handler is not called. Assert that the stored answer is the model's next reply and that no exception text reaches the user.
- [ ] **Step 4:** Run both files; pass. Commit: `feat(ai): schema tool properties cast leniently (Neuron 4)`.

### Task 7: A thread id for every agent (guide 13)

**Files:**
- Modify: `Modules/AI/app/Ai/Agents/ChatAgent.php`, `Modules/AI/app/Ai/Agents/DocumentationAgent.php` (constructors)
- Test: `Modules/AI/tests/Integration/ChatAgentTest.php`, `Modules/AI/tests/Integration/DocumentationServiceTest.php`

- [ ] **Step 1:** Write the test: two `ChatAgent` instances have distinct, non-empty `getWorkflowId()`; one runs `chat()` on `FakeAIProvider` without "This agent has no thread ID". The same for `DocumentationAgent`.
- [ ] **Step 2:** Run: fail. Both constructors call `parent::__construct(workflowId: (string) Str::uuid7())`; replace the comment about the executor with one line on why the id is generated (the application keeps its own conversations).
- [ ] **Step 3:** For every service in Review Focus line 3, find the test that runs one call through the service's real agent on `FakeAIProvider` (or `FakeVectorStore` for `DocumentationService`). Where none exists, add one in that service's test file: `it('runs a call through a Neuron 4 agent with a workflow id')`, asserting the call returns and the provider recorded one request. List in this step's line which services already had one and which got a new test.
- [ ] **Step 4:** Run those tests; they pass as far as later guides allow (Document and SystemMessage failures belong to Tasks 10 and 11). Commit: `fix(ai): every agent runs with its own workflow id (Neuron 4)`.

### Task 8: Branch runners (guide 17)

- [ ] **Step 1:** `ChatAgentTest.php:9-17` reflects on `Workflow::$executor`, which 4.x removed. Replace it with the assertion it was protecting: a constructed agent runs a `chat()` (covered by Task 7), and delete the reflection test. Fix the stale constructor comment in `DocumentationAgent` if Task 7 did not.
- [ ] **Step 2:** Run `ChatAgentTest.php`; pass. Commit: `test(ai): drop the reflection on the removed workflow executor`.

### Task 9: Vector store contract (guide 19)

**Files:**
- Modify: `Modules/AI/app/Ai/Rag/ElasticsearchRagVectorStore.php`, `Modules/AI/app/Ai/Rag/Retrieval/DeveloperDocumentationRetrieval.php`
- Test: `Modules/AI/tests/Unit/Ai/Rag/ElasticsearchRagVectorStoreTest.php`

**Interfaces:**
- Produces: `getSchema(): DocumentSchema`; `delete(FilterExpression $filters): VectorStoreInterface` translating the source filters Neuron sends (`sourceType`, `sourceName`) into today's term query; `search(SearchRequest $request): iterable` running today's kNN query with the request's embedding and top K. The private `search(array, ?array)` is renamed `knnSearch()`. `similaritySearchForContext()` stays as it is (spec Decision 5).

- [ ] **Step 1:** Rewrite the four store tests that call `similaritySearch()`/`deleteBy()` against `search(SearchRequest)`/`delete(FilterExpression)` with the same expected Elasticsearch requests; add the Review Focus test: a hit shaped as 3.x wrote it (the current `documentToSource()` fields) maps back to a `Document` with its content, source and metadata.
- [ ] **Step 2:** Run: fail. Implement per guide 19 Case 5; `DeveloperDocumentationRetrieval` calls `search()` with a `SearchRequest`.
- [ ] **Step 3:** Answer guide 19's "Data stored by 3.x" check for the documentation indexes and write the answer in this step's line. Run the store, retrieval and RAG rebuild tests; pass. Commit: `refactor(ai): the Elasticsearch RAG store implements the Neuron 4 search contract`.

### Task 10: Document accessors (guide 20, and guide 33's check)

**Files:**
- Modify: the files guide 20's searches list (the triage found about 13 in `Modules/AI/app` and 30 in `Modules/AI/tests`, led by `ElasticsearchRagVectorStore`, `PrefixingEmbeddingsProvider`, `SentenceTransformersEmbeddingsProvider`, `MarkdownAwareSplitter`, `FileDocumentReader`, `DocumentationService`)
- Delete: `Modules/AI/tests/Stubs/Embeddings/DocumentWithArrayFormattedContent.php` and the test case that uses it (`tests/Integration/SentenceTransformersEmbeddingsProviderTest.php`)

- [ ] **Step 1:** Write the Review Focus tests: `ElasticsearchRagVectorStore::addDocument()` with a document whose embedding is null throws and sends nothing to Elasticsearch; a citation built from a document with no score has `score: null` (`DocumentationService` and `InAppAssistanceService` citations).
- [ ] **Step 2:** Run: fail. Apply guide 20 to every file its searches list: property reads become getters, writes become setters or `addMetadata()`, `PrefixingEmbeddingsProvider` embeds a new `Document` with the prefixed text and copies the embedding back, embeddings read from Elasticsearch are cast to float, the store refuses a document without an embedding (spec Decision 6), `SentenceTransformersEmbeddingsProvider` reads `getContent()` only.
- [ ] **Step 3:** Guide 33: confirm chunk ids are only stored as the `neuron_id` keyword and never parsed; write the result in this step's line.
- [ ] **Step 4:** Run the module's RAG, embedding, documentation and assistant tests; pass. Commit: `refactor(ai): documents are read and written through Neuron 4 accessors`.

### Task 11: System messages and configured setters (guides 24 and 25)

- [ ] **Step 1:** Guide 24: tests that read `RequestRecord::$systemPrompt` as a string read `?->getContent()` (`HandleAiTextGenerationListenerTest.php:48`, `AgentEndpointTest.php:227,237`, `ConversationTitleTest.php:180`); `DocumentationServiceTest.php:445,457` read the RAG context from the first user message's content instead of the system prompt. Any agent `instructions()` returning a string returns a `SystemMessage` with the same text.
- [ ] **Step 2:** Guide 25: `DocumentationAgent::postProcessors()` stops spreading `parent::postProcessors()`.
- [ ] **Step 3:** Run the four test files and `DocumentationServiceTest.php`; pass. Commit: `refactor(ai): instructions are system messages (Neuron 4)`.

### Task 12: Provider responses and testing fakes (guides 41 and 56)

- [ ] **Step 1:** Guide 41: `ToolCallingFakeProvider::chat()` returns `ProviderResponse` (wrap its `ToolCallMessage`, return `parent::chat()` unchanged); `AiTextGenerationModelBindingTest.php:24` reads `->message()->getContent()`.
- [ ] **Step 2:** Guide 56: check that `DocumentationServiceTest`'s `FakeVectorStore` canned hits (around lines 410 and 463) still come back unchanged; adjust to the 4.x constructor if they do not.
- [ ] **Step 3:** Run `AgentEndpointTest.php`, `AiTextGenerationModelBindingTest.php`, `DocumentationServiceTest.php`; pass. Commit: `test(ai): fakes and provider calls follow Neuron 4`.

### Task 13: Verify against the baseline, document, merge

**Files:**
- Modify: `Modules/AI/docs/GLOSSARY.md`, `Modules/AI/docs/rag/GLOSSARY.md` (Neuron "v3" becomes "v4"), `Modules/AI/README.md` if it names the Neuron version, `docs/superpowers/plans/2026-09-16-laravel-13-upgrade.md` (its Task 2 Neuron constraint step marked `- [-]` with the reason), this plan

- [ ] **Step 1:** Re-run every guide's search patterns for the fifteen guides: no 3.x usage left.
- [ ] **Step 2:** `vendor/bin/pest --parallel --compact`: totals equal the baseline (a failing test of the baseline may still fail; any new failure is fixed). `vendor/bin/phpstan analyse Modules/AI`: no error above the baseline count. The evaluation gates of Task 0 Step 4 pass and `git status` shows no change under any `docs/rag/evaluations`.
- [ ] **Step 3:** Run guide `AGENTS.md`'s conclusion: activate the `neuron-laravel-integration` skill, walk its foundations checklist, and write what differs from the recommended setup in the delivery status (recommendations only; no change in this plan).
- [ ] **Step 4:** Update the docs listed above.
- [ ] **Step 5:** Delivery status, `**Documented in:**` line, `status: completed`, the plans and specs `INDEX.md` entries; `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php` passes.
- [ ] **Step 6:** Merge `neuron-4` into `master` in `Modules/AI` and the other touched modules, then in `laraplate` with the submodule pointers; push after the owner's go.

## Baseline

(Filled by Task 0.)
