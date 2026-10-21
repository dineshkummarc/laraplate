# Palette documentation search and shared query embedding

**Status:** Built 2026-10-21 (one measurement pending)

**Date:** 2026-10-21

**Modules:** `Modules/AI` only. Core is not touched.

**Consumer:** the command palette of `laraplate-ui` (`docs/superpowers/specs/2026-06-17-ai-assist-design.md` §5, plan task 7 of `docs/superpowers/plans/2026-06-17-ai-assist.md`, stack root).

## Problem

The command palette is going to be search only. Besides commands, records and static help, it shows two more groups: passages of the end-user documentation (user RAG) and files (`HasMedia`).

Two things are missing in the backend.

1. **No HTTP route returns documentation passages.** `DocumentationService::retrieveForInApp()` is permission-, tenant- and scope-aware and calls no language model, but only `InAppAssistanceService` and an Artisan command use it. The one HTTP path to it is the assistant message route, which generates an answer. The palette must not call the agent.
2. **The same search text is embedded more than once.** The palette sends one request per group, in parallel, each carrying the same text. A semantic or hybrid strategy for records and media, and the documentation retrieval, each call `EmbeddingService::embedText()`, which has no cache. One keystroke pause can cost up to three identical calls to the embedding provider.

Records and files need no new route: `/app/crud/search/{module}/{entity?}` already serves them (`Media` is `Searchable` and authorizes rehydration).

## Decisions

| # | Decision |
|---|---|
| D1 | The group exists only when the documentation feature is on: the setting `features.faq.enabled`, read as `AssistantCapabilities::help()` and reported to clients as `features.help` by `GET /app/ai/capabilities`. It is the same switch under which the assistant reads documentation. The route calls no chat model, so the assistant's own switch (`features.chat.enabled`) and the chat provider being configured do not gate it. Switched off, the route answers as the assistant routes do for a disabled feature. With the AI module disabled the route does not exist. |
| D2 | One new route, registered by the AI module under the `crud` prefix: `GET /app/crud/search/{module}/documentation?q=`. `{module}` is the module whose documentation is asked for (`cms`, `erp`, `mes`, `sao`), the same `{module}/{entity}` shape as the other `crud/search` routes. `documentation` is a reserved entity name. |
| D3 | No embedding route, and no route that fans out to the others. The client keeps calling the existing search routes, in parallel. |
| D4 | The shared cost is removed inside `EmbeddingService::embedText()`: a cache of query embeddings and a lock so that concurrent requests for the same text compute it once. Core is not changed: it reaches embeddings only through `ITextEmbedder`, which `SearchEmbedder` implements on top of `embedText()`. |
| D5 | Documentation scope follows the assistant. The module in the path is resolved through `AssistantScopeResolver`: the result is that module's guides plus the guides marked cross-cutting (`metadata.cross_cutting_user`). Core is not included wholesale. Every UI app belongs to a module, so the palette always knows which one to ask for; there is no application-wide variant on this route. |
| D6 | The entity is named `documentation`, not `rag` (the technique) and not `help` (the palette already has a static "Help" group). |

## The route

`GET /app/crud/search/{module}/documentation`

| Parameter | Rule |
|---|---|
| `{module}` | Path segment. It must match `AssistantScope::MODULE_KEY_PATTERN` and name a module that is installed and enabled on the instance; anything else answers 404, as an unknown module does on the other `crud` routes. It narrows the result to that module (plus cross-cutting guides) and never widens access: audience, permission and tenant filters apply as for any call. |
| `q` | Required, trimmed. Minimum length and maximum length are fixed in the plan (proposed: 3 and 200 characters). Shorter input answers 422, no embedding is computed. |

Response, inside the usual `ResponseBuilder` envelope. The item is the projection the assistant already builds for a documentation citation (`InAppAssistanceService::promptContext()`), so the client's `AssistantCitation` type fits it:

```json
{
  "data": [
    { "label": "Approve a modification", "reference": "Approve a modification", "excerpt": "…", "heading_breadcrumb": "Approvals > Approve", "module": "cms" }
  ]
}
```

`label` is the source name of the document and `reference` repeats it, as the client already does when a citation has no separate reference; `excerpt` is cut at 300 characters; `heading_breadcrumb` and `module` appear only when the document carries them. At most `topK` items (the existing bound of 1 to 10 applies). Text is plain: the client renders it as text, never as markup. No score is exposed.

The route calls no language model and builds no prompt. It does not take `context.page`.

`documentation` is reserved: the route is registered for every `{module}` and wins over `search/{module}/{entity?}`, so a model named `documentation` in any module would not be reachable through `crud/search`. The plan checks that no module has one.

### Access

- Authenticated session, same as `GET /app/ai/capabilities`; the guest account is refused. The route does not run `ResolveAssistantApplicationContext`: it reads no `context.page`.
- The access context is the one the assistant builds for the in-app profile: tenant, locale and effective permissions come from the authenticated user, never from the request.
- Retrieval goes through `DocumentationService::retrieveForInApp()`, so the filters of `DocumentationRetrievalContext` apply unchanged: audience `user` or `shared`, locale, safe-projection classification, validated permission metadata, tenant, and the module filter.
- Developer documentation is not reachable: it lives in a separate index that the in-app profile cannot use.
- The route is outside `/api/v1`. It must not be exposed by `EXPOSE_CRUD_API`.

### Abuse limits

Every call costs an embedding. A per-user rate limit applies to the route, and it is checked before the embedding is computed. The values are fixed in the plan.

## Shared query embedding

In `EmbeddingService::embedText()`:

1. Key = identity of the active embedding model (`EmbeddingServiceIdentity`) + hash of the normalized text. A model switch changes the key, so stale vectors are never read.
2. Look up the shared cache (Redis in this instance). A hit returns the vector.
3. On a miss, take an atomic lock on the key and wait for it (`Lock::block()`, which retries every 250 ms). Once the lock is held, read the cache again before computing: the request that held it before has probably stored the vector.
4. If the wait times out (`LockTimeoutException`), compute the embedding without the lock and return it. The worst case is today's behaviour.
5. Store the vector with a TTL of a few minutes, then release the lock. The lock has its own expiry, slightly longer than the longest expected embedding call, so a request that dies does not leave it held.

Rules:

- Only the vector is cached, under a hashed key. The search text is not stored.
- The key includes the signed-in user (a fixed shared partition when there is none), so a user cannot tell by timing whether someone else searched the same text; the parallel requests of one user's palette still share an entry.
- The cache and the lock are an optimization: if the cache store is unavailable, `embedText()` computes the vector as it does today.
- Every caller benefits (records, media, documentation retrieval, the assistant), so no route needs to know about it.
- Documents are embedded through other methods (`embedDocument`, `embedDocumentsBatch`) and are not cached: this is for query text only.

## Client contract (for the stack plan)

- The palette calls, in parallel and with a debounce, `crud/search` for records, `crud/search/core/media` for files, and `crud/search/{module}/documentation` for passages, with the module of the current app. The last one only when capabilities report the AI module enabled and configured.
- It puts its own module in the path (`search/cms/documentation` from the CMS app).
- A result is a link: it opens a page, a record, a help topic or a file. Nothing runs.
- A group with no result is not shown.

## Out of scope

- An embedding route, or a route that accepts a vector in place of text.
- An application-wide documentation search (no module). The assistant has that case; the palette does not.
- Any change to Core search routes or to `ITextEmbedder`.
- Caching document embeddings.
- Searching developer documentation.

## Checks to settle in the plan

These were not verified while writing this spec and must be read in code first.

1. **Route precedence.** `search/{module}/{entity?}` belongs to Core. The AI route `search/{module}/documentation` has a wildcard module, so it must be registered ahead of the generic one, and that order must hold whatever the module load order is. The plan proves it with a test that resolves `search/cms/documentation` to the AI controller and `search/cms/contents` to `CrudController`, and checks that no module has an entity named `documentation`.
1b. **Verifying the module.** *Settled:* the route does not reuse `ResolveAssistantApplicationContext`, which resolves a `{module}/{entity}` page for the assistant. It checks the path module the way Core's `ApplicationContentRetrievalService` does, with the nwidart facade `Module::isEnabled($module)` after the key pattern, so an unknown or disabled module is a 404.
2. **Access context without a conversation.** *Settled by reading the code:* `AssistantAccessContextFactory::forInApp()` requires a `Conversation`, and `AssistantAccessContext::assertInAppContext()` rejects a null conversation id. `DocumentationRetrievalContext` reads only tenant, locale and permissions, so a search needs a context built without a conversation: the plan adds `AssistantAccessContextFactory::forDocumentationSearch()` and lets the context carry a null conversation id, while `forInApp()` keeps enforcing ownership.
3. **Rate limiting.** *Settled:* Core already defines a named limiter for an expensive route (`RateLimiter::for('aggregate', …)` in `CoreServiceProvider`, applied as `->middleware('throttle:aggregate')`). The AI route follows it with its own limiter, per signed-in user. The plan fixes the allowance.
4. **Which entities embed the query.** Whether `crud/search` for records and media really embeds the text depends on the search strategy resolved per entity (`AiSearchStrategyResolver`). The plan measures how many embedding calls one palette query makes before and after.

## Testing

- Route: guest refused; AI off refused; unknown or disabled module answers 404; `q` too short answers 422 and computes nothing; a call for one module returns only that module plus cross-cutting guides, never another module's guides; a document of another tenant, another locale or without the user's permissions is never returned; developer-index content is never returned.
- Embedding: second call with the same text hits the cache; concurrent calls compute once; a lock timeout still returns a vector; a model switch does not read the old vector; cache store down still returns a vector; the cached payload holds no text.
- Precedence: `search/cms/documentation` resolves to the AI controller, `search/cms/contents` still resolves to `CrudController`.
