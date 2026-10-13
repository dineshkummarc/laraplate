---
status: open
created_on: 2026-10-13
---
# Right to erasure and exclusions for contents and media. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Tick each step when it is done, in the same work block. A step dropped on purpose is `- [-]` with its reason on the same line.

**Status:** Open. No task started.

**Goal:** what is removed from an instance stays removed. A force delete of a content or a media removes its derived copies, keeps its snapshot as evidence for a configurable time and writes an exclusion that blocks imports and restores. A privacy request, decided by a person, removes every copy including history. Reports can request deletions where the installation turned approvals on.

**Architecture:** Core owns the contracts, the exclusions table, the history primitives, the import and restore guards and the erasure requests. CMS and AI register participants that remove their own copies. Capabilities are declared in code and switched per installation through per-table settings, as soft deletes, locking and versioning already are.

**Tech Stack:** PHP 8.5, Laravel 12, Filament 5, Pest 4, `spatie/laravel-medialibrary` 11.23.9, Scout. No new dependency.

**Spec:** `docs/superpowers/specs/2026-10-12-right-to-erasure-design.md`, all sections; decisions 1 to 17, D1 to D18, open points 2 to 9 confirmed by the owner on 2026-10-13. Related: `2026-10-13-content-reports-design.md` (its own plan), `2026-09-27-core-approvals-subsystem-design.md` (amendment of 2026-10-13).

**Prerequisite done:** Core `047fa3c` and `1a13e6e`: a delete with soft deletes switched off behaves as a force delete after authorization. The full Core suite and the CMS and AI tests touching force deletes still have to run on it; Task 1 Step 1.

## Global Constraints

- Every PHP file `declare(strict_types=1)`; braces always; explicit types; `#[Override]`; `final` where siblings are final; PHPDoc over inline comments; English code and docs.
- Tests live in the module that owns the code; support classes in `tests/Stubs` or `tests/Support`, never declared in a test file. Never run tests with a cached config.
- Pint from the laraplate root with explicit files: `vendor/bin/pint --format agent <files>`. Never with an empty list.
- Schema changes fold into create migrations (pre-stable). Five-driver portability. Table names carry the module prefix.
- Switches and tuning values are seeded settings with a default in code, read through the settings overlay; no `env()` outside config files. The only environment value is the HMAC keyring, a secret.
- Core knows no CMS or AI class: modules register participants.
- Derive queries and transactions from the owning model; no implicit `DB::`.
- No erased value in logs, reports, notes or exclusions.
- Commits go to the repository that owns each file, explicit paths, never `git add -A`.

## Task 1: Baseline and approval operations per installation (D18)

**Files:** `Modules/Core/app/Models/Concerns/HasApprovals.php`, `Modules/Core/database/seeders/CoreDatabaseSeeder.php`, `Modules/Core/app/Services/PerModelSettingResolver.php`, `Modules/Core/app/Models/Media.php`, tests in `Modules/Core/tests`.

- [ ] **Step 1: Baseline.** Run the full Core suite and the CMS and AI tests that force delete or observe `forceDeleted` (`ContentExtensionObserver`, `ExtendsContentTrait`, `MediaAnalysisRefcountObserver`) on the prerequisite commits. Record the result here. A failure caused by them is fixed first.
- [ ] **Step 2: Failing tests.** A per-table setting lists the active approval operations; its seeded default equals `approvalOperations()`; an operation the model does not declare is ignored even if listed; `shouldCapture()` reads the active list. `Comment` keeps `Create` and `Update`. `Media` declares `Delete` and `ForceDelete`, has none active by default, and an upload, an edit and a conversion save are never captured.
- [ ] **Step 3: The setting.** Prefix constant next to `SOFT_DELETES_NAME_PREFIX`, seeded per table like soft deletes and locking, read through `PerModelSettingResolver`. Read `SettingTypeEnum` first for the list type.
- [ ] **Step 4: `HasApprovals` on `Media`.** `approvalOperations()` returns `Delete` and `ForceDelete`; `pendingDeletionStrategy()` returns `Hide`.
- [ ] **Step 5: Docs, run the touched tests, Pint, commit.** Core README and `docs/rag`: the setting and its default.

## Task 2: Participants (D6, open points 2, 3, 4)

**Files:** new `Modules/Core/app/Erasure/Contracts/IErasureParticipant.php`, `Modules/Core/app/Erasure/ErasureParticipantRegistry.php`, value objects `ErasureSubject`, `ErasureTarget`, `ErasureRemedy`, `ErasureReport`; participants in Core, CMS (`Modules/CMS/app/Erasure/`) and AI (`Modules/AI/app/Erasure/`).

- [ ] **Step 1: Contract and registry.** `findCandidates()`, `copiesOf()`, `erase()`, registered by service providers as `ModerationAdapterRegistry` is. A disabled module is reported as not executed.
- [ ] **Step 2: Core participant, failing tests first.** Removes every `ModelEmbedding` of the record, all locales; the search document; media files, conversions, drafts and duplicates by `content_hash` when asked; outbox payloads; `RecordOrigin` rows. Idempotent.
- [ ] **Step 3: CMS participant.** Translations deleted through Eloquent before the content, inside one version set; comments, ratings, pivots, references; `findCandidates()` searches the translations' text for a media's uuid, file name and URL and lists the contents that cite it.
- [ ] **Step 4: AI participant.** Media analyses with the refcount respected: an analysis survives while another media holds the hash.
- [ ] **Step 5: Run the touched tests, Pint, commit per repository.**

## Task 3: History primitives (D7, open points 5, 6)

**Files:** `Modules/Core/app/Services/VersioningService.php`, `Modules/Core/app/Versioning/VersionSetManager.php`, `Modules/Core/app/Models/Concerns/HasVersionedRelations.php`.

- [ ] **Step 1: Failing tests.** Purging a record's history removes every version holding its values and marks its membership in version sets as purged; restoring the other members of a set skips the purged one and reports it. Recreating a record from a `Deleted` snapshot set brings back the record and its translations.
- [ ] **Step 2: Purge primitive.** On `VersioningService`, never a raw query from a module.
- [ ] **Step 3: Recreate primitive.** On `VersioningService`, refusing while an active exclusion exists (Task 6 adds the check).
- [ ] **Step 4: Run the touched tests, Pint, commit.**

## Task 4: Exclusions table and the excludable force delete (D2, D8, open point 9)

**Files:** new create migration for `core_import_exclusions`, `Modules/Core/app/Models/ImportExclusion.php`, `Modules/Core/app/Enums/CoreTables.php`, `Modules/Core/app/Contracts/IExcludable.php`, `Modules/Core/app/Erasure/ExclusionHasher.php`, `Modules/Core/config/config.php`, `Modules/Core/app/Services/Crud/CrudService.php`, `Modules/Core/app/Http/Controllers/MediaController.php`, `Modules/CMS/app/Models/Content.php`, `Modules/Core/app/Models/Media.php`.

- [ ] **Step 1: Failing tests.** A force delete of an `IExcludable` record, once executed, writes one exclusion with the snapshot set id, `case` `Moderation`, `reason_category` from the request or `Manual`, `channel` and `token_id`; a captured force delete writes nothing until approved; a soft delete writes nothing; the source identity is stored as HMAC under the current key with its key id, and matching finds rows written under an older key of the keyring; generic CRUD delete on exclusions is refused.
- [ ] **Step 2: Table and model.** Columns of spec D8 plus `hmac_key_id`. `DeniesGenericCrudWrites` for delete. Insert by hand and update for revocation and legal hold go through the CRUD with their permissions.
- [ ] **Step 3: Keyring.** `CORE_EXCLUSIONS_HMAC_KEYS` in the environment, read in `config.php` as a map of key id to secret plus the current id. Never `APP_KEY`. The README states that a key is never removed while rows use it.
- [ ] **Step 4: The hook.** On `forceDeleted` of an `IExcludable` model: participants except history, then the exclusion. `reason_category` and `note` accepted by the CRUD delete and the media delete requests.
- [ ] **Step 5: Run the touched tests, Pint, commit per repository.**

## Task 5: Matching at import (D9)

**Files:** `Modules/Core/app/Import/Support/RecordOriginRegistry.php`, `Modules/Core/app/Import/Enums/ExternalRecordState.php`, `Modules/Core/app/Import/Enums/ImportRowOutcome.php`, `Modules/Core/app/Import/Support/ImportRowContext.php`, the CMS importers.

- [ ] **Step 1: Failing tests.** An excluded record is neither created nor updated, by keyed source identity and, for media, by `content_hash`; the outcome is `Excluded` and counted in the session; a revoked exclusion no longer blocks.
- [ ] **Step 2: States and outcome.** `ExternalRecordState::Excluded`, `ImportRowOutcome::Excluded`, checked before an importer writes.
- [ ] **Step 3: Every importer.** List the importers of Core and CMS and confirm each passes through the shared path; record the list here. Manual upload of an excluded media file returns a warning, not a refusal.
- [ ] **Step 4: Run the touched tests, Pint, commit per repository.**

## Task 6: Restore block and revocation (D10, D11)

**Files:** a new listener registered by `CoreServiceProvider`, `Modules/Core/app/Versioning/VersionSetManager.php`, `Modules/Core/app/Models/Version.php`, `Modules/Core/app/Exceptions/ExcludedRecordException.php`, exception mapping in the CRUD and Filament.

- [ ] **Step 1: Failing tests, one per path of spec D10.** Bin restore, media restore, approved restore, return to a version, restore of a revision, seed reconciliation: each refuses an excluded record, soft deleted or live. Creating an exclusion cancels pending `Restore` modifications. After revocation, restores work and a snapshot recreates the record.
- [ ] **Step 2: Guards.** Listener on `restoring`; check in `VersionSetManager` for `Revert` sets and in `revertWithoutSaving()`; `ExcludedRecordException`, never a `false`.
- [ ] **Step 3: Revocation.** Update with its permission, mandatory reason, trace; the row stays.
- [ ] **Step 4: Run the touched tests, Pint, commit.**

## Task 7: Snapshot retention and legal hold (D12)

**Files:** a new purge command in `Modules/Core/app/Console/`, `CoreDatabaseSeeder` (setting and cron job), `ImportExclusion`.

- [ ] **Step 1: Failing tests.** Exclusions whose snapshot is older than `exclusions.snapshot_retention_days` and without legal hold lose their snapshot, deleted by id through Task 3's primitive; the exclusion stays; other `Deleted` versions are untouched; a legal hold stops expiry; reading a held snapshot needs its permission.
- [ ] **Step 2: Setting, default 180, and the command,** registered in `core_cron_jobs` like the existing purges.
- [ ] **Step 3: Run the touched tests, Pint, commit.**

## Task 8: Backup reapply (D13)

- [ ] **Step 1: Failing test.** After rows matching active exclusions are inserted back, as a restored backup would, `core:exclusions:reapply` removes them again with the remedy each exclusion records.
- [ ] **Step 2: The command,** and the step in the deployment documentation for every restore.
- [ ] **Step 3: Run the touched tests, Pint, commit.**

## Task 9: Filament (D16)

**Files:** `Modules/Core/app/Filament/Utils/HasTable.php`, new `Modules/Core/app/Filament/Resources/ImportExclusions/`, the CMS content resource. Follow `.ai/rules/filament.md` and the `filament-development` skill.

- [ ] **Step 1: Force delete confirmation on excludable models.** Reason category, note, the notice that the record will not come back, and for a media the duplicates by hash with the choice to include them; bulk variant.
- [ ] **Step 2: Exclusions resource.** Read-only list with the filters of spec D8, revoke, manual insert, legal hold.
- [ ] **Step 3: Tests, Pint, commit.** The Vue UI part is specified in the stack repository from `ui-preview-v2.html`; the backend exposes a capability flag per record type and the duplicates of a media. Record here when that spec exists.

## Task 10: Erasure requests (D3, D4, D5, D15, open points 7, 8)

**Files:** create migrations for `core_erasure_requests` and `core_erasure_request_items`, models, domain actions registered in `DomainActionRegistry`, a reminder command, a notification, a Filament resource.

- [ ] **Step 1: Failing tests.** Workflow transitions with their permissions; due date one month with reminders at 7 and 2 days, all settings; items targeting contents, media with duplicates, and users; recipients listed; `Purge` removes every copy including the snapshot and writes an exclusion without snapshot; execution queued per item, idempotent, not captured by approvals; minimisation after 12 months keeps dates, outcome, categories and deciders only.
- [ ] **Step 2: Models and workflow.**
- [ ] **Step 3: Execution job** with the participants and Task 3's purge, under an approval bypass scoped to the job; read `setForcedApprovalUpdate()` first.
- [ ] **Step 4: User items.** Existing user deletion made definitive with its AI purge; authored contents keep a "removed user" as author.
- [ ] **Step 5: Notifications, reminders, minimisation command in `core_cron_jobs`, Filament resource.**
- [ ] **Step 6: Run the touched tests, Pint, commit per repository.**

## Task 11: Delist (D3)

- [ ] **Step 1: Failing tests.** A delisted content is absent from search, listings, sitemap and API, present at its address with a no-index signal; a revert does not undo it; an exclusion is written.
- [ ] **Step 2: CMS column in the create migration, in `$dontVersionable`; filters; the remedy in the request execution.**
- [ ] **Step 3: Run the touched tests, Pint, commit.**

## Task 12: Redact (D3)

- [ ] **Step 1: Failing tests.** Named fields and passages replaced in the record and its translations; no version holds the old value; an exclusion blocks the import from writing it back.
- [ ] **Step 2: The remedy,** using Task 3's purge on the versions holding the old value.
- [ ] **Step 3: Run the touched tests, Pint, commit.**

## Task 13: Documentation and closing

- [ ] **Step 1: Module docs.** Core, CMS and AI READMEs and `docs/rag`: excludable force delete, exclusions and their history, import and restore blocks, retention and legal hold, backup reapply, requests and remedies, the HMAC keyring, every new setting with its default. User doc for the staff in Filament. The legal frame of the spec is linked, not copied.
- [ ] **Step 2: Index and spec.** Update this plan's line in `plans/INDEX.md` and the spec's status line.
- [ ] **Step 3: Close.** `## Delivery status (date)` with every divergence, the `**Documented in:**` line, `status: completed`.

## Out of scope

MCP tools for reports and deletions: they come with the MCP server's phase 2, and inherit the excludable force delete without work of their own. The reports feature has its own plan. The Vue UI is specified and built in the stack repository.
