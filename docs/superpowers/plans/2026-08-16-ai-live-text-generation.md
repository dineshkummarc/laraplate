---
status: completed
---
# AI Live Text Generation Plan

> REQUIRED SUB-SKILL: superpowers:executing-plans. Checkbox (`- [ ]`) steps.

**Goal:** make the optional live LLM text-generation path production-ready behind its feature flag, without changing the event contract or any consumer. Spec: `docs/superpowers/specs/2026-08-16-ai-live-text-generation-design.md`.

## Global Constraints

- `declare(strict_types=1);`, `final`, explicit types, `#[Override]`; reuse the AI module's `ProviderFactory`/`ChatAgent` — no new provider/SDK.
- Do not change Core's `AiTextGenerationRequested` event or any SAO consumer.
- Any failure leaves the event **unfulfilled** so the requester falls back. Per task: minimal tests (mocked provider); Pint before commit. Commit per task; bump parent at the end.
- Offline-first: all behaviour covered with mocks; the only live test is gated and skipped by default.

## Task 1: config + provider preflight

- Extend `ai.features.text_generation` (`model`, `timeout_seconds`, `max_output_chars`, `rate_limit`, `cache_ttl_seconds`, overridable `system_prompt`). Add a preflight in the listener: enabled **and** provider buildable, else log-once and no-op. Document the new env vars in the AI README.
- [x] Config extended (max chars, cache TTL, rate limit) + docs; feature-flag gate + failure→unfulfilled preflight. Pint + commit.

## Task 2: cost controls

- Timeout wrapper, `max_output_chars` truncation (word boundary), per-`purpose` `RateLimiter`, optional `Cache` keyed by `sha256(purpose|prompt)` with TTL.
- [x] Rate limit, cache hit/miss, output cap covered (timeout treated as a caught Throwable → unfulfilled; hard wall-clock timeout is the HTTP client's concern). Pint + commit.

## Task 3: output hardening + observability

- Sanitize output (trim, strip control chars, collapse whitespace, length cap); config-overridable system prompt with the safe default. One structured log line per attempt with `outcome` + latency (+ tokens when available), no secret/body logging.
- [x] Output sanitization + structured outcome logging (Log spy). Pint + commit.

## Task 4: gated live smoke test + docs

- A single live round-trip test, skipped unless `AI_LIVE_TESTS=1` + provider creds; asserts a non-empty, name-preserving rewrite. Update AI RAG docs (feature, flags, how to run the live test) and reconcile spec/plan indexes.
- [x] Gated live smoke test skips cleanly offline; README env vars documented; indexes reconciled. Pint + commit + bump parent.

## Exit criteria

- With `AI_TEXT_GENERATION_ENABLED=1` and a configured provider, an ownership suggestion is phrased by the live model; with the feature off, a misconfig, a timeout, a rate-limit, or an error, the caller silently gets the deterministic text. All non-live behaviour is green in CI; the live smoke passes locally against a real provider.

## Known gaps / out of scope

- Streaming, multi-turn, tool use; a new provider/SDK; auto-applying AI output to domain state (D14 stands).
- Live provider credentials/quotas are an environment concern, not code.

## Delivery status (2026-08-25): delivered, reconciled against the code on 2026-10-23

Shipped in AI 2.17.0. Divergences:

- The system prompt is a constant in the listener, not a config override.
- No `timeout_seconds` setting: a timeout is caught as `Throwable` and leaves the event unfulfilled.
- No separate "provider buildable" preflight: a failure to build the agent logs outcome `error`.
- The log line carries no token count.
- The model binding started as `AI_TEXT_GENERATION_MODEL` and became the setting `features.text_generation.model` on 2026-09-29; the other `AI_TEXT_GENERATION_*` variables became settings on 2026-10-07, so the exit criterion's `AI_TEXT_GENERATION_ENABLED=1` no longer applies.
- Task 4's update of `Modules/AI/docs/rag/MODULE.md` was not done: it has nothing on text generation; to write in the AI repository.

**Documented in:** `Modules/AI/README.md` (*Configuration*, *Architecture*), `Modules/AI/docs/rag/AI_MODEL_SELECTION_USER.md`, `Modules/AI/docs/rag/AI_MODEL_SELECTION_DEVELOPER.md`, `Modules/SAO/docs/rag/GLOSSARY.md` (*Automation*).
