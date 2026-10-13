---
status: open
created_on: 2026-10-13
---
# Content reports. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Tick each step when it is done, in the same work block. A step dropped on purpose is `- [-]` with its reason on the same line.

**Status:** Open. No task started.

**Goal:** anyone who sees a published content, media, comment or contributor reports it to the staff with a reason from a list. The staff works on cases grouped per item and resolves them. Reasons the staff elects can request the item's deletion through Core approvals, where the installation turned approvals on. A privacy report becomes an erasure request.

**Architecture:** Core owns the reasons, the reports, the cases, the routes, the reaction and the staff surface. A model becomes reportable by implementing `IReportable` and being registered; CMS registers `Content`, `Comment` and `Contributor`, Core registers `Media`. Waiting states reuse `HasApprovals` and its `Hide` and `Block` strategies unchanged.

**Tech Stack:** PHP 8.5, Laravel 12, Filament 5, Sanctum 4, Pest 4. No new dependency.

**Spec:** `docs/superpowers/specs/2026-10-13-content-reports-design.md`, decisions 1 to 13, D1 to D11.

**Depends on:** `2026-10-13-right-to-erasure.md` Task 1 (approval operations per installation, `HasApprovals` on `Media`) before Task 5 here; its Task 4 (excludable force delete) before the removal outcome of Task 6; its Task 10 (erasure requests) before the conversion of Task 6.

## Global Constraints

- Every PHP file `declare(strict_types=1)`; braces always; explicit types; `#[Override]`; `final` where siblings are final; PHPDoc over inline comments; English code and docs.
- Tests live in the module that owns the code; support classes in `tests/Stubs` or `tests/Support`, never declared in a test file. Never run tests with a cached config.
- Pint from the laraplate root with explicit files. Five-driver portability; schema in create migrations; table prefix `core_`.
- Switches and values are seeded settings with a default in code. Purges are registered in `core_cron_jobs`.
- Core knows no CMS class: CMS registers its reportables.
- The reporter's identity never reaches the owner of the reported item, the record's history or a log.
- Commits go to the repository that owns each file, explicit paths, never `git add -A`.

## Task 1: Reasons (D2)

**Files:** create migration for `core_report_reasons` and its translations, `Modules/Core/app/Models/ReportReason.php` on the `Taxonomy` base, `Modules/Core/app/Enums/ReportCategory.php`, `Modules/Core/app/Enums/CoreTables.php`, `CoreDatabaseSeeder`.

- [ ] **Step 1: Failing tests.** A reason has a translatable label, a category from `ReportCategory` (`Rectification`, `Privacy`, `Inappropriate`, `Legal`, `Other`), active and order; it can require the free text; it can be limited to some reportable types. The seeder writes the six reasons of the spec in Italian and English and leaves the administrator's edits alone on reseeding.
- [ ] **Step 2: Enum, model, migration, seeding** through `SeedDefinition` like the other Core seeds.
- [ ] **Step 3: Run the touched tests, Pint, commit.**

## Task 2: Reportables (D1)

**Files:** `Modules/Core/app/Reports/Contracts/IReportable.php`, `Modules/Core/app/Reports/ReportableRegistry.php`, registrations in `CoreServiceProvider` (`Media`) and the CMS service provider (`Content`, `Comment`, `Contributor`).

- [ ] **Step 1: Failing tests.** Registered types are reportable; an unregistered type is refused; a media is reportable only when attached to a published, public owner, never a draft or an internal attachment such as an ERP or SAO file; the contract gives the label, the public link and the owner to notify.
- [ ] **Step 2: Contract, registry, registrations.**
- [ ] **Step 3: Run the touched tests, Pint, commit per repository.**

## Task 3: Reports and cases (D3, D4, D8)

**Files:** create migration for `core_reports`, `Modules/Core/app/Models/Report.php`, `Modules/Core/app/Enums/ReportStatus.php`, `Modules/Core/app/Enums/ReportResolution.php`, a case query service.

- [ ] **Step 1: Failing tests.** A report stores the columns of spec D3; a case is the open reports on one reportable with counts per category and first and last date; resolving a case resolves its open reports with one outcome; a report after closure opens a new case and shows the previous outcome; the reporter's identity is absent from anything the owner can read.
- [ ] **Step 2: Model, enums, case service.**
- [ ] **Step 3: Retention purge.** Contact and message removed 180 days after closure, a setting; category, dates and outcome kept; registered in `core_cron_jobs`.
- [ ] **Step 4: Run the touched tests, Pint, commit.**

## Task 4: Routes (D6)

**Files:** `Modules/Core/routes/web.php` (`/app`), `Modules/Core/routes/api.php` (`/api/v1`), a controller and form request, rate limiter, guest setting in `CoreDatabaseSeeder`.

- [ ] **Step 1: Failing tests.** `POST` under `/app` for users and, with the setting on, guests; `POST` under `/api/v1` through `AuthenticateApiRequest`; `GET` of active reasons per type; rate limit per address and per user; a second report by the same reporter on an open report answers as accepted and changes nothing; guest reports refused when the setting is off; no address stored for an anonymous report.
- [ ] **Step 2: Guest setting, seeded on, and the routes.**
- [ ] **Step 3: Run the touched tests, Pint, commit.**

## Task 5: Reaction (D5)

**Files:** `ReportReason` (reaction, threshold), a trusted-roles setting, a reaction service, a system actor for reports in Core.

- [ ] **Step 1: Failing tests.** A reason with `RequestDeletion` fires at once for a reporter in a trusted role and otherwise at 3 distinct reporters, editable per reason; a single anonymous report never fires it; the delete is captured as a pending `Modification` authored by the system actor, never by the reporter; the record follows its model's `pendingDeletionStrategy()`; approving deletes it and resolves the case `Removed`; rejecting brings it back; the reaction refuses a model without `HasApprovals` or whose installation has not turned deletions on, and only opens the case; the owner is notified without the reporter's identity.
- [ ] **Step 2: Reaction fields, trusted roles setting, service, system actor.**
- [ ] **Step 3: Run the touched tests, Pint, commit.**

## Task 6: Staff surface and notifications (D7, D9)

**Files:** a Filament resource for cases, staff actions, a notification class next to `PendingApprovalsNotification`. Follow `.ai/rules/filament.md` and the `filament-development` skill.

- [ ] **Step 1: Failing tests.** One notification per case to the users holding the permission to handle reports; a reporter with an account is notified of the outcome; the actions `handle`, `resolve`, `dismiss` work with their permissions; removal is the force delete of the record with the report's reason category, which on contents and media writes the exclusion; a `Privacy` report converts into an erasure request with the item as first target, the report linked and its contact copied.
- [ ] **Step 2: Notification, resource, actions.** The conversion waits for the erasure plan's Task 10; until then the step is marked as such here.
- [ ] **Step 3: Run the touched tests, Pint, commit.**

## Task 7: Documentation and closing

- [ ] **Step 1: Module docs.** Core and CMS READMEs and `docs/rag`: reasons and categories, reportables, routes, guest setting, reaction and its dependence on the installation's approvals, retention, staff workflow. User doc for the staff. The DSA note of the spec is linked, not copied.
- [ ] **Step 2: Index and spec status.**
- [ ] **Step 3: Close.** `## Delivery status (date)`, `**Documented in:**`, `status: completed`.

## Out of scope

Reporting people, decided against on 2026-10-13. Opening reports through MCP, which comes with the MCP server's phase 2. The Vue UI's report form, specified in the stack repository.
