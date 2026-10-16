---
status: open
created_on: 2026-10-15
---
# MES backend for the edge agent. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Tick each step when it is done, in the same work block. A step dropped on purpose is `- [-]` with its reason on the same line.

**Status:** Open. Tasks 1 and 2 done.

**Goal:** the MES backend speaks to the `laraplate-edge` agent as the edge agent spec fixes it: machine sources authenticate through service accounts and `api` permissions, the ingest route follows the CRUD nomenclature, each source receives a versioned configuration document and records the agent's apply reports, and data the agent dropped becomes a visible incident.

**Architecture:** a source points at one service account (`mes_machine_sources.user_id`); Core's `AuthenticateApiRequest` authenticates the bearer token, and one MES resolver turns the authenticated user into its source after checking the route's `api` permission. Configuration delivery is two tables, a service that numbers versions monotonically, two dedicated controllers on CRUD-style routes and a Filament section on the source page. Losses ride in an optional envelope field and are recorded once, when the message is first processed.

**Tech Stack:** PHP 8.5, Laravel 12, Sanctum 4, Spatie permission, Filament 5, Pest 4. No new dependency.

**Spec:** `../docs/superpowers/specs/2026-10-12-laraplate-edge-agent-design.md` (stack root) sections 5.5, 6.4, 6.5 and 12; the mother spec `../docs/superpowers/specs/2026-10-05-mes-machine-data-acquisition-design.md` section "Amendments (2026-10-12)"; `docs/superpowers/specs/2026-10-07-api-access-control-design.md` sections 9.3 and 10 (service accounts, MES consumer).

## Global Constraints

- Every PHP file `declare(strict_types=1)`; braces always; explicit types; `#[Override]`; `final` where siblings are final; PHPDoc over inline comments; English code and docs.
- New tables carry `company_id`, the `mes_` prefix and an entry in `MESTables`; schema changes to MES tables are folded into their create migrations (pre-stable, no alter migrations); portable across MySQL, MariaDB, PostgreSQL, Oracle, SQLite.
- Tests live in `Modules/MES/tests`, support classes in `Modules/MES/tests/Stubs`; never run tests with a cached config (`php artisan config:clear` first).
- Pint from the laraplate root with explicit files; PHPStan clean on touched files; no silencing.
- Other sessions edit these repositories: stage only your own hunks (`git add -p` or a temp blob), never `git add -A`; commit in the repository that owns each file and bump the `Modules/MES` / `Modules/Core` pointers in `laraplate`; never push.
- Spec values: routes `POST api/v1/insert/mes/machine-messages` (`mes.api.machine-messages.insert`), `GET api/v1/detail/mes/machine-agent-configs` (`mes.api.machine-agent-configs.detail`), `POST api/v1/insert/mes/machine-agent-config-reports` (`mes.api.machine-agent-config-reports.insert`); permissions `insert` on `mes_machine_messages`, `select` on `mes_machine_agent_configs`, `insert` on `mes_machine_agent_config_reports`; all under `core.expose_api`; `401` missing, invalid or expired token; `403` missing permission, address outside `allowed_cidrs`, inactive source; ingest outcomes `202`, `200` with `duplicate: true`, `413`, `422`, `429` unchanged; configuration `detail` answers `304` when `?version=` equals the active version; report outcomes `applied`, `rejected`, `rolled_back`; incidents `data_dropped` and `config_rejected`; envelope field `losses[]` with `priority`, `from_ts`, `to_ts`, `samples`, `reason`, optional, protocol stays `laraplate-machine/1`; the ingest rename has no alias.

## Decisions taken in this plan

- **D1. One source per service account.** `mes_machine_sources.user_id` is nullable and unique, and points only at a user with `is_service_account`; a token of any other user never resolves a source (`403`).
- **D2. Versions only grow.** The agent refuses a document older than the applied one (spec 5.4), so "rolling back by activating an earlier version" (spec 5.5) publishes that earlier document again as a new version. The backend owns numbering: a published document's `version` field is set to the stored version.
- **D3. Structural validation only.** The agent's full `laraplate-edge-config/1` schema does not exist yet; the module keeps a structural schema (top-level keys, `schema` constant, integer `version`, `inputs`/`outputs`/`plugins` as lists of objects with a string `id`) and is replaced by the agent's published schema when it exists (spec section 11 test).
- **D4. A failed apply is an incident.** Reports `rejected` and `rolled_back` both open a `config_rejected` incident (a rollback means the new document did not start); `applied` does not.
- **D5. A role for agents.** `MESDatabaseSeeder` creates the `api` role `mes-machine-agent` holding the three permissions; administrators give it to service accounts.

## Review Focus

- A token of a regular (non service account) user holding the three `api` permissions calls the ingest or configuration routes: `403`, no source resolved, nothing stored. Test in Task 2.
- A service account whose source is soft-deleted or inactive keeps sending: `403` and at most one `auth_failure` incident per five minutes. Test in Task 2.
- Activating an older configuration: the agent polling with the current version gets `200` and a document with a higher version number holding the old content, never a lower version. Test in Task 4.
- A report naming a version that does not exist for the caller's source, or another source's version: `422`, no incident. Test in Task 4.
- A message holding `losses[]` processed twice (reprocess action): one `data_dropped` incident per loss, not two. Test in Task 3.

---

### Task 1: Sources on service accounts

**Files:**
- Modify: `Modules/MES/database/migrations/2026_10_06_000001_create_mes_machine_sources_table.php` (add `user_id`, nullable, unique, FK to users, `nullOnDelete`)
- Modify: `Modules/MES/app/Models/MachineSource.php` (drop `HasApiTokens` and `tokens()`; add `serviceAccount(): BelongsTo`; `user_id` fillable)
- Delete: `Modules/MES/app/Machine/MachineSourceTokenService.php`
- Modify: `Modules/MES/app/Services/DomainActions/MesDomainActionRegistrar.php` (remove `issue_token`, `revoke_token`), `Modules/MES/app/Policies/MesModelPolicy.php` (remove `issueToken`, `revokeToken`), `Modules/MES/app/Filament/Resources/MachineSources/Pages/EditMachineSource.php` (remove the token actions), `Modules/MES/app/Filament/Resources/MachineSources/Schemas/MachineSourceForm.php` (service account select)
- Create: `Modules/MES/app/Machine/MachineSourceResolver.php`
- Test: `Modules/MES/tests/Feature/Machine/MachineSourceServiceAccountTest.php`

**Interfaces:**
- Produces: `MachineSource::serviceAccount(): BelongsTo` (to `Modules\Core\Models\User`); `MachineSourceResolver::forUser(?Authenticatable $user): ?MachineSource` (the source whose `user_id` is the user's id, only when the user is a service account; soft-deleted sources excluded; active or not is the caller's concern).

- [x] **Step 1: Failing tests.** `forUser()` returns the linked source for a service account; `null` for a regular user linked by a forged `user_id`, for `null`, for a user with no source. Linking a second source to the same service account fails (unique). Linking a non service account user through the form fails validation. The source edit page has no `issue_token` / `revoke_token` actions and the form offers only service accounts. `MachineSource` has no `tokens()` relation.
- [x] **Step 2: Migration, model, resolver.**
- [x] **Step 3: Remove the token service, its domain actions, policy methods and Filament actions; add the select.** Grep `MachineSourceTokenService` and `mes:machine-ingest` across `Modules/` and fix every remaining reference except the ingest route (Task 2).
- [x] **Step 4: Run the touched tests and `Modules/MES/tests/Feature/Machine`, Pint, PHPStan, commit.**

### Task 2: Ingest on the CRUD route through the service account

**Files:**
- Modify: `Modules/MES/routes/api.php`
- Delete: `Modules/MES/app/Http/Middleware/AuthenticateMachineSource.php`
- Create: `Modules/MES/app/Http/Middleware/ResolveMachineSource.php`
- Modify: `Modules/MES/app/Http/Controllers/MachineDataIngestController.php`, `Modules/MES/app/Providers/MESServiceProvider.php` (limiter key), `Modules/MES/database/seeders/MESDatabaseSeeder.php` (D5)
- Test: `Modules/MES/tests/Feature/Machine/MachineDataIngestTest.php` (rewrite its auth cases), `Modules/MES/tests/Feature/Machine/MachineRoutesTest.php`

**Interfaces:**
- Consumes: `MachineSourceResolver::forUser()` (Task 1); Core `AuthorizationService::ensurePermissionForClass(Request, class-string, string): string`; Core `Controller::authorizationFailureStatus(Request): int`.
- Produces: middleware `ResolveMachineSource` with parameter `model,operation` (e.g. `ResolveMachineSource::class . ':' . MachineMessage::class . ',insert'`), which checks the permission (`401`/`403` by `authorizationFailureStatus`), resolves the source (`403` when none), refuses an inactive source (`403`, `auth_failure` incident with reason `inactive_source`, once per five minutes per source as today), and sets the request attribute `ResolveMachineSource::REQUEST_ATTRIBUTE = 'machine_source'`. Task 4 reuses it.

- [x] **Step 1: Failing tests.** With the API switch on: a service account token with the `mes-machine-agent` role ingests through `POST /api/v1/insert/mes/machine-messages` (`202`, then `200` duplicate); no token `401`; an invalid token `401`; a token without the ability `403`; a token outside `allowed_cidrs` `403`; the role without the permission `403`; a regular user's token with the permission `403` and nothing stored; an inactive source `403` and one `auth_failure` incident for two refused calls; `POST /api/v1/mes/machine-data` is `404`; the route name is `mes.api.machine-messages.insert` and the route wins over Core's generic `insert/{module}/{entity}` (assert the matched action); with the switch off the route is refused as Core refuses every `/api` route. The existing `413`, `422`, transport and normaliser cases keep passing. The seeded role `mes-machine-agent` on guard `api` holds exactly the three permissions of the Global Constraints. Delivered with the ingest permission only: the two configuration permissions belong to tables Task 4 creates, so Task 4 adds them to the role and its test asserts all three.
- [x] **Step 2: Middleware, route, controller.** The route drops `SelfAuthenticatedApiRoute`; the limiter keys by the authenticated user's id.
- [x] **Step 3: Seeder role (D5).**
- [x] **Step 4: Run `Modules/MES/tests/Feature/Machine`, Pint, PHPStan, commit.**

### Task 3: Losses and the new incident types

**Files:**
- Modify: `Modules/MES/resources/protocol/laraplate-machine-1.schema.json`, `Modules/MES/app/Enums/MachineIncidentType.php` (add `DataDropped = 'data_dropped'`, `ConfigRejected = 'config_rejected'`), `Modules/MES/app/Machine/MachineMessageProcessor.php`
- Test: `Modules/MES/tests/Feature/Machine/MachineEnvelopeValidatorTest.php`, `Modules/MES/tests/Feature/Machine/MachineLossesTest.php`

**Interfaces:**
- Produces: `MachineIncidentType::DataDropped`, `MachineIncidentType::ConfigRejected` (Task 4 uses the latter). A `data_dropped` incident's `detail` is `{message_id, priority, from_ts, to_ts, samples, reason}`.

- [x] **Step 1: Failing tests.** The schema accepts an envelope with `losses: [{priority: "bulk", from_ts, to_ts, samples: 120, reason: "buffer_full"}]` and without `losses`; refuses a loss with `priority` other than `critical`/`bulk`, `samples < 1`, a non date-time `from_ts`, a missing key or an extra key. Processing an accepted message with two losses records two `data_dropped` incidents with the detail above; reprocessing it (the existing reprocess action) records none; a duplicate message (same `message_id`) records none; the same envelope through the MQTT bridge records them too.
- [x] **Step 2: Schema, enum, processor** (first processing only; follow how the processor distinguishes a first run from a reprocess, or record by `message_id` so a second run finds them).
- [x] **Step 3: Run the touched tests and `Modules/MES/tests/Feature/Machine`, Pint, PHPStan, commit.**

### Task 4: Configuration delivery

**Files:**
- Create: migrations `create_mes_machine_agent_configs_table` (`company_id`, `source_id`, `version` unsigned int, `document` json, `created_by` nullable FK users, timestamps; unique `(source_id, version)`) and `create_mes_machine_agent_config_reports_table` (`company_id`, `source_id`, `config_version` unsigned int, `outcome` enum, `errors` json nullable, `agent_version` string 64, `reported_at`); `MESTables` entries; add `active_config_id` (nullable FK, `nullOnDelete`) to the sources create migration
- Create: `Modules/MES/app/Models/MachineAgentConfig.php`, `MachineAgentConfigReport.php` with factories; `Modules/MES/app/Enums/MachineAgentConfigOutcome.php` (`Applied = 'applied'`, `Rejected = 'rejected'`, `RolledBack = 'rolled_back'`)
- Create: `Modules/MES/resources/edge/laraplate-edge-config-1.schema.json` (D3), `Modules/MES/app/Machine/MachineAgentConfigService.php`
- Create: `Modules/MES/app/Http/Controllers/MachineAgentConfigController.php`, `MachineAgentConfigReportController.php`; routes in `Modules/MES/routes/api.php`
- Test: `Modules/MES/tests/Feature/Machine/MachineAgentConfigTest.php`

**Interfaces:**
- Consumes: `ResolveMachineSource` (Task 2), `MachineIncidentType::ConfigRejected` (Task 3), `MachineIncidentRecorder::record()`.
- Produces: `MachineAgentConfigService::publish(MachineSource $source, array $document, ?User $author = null): MachineAgentConfig` (validates D3, numbers `max + 1`, writes the number into `document.version`, activates it); `MachineAgentConfigService::activate(MachineAgentConfig $config, ?User $author = null): MachineAgentConfig` (D2: republishes the document as a new version and activates that); `MachineAgentConfigService::validate(array $document): list<string>` (errors). `MachineSource::activeConfig(): BelongsTo`, `MachineSource::agentConfigs(): HasMany`, `MachineSource::agentConfigReports(): HasMany`.

- [ ] **Step 1: Failing tests.** `publish()` gives versions 1, 2, 3 and writes them into the document; a document with `schema` not `laraplate-edge-config/1`, a non-list `inputs`, an input without a string `id` is refused with errors; `activate()` on version 1 while 3 is active creates version 4 holding version 1's content and makes it active. `GET /api/v1/detail/mes/machine-agent-configs` as the agent: `200` with `{version, document}` of the active config; `?version=` equal to it `304` with no body; `?version=` lower `200`; no active config `404`; permission and source refusals as Task 2. `POST /api/v1/insert/mes/machine-agent-config-reports` with `{version, outcome, errors, agent_version}`: `201` and one stored row; `rejected` and `rolled_back` open one `config_rejected` incident each with `{version, outcome, errors}` (D4), `applied` none; an unknown outcome, a missing `agent_version`, a version not published for the caller's source `422` and no incident. Route names as in the Global Constraints.
- [ ] **Step 2: Migrations, enums, models, factories.**
- [ ] **Step 3: Schema file and service.**
- [ ] **Step 4: Controllers and routes.**
- [ ] **Step 5: Run the touched tests and `Modules/MES/tests/Feature/Machine`, Pint, PHPStan, commit.**

### Task 5: Filament section on the source page

**Files:**
- Modify: `Modules/MES/app/Filament/Resources/MachineSources/MachineSourceResource.php`; create relation managers or a page section under `Modules/MES/app/Filament/Resources/MachineSources/` following `.ai/rules/filament.md` and the module's existing relation managers
- Test: `Modules/MES/tests/Feature/Filament/MachineSourceAgentConfigTest.php`

**Interfaces:**
- Consumes: `MachineAgentConfigService::publish()`, `activate()`, `validate()` (Task 4).

- [ ] **Step 1: Failing tests.** On a source's edit page: publishing a JSON document creates the next version and shows it active; an invalid document shows the service's errors on the field and stores nothing; the versions list shows version, author and date, and "Activate" on an older one creates a new version (D2); the reports list is read-only and shows version, outcome, errors and agent version, newest first.
- [ ] **Step 2: Section, actions, lists.**
- [ ] **Step 3: Run the touched tests, Pint, PHPStan, commit.**

### Task 6: Documentation and closing

**Files:** `Modules/MES/README.md`, `Modules/MES/docs/MACHINE_CONNECTIVITY.md` (The HTTP endpoint, The envelope, Incidents and health, a new "Edge agent configuration" section), `Modules/MES/docs/rag/MODULE.md`, `Modules/MES/tests/Feature/Machine/MachineDocumentationTest.php`; this plan; `docs/superpowers/plans/INDEX.md`; at the stack root, the edge agent spec status line and the mother spec Amendments (one line each: delivered by this plan).

- [ ] **Step 1: Docs.** Service accounts and the `mes-machine-agent` role; the three routes, their permissions and status codes; issuing the token from the user resource with `allowed_cidrs`; configuration versions, activation (D2), structural validation (D3), reports and `config_rejected` (D4); `losses[]` and `data_dropped`; the old route and token actions are gone. `MachineDocumentationTest` asserts the new route names.
- [ ] **Step 2: Close.** Delivery status with divergences, `**Documented in:**`, ticks, front matter `status: closed`, plans index; the two stack-root spec lines (commit in the stack); run `tests/Unit/ClosedPlansPointToDocumentationTest.php`; ask the user to run the full suite.
