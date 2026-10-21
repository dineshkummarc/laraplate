---
status: closed
created_on: 2026-10-20
---
# Self-hosted AI services, client side, Phase 1. Implementation Plan

> **For agentic workers:** execute task by task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Status:** Closed 2026-10-21, see *Delivery status*.

**Goal:** every call Laraplate makes to `sentence-transformers-api` or `whisper-api` carries a request id that the failure log line repeats, and a contract test keeps the clients aligned with the services' OpenAPI documents.

**Architecture:** one small helper in `Modules/AI` builds the request id and the log context; the four clients use it. The services' `openapi.json` files are copied as test fixtures, with the service version they come from; Pest tests check the clients against them.

**Tech Stack:** PHP 8.5, Laravel 12, Pest, the `Http` facade and Guzzle (`SentenceTransformersEmbeddingsProvider`). No new dependency.

**Spec:** `../specs/2026-10-20-self-hosted-ai-services-client-design.md`, section 3: R4, R7 (Phase 1 of section 6). Service side: the Phase 1 plans of `sentence-transformers-api` and `whisper-api`.

## Global Constraints

- Work in `Modules/AI` only; commit in the `Modules/AI` submodule. The plan file and the indexes are committed in `laraplate`. Never bump submodule pointers.
- `declare(strict_types=1)`, `final`, explicit types, PHPDoc over inline comments; `vendor/bin/pint --dirty` before each commit.
- Tests in `Modules/AI/tests`, Pest, `Http::fake()` or the existing `RecordingHttpClient` stub; no class declared in a test file.
- Run `php artisan config:clear` before the tests (never with a cached config); `php artisan test --compact` with the narrowest path.
- No secret, text, query, transcript or file name in a log line.
- The HTTP payloads the clients send do not change, except the new header.

## Decisions taken in this plan

- **P1. One request id per HTTP call**, 32 lowercase hex characters (`bin2hex(random_bytes(16))`), sent as `X-Request-Id`. A retry of `CrossEncoderService` (`retry(2, 200)`) reuses the id of its first attempt, so the service log shows the attempts together.
- **P2. Failure log.** Where a client fails (exception or non-2xx), it logs once at `warning` with `service` (`sentence_transformers`, `cross_encoder`, `whisper`), `endpoint`, `status` (or `null` when unreachable) and `request_id`. `WhisperTranscriber` gains this line: today it returns `null` silently. `SentenceTransformersEmbeddingsProvider` and `EmbeddingServiceIdentity` attach the request id to the exception they throw, so the job log carries it.
- **P3. `api_version` warning** only where Laraplate already reads `/health`: `EmbeddingServiceIdentity::healthModel()`. An `api_version` other than `1` logs a warning once per process. The cross-encoder and Whisper clients do not call `/health`; the Filament panel (E6) covers them later. This narrows the spec's R7 bullet and is recorded in its delivery.
- **P4. Fixtures.** `Modules/AI/tests/Fixtures/SelfHostedServices/sentence-transformers-api.openapi.json` and `whisper-api.openapi.json`, each with a sibling `.version` file holding the service `VERSION` it was copied from. Updating them is a manual step of a service release, written in the module docs.

## Review Focus

- The request id of a `CrossEncoderService` retry is the same across attempts (P1).
- `WhisperTranscriber` still returns `null` on every failure and never throws (its contract), now with a log line (P2).
- No file name, path or text in the new log lines.
- The contract test fails when a client sends a field the service schema does not know or omits a required one, and when a client reads a field the response schema does not declare.

---

### Task 1: Request id helper and failure logs (R4)

**Files:** create `Modules/AI/app/Ai/SelfHosted/ServiceRequestId.php`; modify `Modules/AI/app/Ai/Embeddings/SentenceTransformersEmbeddingsProvider.php`, `Modules/AI/app/Ai/Embeddings/EmbeddingServiceIdentity.php`, `Modules/AI/app/Services/CrossEncoderService.php`, `Modules/AI/app/Ai/MediaAnalysis/Transcription/WhisperTranscriber.php`; tests in `Modules/AI/tests/Integration/CrossEncoderServiceTest.php`, `Modules/AI/tests/Integration/SentenceTransformersEmbeddingsProviderTest.php`, `Modules/AI/tests/Feature/MediaAnalysis/WhisperTranscriberTest.php` and a new unit test of the helper.

- [x] **Failing tests.** Each client sends `X-Request-Id` of 32 hex characters; a `CrossEncoderService` call that fails twice then succeeds sends the same id three times; a `WhisperTranscriber` call answered `503` returns `null` and logs one warning with `service`, `endpoint`, `status`, `request_id` and no file name (`Log::spy()`); a `SentenceTransformersEmbeddingsProvider` batch answered `500` throws an exception whose message or context carries the request id.
- [x] **Implement** P1 and P2. Check whether the existing callers of `CrossEncoderService` already log the rerank failure; if so, add the request id to that line instead of a second one.
- [x] **Run** `php artisan test --compact Modules/AI/tests/Integration/CrossEncoderServiceTest.php Modules/AI/tests/Integration/SentenceTransformersEmbeddingsProviderTest.php Modules/AI/tests/Feature/MediaAnalysis/WhisperTranscriberTest.php` and the helper test; `vendor/bin/pint --dirty`; commit in `Modules/AI` `feat: send a request id to the self-hosted AI services`.

### Task 2: Contract fixtures and tests (R7)

Waits for Task 4 of the `sentence-transformers-api` plan and Task 5 of the `whisper-api` plan.

**Files:** create the fixtures of P4 and `Modules/AI/tests/Integration/SelfHostedServicesContractTest.php` with its helper in `Modules/AI/tests/Stubs/` (no class in the test file).

- [x] **Copy** the two `openapi.json` files and their `VERSION` values into the fixtures.
- [x] **Failing tests.** With `Http::fake()` recording requests: the bodies `SentenceTransformersEmbeddingsProvider` sends to `/embed` (single and batch) and `CrossEncoderService` sends to `/score` carry every required field of the request schema and no undeclared one; the multipart field of `WhisperTranscriber` is `file`. Fed the example answers of each schema, each client parses them without error; fed an example lacking a field the client reads, the test shows the client depends on it.
- [x] **Implement** whatever the tests reveal; nothing is expected beyond the helper.
- [x] **Run** the new test; pint; commit in `Modules/AI` `test: check the self-hosted AI clients against the service contracts`.

### Task 3: `api_version` check (R7)

Waits for the services to report `api_version` (Task 4 of the `sentence-transformers-api` plan).

**Files:** modify `Modules/AI/app/Ai/Embeddings/EmbeddingServiceIdentity.php`; test next to its existing tests.

- [x] **Failing tests.** `/health` with `api_version: 1`: no warning; with `2`: one warning naming the expected and the received version, once per process; without the field (an older service): no warning.
- [x] **Implement** P3.
- [x] **Run** the test; pint; commit in `Modules/AI` `feat: warn when the embedding service speaks an unknown API version`.

### Task 4: Documentation and closing

**Files:** modify `Modules/AI/docs/SENTENCE_TRANSFORMERS_INSTALLATION.md`, `Modules/AI/docs/WHISPER_INSTALLATION.md`, the relevant `Modules/AI/docs/rag/` developer page, `../specs/2026-10-20-self-hosted-ai-services-client-design.md`, `INDEX.md` files, this plan.

- [x] **Docs.** The request id in both guides (how to find a failed call in the service log); `/ready` as the healthcheck; the fixture update step for a service release in the developer docs; the guides' status lines follow the services' readiness.
- [x] **Spec.** Status line points to this plan and records Phase 1 delivered; note P3 against R7.
- [x] **Close.** Delivery status with divergences, `**Documented in:**`, ticks, `status: closed`; commit the plan in `laraplate` `docs: close the client phase 1 plan`.

## Delivery status (2026-10-21): shipped

All four tasks are built, in `Modules/AI` (`laraplate-ai` 248591b, b617ddc, 2635abf, 8135f69, 10ba448 and 9f7b206 from the final review; `laraplate-core` c0a4292d), against Phase 1 of `sentence-transformers-api` and `whisper-api` (both `VERSION` 0.1.0, `api_version` 1). Divergences from the plan, each with its reason:

- **P1, retries.** `retry(2, 200)` is two attempts in Laravel, not three: the test sends a `503` then a `200` and checks the two requests carry the same id. The id is set once on the pending request, so every attempt shares it.
- **P2, the failure.** A failure is a call that cannot reach the service or gets an error status. `SentenceTransformersEmbeddingsProvider`, `EmbeddingServiceIdentity` and `CrossEncoderService` throw a new `Ai/SelfHosted/SelfHostedServiceException` (a `RuntimeException` whose previous is the HTTP exception), with the id at the start of its message and in its `context()`. `CrossEncoderService` therefore no longer throws `RequestException`; its only caller, Core's `EnsembleSearchService`, catches `Throwable`. A `2xx` answer the client cannot use keeps its own exception without an id: the service log shows that call as a success.
- **P2, the cross-encoder log.** `EnsembleSearchService` already logs the rerank failure with the exception message, so the id rides in that message and the client adds no second line. Core then logged the id only in the line's `error` text; a follow-up (`laraplate-core` c0a4292d) merges the `context()` of any reranker exception into that warning, generically, so `request_id` and `status` are keys of it too.
- **P2, the endpoint.** The log line names the full URL of the call without credentials, query or fragment (spec R4 asks for the service URL), not the path alone.
- **P3, once per process.** Laravel `once()` keys the warning on its call site, the endpoint and the received version: one warning per process for each unknown version a service reports.
- **Task 1 test of the embeddings provider.** The `500` case drives `embedText()`: it takes the same call as a batch, without the adaptive batcher's retries and their real sleeps.
- **Task 2 contract test.** The schema helper (`tests/Stubs/SelfHostedServiceContract`) treats an object schema without `additionalProperties` as closed, so an undeclared field the client sends fails. Dependence on a field is proven for the fields a client cannot do without (`embeddings`, `scores`, `text`); the optional reads (`model`, `api_version`) are checked as declared in the answer schemas. The `.version` files are `<service>.version`, next to `<service>.openapi.json`.
- **Final review fixes (10ba448).** The contract test also feeds each client an answer reduced to its schema's required fields and checks every example against its own schema, so a client that needs a field the service does not promise fails; `WhisperTranscriber` returns `null` even when the log cannot be written; the embeddings provider logs the URL Guzzle really calls (a base URL with a path and no trailing slash loses its last segment, as before this plan).
- **Review minors (9f7b206, c0a4292d).** The exception message keeps the HTTP exception's reason with every URL reduced to scheme, host, port and path; `WhisperTranscriber` logs a client-side error apart (`Self-hosted AI service call could not be handled`, by class only); an `api_version` of `"1"` or `1.0` counts as `1`; the contract helper checks `pattern` and a `null` const and refuses keywords it does not check; the contract test reads the API version from `EmbeddingServiceIdentity::API_VERSION` and lets optional multipart fields through; the batcher's burst of warnings is documented. `embedDocuments()` declares Neuron's `Document[]` contract (any keys, renumbered) and drops a redundant `array_values` reported by PHPStan.
- **Task 3 tests** live in a new `tests/Unit/Ai/Embeddings/EmbeddingServiceIdentityTest.php`: the class had no direct test before.

**Documented in:** `Modules/AI/docs/SENTENCE_TRANSFORMERS_INSTALLATION.md` (Verify the embedding host, Tracing a failed call), `Modules/AI/docs/WHISPER_INSTALLATION.md` (HTTP contract, Tracing a failed call), `Modules/AI/docs/rag/MODULE.md` (Self-hosted AI services: request id, API version, contract test and the fixture update of a service release), `Modules/AI/docs/rag/MEDIA_ANALYSIS_DEVELOPER.md` (Failure), `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md` (the `Reranker failed` warning).
