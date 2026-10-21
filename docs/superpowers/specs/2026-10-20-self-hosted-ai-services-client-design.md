# Self-hosted AI services: the Laraplate client side

**Status:** Draft, 2026-10-20. Phase 0 delivered on 2026-10-20 (`laraplate-ai` c2530f0). Phase 1 (R4, R7) delivered on 2026-10-21: `../plans/2026-10-20-self-hosted-ai-services-client-phase-1.md` (closed). The `api_version` check of R7 runs only where Laraplate already reads `/health`, `EmbeddingServiceIdentity` (plan decision P3); the cross-encoder and Whisper clients get it with the Filament panel (E6).
**Scope:** `Modules/AI`: `SentenceTransformersEmbeddingsProvider`, `EmbeddingServiceIdentity`, `CrossEncoderService`, `WhisperTranscriber`, the embeddings provider factory, the install guides and a Filament status panel.
**Siblings:** the services own the rest of the work: [`sentence-transformers-api`](https://github.com/getlaraplate/sentence-transformers-api/blob/master/docs/superpowers/specs/2026-10-20-production-readiness-design.md) and [`whisper-api`](https://github.com/getlaraplate/whisper-api/blob/master/docs/superpowers/specs/2026-10-20-production-readiness-design.md). The three specs share the decision and requirement numbers (D, R, E), so a number means the same thing in each.
**History:** split on 2026-10-20 from one spec at the root of the Laraplate workspace, so that each repository owns the design of its own work.

## 1. Goal

The two Python services are free, self-hosted infrastructure that the customer
installs next to Laraplate (sibling specs, D1 to D5). This spec covers what
Laraplate itself must do so that:

- the customer knows whether the services are ready for production;
- a failure can be traced from a Laraplate log line to a service log line;
- the client keeps matching the services' HTTP contract as they evolve;
- the customer can swap a Laraplate service for an equivalent server or a cloud API with only `.env` changes.

## 2. Decisions

D1 to D5 are in the sibling specs. The ones that bind this side:

| # | Decision |
|---|---|
| D1 | The customer installs and runs the services; Laraplate does not host them. |
| D3 | CPU is the baseline; GPU where tested. Nothing on the Laraplate side depends on it. |
| D5 | The readiness label ships first, in the services and in the Laraplate guides. |

## 3. Requirements

### Readiness in the guides (section 4 of the sibling specs)

Delivered in Phase 0: `Modules/AI/docs/SENTENCE_TRANSFORMERS_INSTALLATION.md` and
`WHISPER_INSTALLATION.md` open with the status of the service and link the
Production readiness section of its README. They change with the services'
status, in the same work block.

### R4. Request id

- Every call to a self-hosted service sends an `X-Request-Id`, and the client logs it, with the service URL and the status, when the call fails.
- Applies to `SentenceTransformersEmbeddingsProvider`, `EmbeddingServiceIdentity`, `CrossEncoderService` and `WhisperTranscriber`.

### R7. Contract test

- Each service repository ships an OpenAPI document of its endpoints (sibling specs, R7).
- `Modules/AI` gets a contract test that checks the requests its clients build, and the answers they parse, against fixtures generated from those documents, so a change on either side that breaks the other fails a test.
- The client reads `api_version` from `/health` and logs a warning when the service speaks a version it does not know.

## 4. Evolutions

### E1. Standard protocols (highest leverage)

The services add standard endpoints next to their own (sibling specs, E1). The client learns the same protocols:

- an OpenAI-compatible embeddings provider that honours a base URL (today `OPENAI_BASE_URL` is read for chat only), so `/v1/embeddings` of this service, Text Embeddings Inference or a cloud API are interchangeable;
- a Text Embeddings Inference rerank mode in `CrossEncoderService` (`POST /rerank`);
- an OpenAI-style mode in `WhisperTranscriber` (`POST /v1/audio/transcriptions`), so speaches or a cloud API can transcribe.

Query and passage prefixes need nothing new: Laraplate already applies them
client-side from the profile (`PrefixingEmbeddingsProvider`). The current
endpoints stay supported.

### E2. Asynchronous transcription

When `whisper-api` offers jobs (its spec, E2), the media analysis job submits the
file, then polls with a backoff until the result or a timeout, instead of holding
one HTTP request for the whole transcription. Short files may keep the
synchronous call.

### E6. Status in Filament

- A panel per configured self-hosted service: reachable, `version`, `api_version` compatible with the client, `readiness`, allowed models against the active embedding profile, resident models.
- It reads `/health`, `/ready` and `/models` with the configured key.
- An active profile whose service model the host does not allow shows as an error, before the first embedding job fails on it.

## 5. Out of scope

- Hosting the services (D1).
- Any change to the services themselves: they are in the sibling specs.

## 6. Order of work

| Phase | Content | Depends on |
|---|---|---|
| 0 | Readiness status in the guides. **Delivered 2026-10-20** (`laraplate-ai` c2530f0) | Phase 0 of the services |
| 1 | R4, R7. **Delivered 2026-10-21** (`laraplate-ai` 248591b..10ba448; `api_version` narrowed by P3) | R4 and R7 in the services |
| 4 | E1 | E1 in the services, or a third-party server for testing |
| 5 | E2 | E2 in `whisper-api` |
| 6 | E6 | R2 and R7 in the services |

Phase numbers follow the services' phases, so work on the two sides that
belongs together carries the same number.
