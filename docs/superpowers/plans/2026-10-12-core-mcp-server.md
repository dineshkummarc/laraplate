---
status: open
created_on: 2026-10-12
---
# Core MCP server, phase 1 (read-only). Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Tick each step when it is done, in the same work block. A step dropped on purpose is `- [-]` with its reason on the same line.

**Status:** Open. No task started.

**Goal:** an external LLM client (Claude Desktop or Code, ChatGPT, a custom agent) reads Laraplate over the Model Context Protocol, as a fourth HTTP surface, under one real user's permissions and ACL. Phase 1 is read-only, as the spec decided.

**Spec:** `docs/superpowers/specs/2026-09-12-mcp-server-design.md` (draft, revised 2026-09-16). Its open point 2 asks for this plan, after open point 1: the package and the protocol revision. This plan is that plan. The design is not redone here; Task 1 reconciles it with what shipped after it was written.

**Owner of the code:** `Modules/Core`. The spec is explicit that this is not the AI module: Laraplate is the served resource, the caller is untrusted and model-driven. Core never depends on AI, and neither does this server.

## What changed since the spec was written

Each row was read on 2026-10-12 in the spec or plan named. Task 1 confirms the code state before anything is amended.

| Spec says | Later decision | Source |
|-----------|----------------|--------|
| `HasApiTokens` is absent; a stateless group is to add. | Shipped as part of API access control: `HasApiTokens` on `User`, `AuthenticateApiRequest`, token routes under `/app/auth/user/tokens`. | `plans/2026-10-08-api-access-control.md`, index entry "delivered 2026-10-09". |
| Abilities are `mcp:read` and `mcp:write`. | Abilities are permission names of the `api` guard, `*` is refused, a request needs the role permission **and** the ability. | `specs/2026-10-07-api-access-control-design.md` section 9.1, amendment "9.1 Abilities". |
| `/app` and `/api/v1` carry no throttle. | Per token or per client address, `core.api.rate_limit_per_minute`, default 600; failed bearer attempts are limited before the token lookup. | same spec, section 5.1 and amendment "5.1 status codes". |
| Superadmin tokens must be refused, also at minting. | The shipped middleware answers 401 to a superadmin token. | same spec, section 5.1. |
| Without a token the route is not reached. | `AuthenticateApiRequest` falls back to the `anonymous` user with the `api` roles when there is no token. MCP must not accept that. | same spec, A3. |
| Phase 2 is blocked until `ActionRequestService` and `RiskClassifier` move from AI to Core. | Both were retired. Writes go through Core approvals at the model. The spec says MCP writes "inherit sections 3 to 7" of the governed-writes design. | `specs/2026-10-07-assistant-governed-writes-design.md` lines 6, 17 and 107; `plans/2026-10-07-assistant-governed-writes.md`. |
| Gate is `MCP_ENABLED`, routes not registered when off. | Decided 2026-10-12 by the owner: a seeded Core setting, default `false`, exactly as `expose_api` (`CoreDatabaseSeeder`, `config/config.php`, `CrudApiExposure`, the middleware answering 403). The env variable is dropped. | `Modules/Core/database/seeders/CoreDatabaseSeeder.php` line 105, `app/Http/Middleware/AuthenticateApiRequest.php` line 55. |

## Global Constraints

- Every PHP file `declare(strict_types=1)`; braces always; explicit types; `#[Override]`; PHPDoc over inline comments; English code and docs.
- `laravel/mcp` is locked at v1.0.1 as a dev dependency. Before a step relies on one of its classes, read the installed source and write the class and method names in the step's note. The spec says it in its own words: do not rely on recalled API shapes, the protocol moves fast.
- Tests live in `Modules/Core/tests`; support classes in `tests/Stubs` or `tests/Support`. No test declares a class. Never run tests with a cached config.
- Pint from the laraplate root with explicit files. Five-driver portability for any schema change, folded into the create migration.
- No dependency change without the owner's approval. Given 2026-10-12 for `laravel/mcp` only, in `require` of `Modules/Core`; any other package asks again.
- The gate is a seeded setting, never an env variable.
- No MCP-side allowlist or deny-list of entities: permissions and ACL are the whole authorization model, as the spec decided. The tool set is declared, never assembled inside a request handler.
- Phase 1 exposes no write, no lifecycle tool, and mints no write ability.
- Commits go to the repository that owns each file, explicit paths, never `git add -A`.

## Task 1: Reconcile the spec with the code

No feature code. The output is an amended spec and decisions the owner signs.

- [ ] **Step 1: Confirm the shipped state.** Read `User` (`HasApiTokens`), `AuthenticateApiRequest`, `ApiTokenController`, `CreateApiTokenRequest`, the rate limiter and the superadmin refusal. For each row of the table above, write "confirmed" or the difference in this step.
- [ ] **Step 2: Decision, abilities.** Recommendation: reuse the shipped model. A read-only phase 1 needs no new ability: every tool needs the entity's `select` permission, and a token carries it as an ability. `mcp:write` stays reserved in the spec and is not minted. The spec's `mcp:read` is dropped, or kept only if the owner wants a token that is MCP-only. Record the choice.
- [x] **Step 3: Decision, gate.** Decided 2026-10-12 by the owner: the switch is a seeded Core setting, default `false`, built like `expose_api`. The name is `expose_mcp` (config `core.expose_mcp`): a proposal that follows the sibling, to confirm when the seeder is written. It replaces the spec's `MCP_ENABLED`. Consequences accepted: the routes are always registered, so a disabled server answers 403 and can be fingerprinted, which the spec wanted to avoid; the switch is read per request through the settings overlay, so an administrator turns it on in Filament without a deploy. `expose_api` stays independent: MCP does not need `/api/v1` on, and `/api/v1` on does not open MCP.
- [ ] **Step 4: Decision, anonymous.** The MCP group requires a token. No token or an invalid one is 401, never the `anonymous` fallback. Confirm it as the spec's intent.
- [ ] **Step 5: Phase 2 premise.** Amend the spec's *Write approval* section with a dated note: the blocker named there is gone, and the four questions still stand. No design is done in this plan.
- [ ] **Step 6: Amend and index.** Add a dated `## Amendments (2026-10-12)` section to the spec with the decisions, and change its status line. Update the spec's line in `specs/INDEX.md`. The spec's body is not rewritten.

## Task 2: Package and protocol revision

- [x] **Step 1: Owner decision on the dependency.** Move `laravel/mcp` from dev to `require`, declared in `Modules/Core/composer.json` so the module installs it. The alternative is a hand-written JSON-RPC layer, not recommended: protocol, schema and transport are the library's job and it is first party. Record the decision before any code.
  Decided 2026-10-12 by the owner: approved, `laravel/mcp` moves to `require`, declared in `Modules/Core/composer.json`. The move itself, with the lock update, is done in Step 2 when the library is installed and read, so the version is the one that was read.
- [ ] **Step 2: Read the installed library.** `composer install`, then read `Laravel\Mcp`: the server class, how a tool declares its schema and handles a call, how a resource is declared, how routes are registered for the HTTP transport, how middleware attaches, how a session spans requests. Write the names here.
- [ ] **Step 3: Pin the protocol revision.** Record which MCP revision the installed version speaks and which one the plan targets. This closes spec open point 1.

## Task 3: The surface: route group, gate, user

**Files:** `Modules/Core/routes/mcp.php` (new), `Modules/Core/app/Providers/RouteServiceProvider.php`, `Modules/Core/app/Http/Middleware/*`, `Modules/Core/database/seeders/CoreDatabaseSeeder.php`, `Modules/Core/config/config.php`, a helper beside `app/Support/CrudApiExposure.php`.

- [ ] **Step 1: Failing tests.**
  - Off (`expose_mcp` false): 403 before authentication, on every route of the group. Turning the setting on at runtime opens it with no restart. The seeded default is `false` and the config default equals it.
  - On, no token: 401. Invalid, expired or revoked token: 401. Superadmin token: refused before any tool runs.
  - A cookie or a session never authenticates the group.
  - A request from an address outside the token's `allowed_cidrs` is refused, as on `/api/v1`.
  - The resolved user is never kept between requests: two requests with two tokens in one process never see each other's user.
  - Revoking a token stops the next JSON-RPC message of an open stream, not only the next connection.
- [ ] **Step 2: The setting and the group.** The setting first, as `expose_api` is: a seeded definition in `CoreDatabaseSeeder` (boolean, group `core`, default `false`), the matching default in `config/config.php`, a test that the seeded value and the config default agree, and a helper like `CrudApiExposure` so tests can turn it on through the settings overlay, since `config(['core.expose_mcp' => true])` alone is overwritten. Then the group: its own middleware group: bearer, `auth:sanctum` through the shipped middleware, no `EncryptCookies`, `StartSession`, `VerifyCsrfToken` or `EnsureFrontendRequestsAreStateful`. The spec calls mounting it on the `auth` group the trap. The user is set per request, no static or singleton holds it.
- [ ] **Step 3: Rate limiting.** Reuse the shipped per-token limiter. A stricter named bucket for `search`, because a deep search embeds the query on every call and the spec calls the limiter the control that makes it affordable. Numbers are settings.
- [ ] **Step 4: Run the touched tests, format, commit.**

## Task 4: The read tools

**Files:** `Modules/Core/app/Mcp/*` (new).

The tools are a thin projection of `CrudService` and the Graph controller. They re-implement no permission check, no ACL and no relation visibility: those live in the gateway and shipped with API access control.

- [ ] **Step 1: Failing tests, parity first.** For one user and one token, each tool returns what the same call returns on `/api/v1` for the same user: same rows, same hidden columns, same related-record visibility, same 403s. A row the user's ACL hides is absent. A token without the entity's `select` ability gets nothing.
- [ ] **Step 2: `entities`.** Built from `modules()` and `models()` with a filter on the current user's read permission, enriched with `ModelCapabilityScanner` (`hasVersions`, `hasLocks`, `hasApprovals`, `hasOptimisticLocking`, `hasTranslations`). Concrete models only: never an introspected table, even when `crud.dynamic_entities` is on. Schema from the model class (`$fillable`, `$casts`, validation), not from the database.
- [ ] **Step 3: `list`, `detail`, `tree`, `history`, `pending_approvals`, `latest_disapproval`.** One adapter class maps a tool call to the `CrudService` method named in the spec's table. A tool that does not apply to an entity (no versions, no approvals) is refused with a message the model can use, and the description says which entities it applies to.
- [ ] **Step 4: `graph_expand`, `graph_stats`.** Through the Graph controller path.
- [ ] **Step 5: `search`.** Through `CrudService::search`. It passes `mode=deep` if the search modes plan has shipped; read `plans/2026-09-16-search-modes-and-strategy-resolution.md` first and say which it is. `deep` is gated by a permission: the service account needs it like any user, and the test proves a token cannot grant it.
- [ ] **Step 6: Excluded, as a test.** The registered tool list contains none of: impersonation, any Artisan passthrough, `domainAction` and raw activate, `clearModelCache`, `dev` routes, media upload, imports, facets, freshness, notifications. The test fails when a name is added.
- [ ] **Step 7: Descriptions say what the model needs.** A single sentence per tool, and `detail` states that the `lock_version` it returns is what a future write would need. No instruction text in any description that a record's content could imitate.
- [ ] **Step 8: Run the touched tests, format, commit.**

## Task 5: Resources and audit

- [ ] **Step 1: Resources.** `about` (the site info) and `translations`, and the per-entity schema addressable as the data `entities` already returns. Static reads cost nothing per turn.
- [ ] **Step 2: Failing audit tests.** Every `tools/call` writes one line to a dedicated log channel: user id, token id and name, tool, arguments without secrets, outcome (ok, denied, validation error, exception). No result content. A denied call is logged. A failing log write does not fail the call.
- [ ] **Step 3: The channel.** A dedicated channel in Core's logging config, with the module's rotation style. A queryable table stays a later decision, as the spec says.
- [ ] **Step 4: Run the touched tests, format, commit.**

## Task 6: Documentation and closing

- [ ] **Step 1: Core docs.** `Modules/Core/README.md` and `Modules/Core/docs/rag/`: the endpoint, the `expose_mcp` setting and its relation to `expose_api`, the group and why it is not the `auth` group, the tools, what is excluded and why, the audit channel. Any new env or setting listed with its default.
- [ ] **Step 2: User doc.** How to create a token (`/app/auth/user/tokens`, abilities, expiry), a service account for an integration, and the few lines a client such as Claude Desktop needs. Say that a superadmin token does not work and why.
- [ ] **Step 3: Stack docs.** `laraplate-stack/.cursor/rules/01-ecosystem.mdc` names three HTTP surfaces. This is a fourth: update it, with the licensing boundary unchanged, and note it in `docs/architecture.md`. The stack is a separate repository: a note for the owner if it cannot be done from here.
- [ ] **Step 4: Close.** Add `## Delivery status (date)` with every divergence, the `**Documented in:**` line naming the Core docs, `status: completed`, and the index line. Phase 2 is listed as not built, with the spec's four questions as its entry point.

## Out of scope

Write and lifecycle tools, token pre-approval, a parked-call protocol for unattended approvals, OAuth 2.1 self-onboarding, MCP prompts, Laraplate as an MCP client of other servers (Neuron has a client side; separate decision), a queryable audit table, `/api/v1` "safe tools" lists.
