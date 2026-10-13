# Right to Erasure and Exclusions for Contents and Media: Design

**Status:** Draft (for review)
**Date:** 2026-10-12, revised four times on 2026-10-13
**Author:** swolley + Claude
**Related:**
- `2026-10-13-content-reports-design.md`: a report is the public entry point; a privacy report opens an erasure request.
- `2026-09-12-mcp-server-design.md`: MCP reaches the same operations once it opens writes.
- `2026-09-27-core-approvals-subsystem-design.md`: approvals capture deletes, force deletes and restores at the model.
- `2026-07-21-aggregate-version-sets-design.md`: history per record and per set; reverts are version sets.
- `2026-09-18-media-ai-analysis-search-design.md`: analyses are shared by `content_hash`.
- `2026-07-22-module-import-command-framework-design.md`: `RecordOrigin` and the import path.

**Revisions.** The first draft of 2026-10-12 was reviewed with the owner on 2026-10-13 in three passes. The last
pass replaced the dedicated `exclude` action with the existing force delete, kept the force delete snapshot as
evidence, and split removals by purpose. The body was rewritten in place: the spec had not been approved, so
nothing it said had been relied on. Superseded decisions are listed with what replaced them.

## Goal

What is removed from an instance stays removed: it does not come back through an import, a restore or a return
to an earlier version. How much of it the instance keeps depends on why it was removed. A moderation removal
keeps the evidence for a while. A removal for privacy keeps nothing but what proves the request was honoured and
what stops the data from coming back.

## Legal frame

Not legal advice; the operator of each instance validates it. Read on 2026-10-13:

- **GDPR art. 17** covers every personal datum the controller processes, public or not: history, logs,
  embeddings, analyses, conversations, search logs. Its paragraph 3 lists the exceptions: freedom of expression
  and information, a legal obligation, archiving in the public interest and research, and the establishment,
  exercise or defence of legal claims.
- **Accountability, art. 5(2)**, requires the controller to prove compliance; a suppression record that stops the
  data from being collected again is what makes an erasure last.
- **Backups.** The UK ICO accepts that backups are not rewritten if the data is put "beyond use" and deleted on
  the normal rotation ([ICO, right to erasure](https://ico.org.uk/for-organisations/guide-to-dp/guide-to-the-uk-gdpr/individual-rights/right-to-erasure)).
- **Pseudonymised data is personal data** ([EDPB Guidelines 01/2025](https://www.edpb.europa.eu/our-work-tools/documents/public-consultations/2025/guidelines-012025-pseudonymisation_es)).
  A plain hash of an identifier that carries a name is reversed by guessing names; a keyed hash is stronger but
  stays pseudonymous.
- **Recipients, art. 19.** The controller tells the recipients of the data about the erasure.
- **News archives in Italy.** Cass. SS.UU. 19681/2019 balances the right to be forgotten with historical
  recounting and states that online archives were not its subject; later decisions (7559/2020, 9147/2020) are
  cited for deindexing archives and were not verified here. Delisting and redaction, not deletion, are the usual
  answer for an archive.

## Decided by the owner

Recorded on 2026-10-12 and 2026-10-13, in order.

1. Erasure applies to a **media on its own**, not only to a content and its attachments.
2. Exclusions live in a **dedicated table, morph-like**: class, optional local id, optional source identity,
   matched during imports.
3. An excluded record **cannot be restored from versions**, soft deleted or live.
4. For a live excluded record the block is **total**: no return to any version.
5. An exclusion is lifted only by an **explicit revocation**.
6. Reports are a new model and route, with a reason from a list of values.
7. The exclusions table is **also the history** of exclusions.
8. Excluding is governed by the **`forceDelete` permission**: who may delete a record for good obtains the same
   result.
9. Contents and media get the operation **in Filament and in the Vue UI**.
10. **No dedicated `exclude` action or route.** On models that declare it, the existing force delete writes the
    exclusion (D2). Supersedes the earlier "direct exclusion action on every surface" and "domain action on
    `POST /{action}/{module}/{entity}`".
11. The exclusion **keeps the id of the snapshot** the force delete already writes (D2).
12. **Four cases by purpose** (D1); a removal for privacy goes only through an erasure request, never through a
    force delete (D3); the source identity is a **keyed hash with a dedicated key** (D8); moderation snapshots have
    a **configurable retention and a legal hold** (D12).
13. **Reaction time is not retention.** The request's due date is the GDPR term with reminders (D5). How long
    anything is kept follows the existing mechanism of the instance: settings with a default, purges registered
    in `core_cron_jobs` (D12, D18).
14. **Snapshot retention selects from the exclusions table**, through `snapshot_version_id`. No new
    `VersionChangeType`: the snapshot stays a `Deleted` version (D12).
15. **MCP adds no confirmation of its own.** A caller over MCP can do what its user's permissions and its token's
    abilities allow, under the model's approval rules. Setting the permissions right is the job of whoever grants
    them. Supersedes open point 10 of the previous revision.
16. **The code declares what is possible, the installation decides what is active**, with a default (D18).
    Approvals do not apply to everyone: a writer holding `approve`, a superadmin and the console act directly.
17. **`Media` gets `HasApprovals`**, with the operations under approval chosen per installation and **none by
    default**, so that media behave as today until an installation turns deletions on (D18).

## Why a delete is not enough

Verified in the code on 2026-10-12 and 2026-10-13.

| Copy | What a delete does to it today | Source |
|------|--------------------------------|--------|
| The row | Soft delete by default; `Modules\Core\SoftDeletes\SoftDeletes` keeps the row unless the model or the setting `soft_deletes_{table}` turns it off. | `SoftDeletes::softDeletesEnabledBySettings()` |
| History | A force delete writes a `VersionChangeType::Deleted` version with strategy `SNAPSHOT` holding the whole image, selected attributes encrypted. Every earlier version keeps its full content. | `HasVersions::writePendingVersionDelete()` |
| Pending approvals | A captured operation is a `Modification` with its payload. A force delete on a model that requires approval is captured, not executed. | `HasApprovals::captureOperation()`, `CrudService::delete()` |
| Embeddings | Removed for `Media` on force delete only. | `MediaLifecycleObserver::forceDeleted()` |
| Search index | `Media` reindexes its owner on delete. Removal of the record's own document is to verify per driver. | `MediaLifecycleObserver` |
| Media files | `Media` uses Core's own `SoftDeletes`. Whether Spatie deletes files and conversions on a force delete is **to verify**. | `Models/Media.php` |
| Duplicate media | The same file uploaded twice is two rows with one `content_hash`. | `MediaAnalysisRefcountObserver` |
| Media analyses | Captions and transcripts, shared by `content_hash`, deleted with the last media holding it. | same |
| Media drafts | A separate table and collection. | `Models/MediaDraft.php` |
| Inline references | A content body can point at a media file; how is **to verify**. | |
| Translations | `ContentTranslation` rows; behaviour on delete is to verify. | `CMS\Models\Translations` |
| Outbox | Events with a payload, published outside. | `Models/OutboxEvent.php` |
| Provenance | `RecordOrigin` holds `source_label` and `url`; `referable_id` is mandatory. | `create_record_origins_table` |
| Imports | `RecordOriginRegistry::inspect()` answers `Missing`, `Unchanged` or `Changed`. Nothing says "do not". | `Import/Support/RecordOriginRegistry.php` |

The force delete path today: `CrudService::delete()` requires `forceDelete`, calls `forceDelete()` on each record
and returns the pending modification when approvals capture it; the route is `/delete/{module}/{entity}` in
`routes/crud.php`. Media also have `routes/media.php`.

## Scope

**In:** CMS contents with translations, references, contributors, comments and ratings; Core media with files,
conversions, drafts, metadata and duplicates; their copies in Core, CMS and AI; backups through D13.

**Out:** ERP documents, which have legal retention; external search engines and third parties, which the report
lists; user accounts, an existing flow a request may call (open point 7).

## Decisions

### D1. Four cases, by purpose

| Case | Basis | What happens | What stays |
|------|-------|--------------|------------|
| **Moderation**: spam, inappropriate, copyright, editorial | editorial removal; possible legal claims | force delete, as today (D2) | the snapshot as evidence, for a configurable time (D12); the exclusion with the snapshot id |
| **Privacy**: art. 17 | right to erasure | every copy removed, internal and non-public ones included (D3, D7) | the exclusion without snapshot and without title; the request reduced to a minimum (D5) |
| **News archive** | balance with freedom of information | `Delist` or `Redact`, decided by a person with a written reason (D3) | the content, no longer findable by name or anonymised |
| **Legal hold** | defence of legal claims, art. 17(3)(e) | the snapshot is not deleted while the hold lasts (D12) | the snapshot, with restricted access |

### D2. Force delete on excludable models writes the exclusion

No new action and no new route. A model declares itself excludable through `IExcludable`; `Content` and `Media`
do. When a force delete of such a record **executes**:

- the participants (D6) remove its derived copies except the history: embeddings, search document, files,
  drafts, analyses with their refcount, outbox payloads, `RecordOrigin` rows;
- the `Deleted` snapshot version is written as today and kept;
- an exclusion row is written with `snapshot_version_id`, the reason category and the channel (D8).

Consequences:

- Every surface that already force deletes inherits it: the CRUD route on `/app` and `/api`, Filament, the Vue UI
  and, when MCP opens writes, its `delete` tool. The permission is `forceDelete`, plus the token ability on `/api`
  and MCP.
- A force delete captured by approvals writes nothing until it is approved and executed.
- A soft delete writes no exclusion: it is reversible.
- The request carries an optional `reason_category` and `note`; without them the category is `Manual`.
- Removing a record to import it again clean needs a revocation afterwards. Accepted: a record deleted for good
  that comes back with the next import is already a defect.

### D3. Privacy goes through an erasure request

A removal for privacy destroys the evidence and needs the requester's identity checked, so it is never a force
delete. It is an erasure request decided by a person, executed from the staff screens, with three remedies:

| Remedy | Effect |
|--------|--------|
| `Purge` | every copy removed, the snapshot and the whole history included; exclusion without snapshot |
| `Redact` | the record stays; named fields or passages are replaced; every version holding the old value is removed; exclusion written |
| `Delist` | the record stays at its address but leaves internal search, listings, sitemaps and the API, with a no-index signal; exclusion written |

The `Delist` flag is a new CMS column, **excluded from versioning** (`$dontVersionable`), otherwise a return to an
earlier version would list the content again. The decision picks the remedy per item; one request can mix them.

### D4. A media is a target on its own

An item can name a `Media` with no content. It acts on every media sharing its `content_hash`; the review screen
lists them first, with the contents whose body points at those files, so that a `Redact` can be added on them. A
removed media leaves a broken reference, which the review shows.

### D5. The request record

`core_erasure_requests`: subject name and contact, channel (`report`, `form`, `email`, `manual`), the report it
came from, received at, due at, status, handler, identity verification (by whom, how, when), decision and legal
reason, recipients notified. `core_erasure_request_items`: one target each, proposed and decided remedy, fields to
redact, outcome, report.

Status: `Received`, `IdentityVerified`, `UnderReview`, `Decided`, `Executing`, `Completed`, `Failed`,
`Rejected`; each transition a domain action with its own permission. The due date comes from a seeded setting, one
month by default; extending it is a recorded decision; reminders fire before it.

**Recipients, art. 19.** The review lists where the data went: outbox events and their destinations, API and MCP
tokens that read it if the audit says so, publication dates. The request records whom the controller notified.

**Minimisation after closure.** After a seeded period the request keeps only dates, outcome, categories of data
removed, decided remedies and who decided. Name, contact and message are deleted.

### D6. Participants, not a central purger

Core defines `IErasureParticipant` and a registry, as `ModerationAdapterRegistry` and `OwnerAuthorizerRegistry`
do:

- `findCandidates(ErasureSubject $subject): list<ErasureTarget>`: optional, read-only.
- `copiesOf(ErasureTarget $target): list<string>`: what this participant holds, for the review screen.
- `erase(ErasureTarget $target, ErasureRemedy $remedy): ErasureReport`: idempotent.

Core: rows, versions, set membership, pending modifications, embeddings, search documents, media files,
conversions, drafts and duplicates, outbox payloads, `RecordOrigin` rows. CMS: contents, translations, pivots,
comments, ratings, contributors, references, inline media references. AI: media analyses, refcount respected.
Core knows no CMS or AI class. A disabled module is reported as not executed. D2 and D3 use the same participants.

### D7. The purge primitive and history removal

Core gets a primitive that removes one record and every derived copy registered by the participants, the history
included: every version holding a value of the record and its membership in version sets, through
`VersioningService`, never a raw query from a module. `Purge` and `Redact` use it; a force delete does not, since
it keeps the snapshot. The review screen says that nothing can be restored afterwards.

### D8. The exclusions table

`core_import_exclusions`, independent of `RecordOrigin`: an exclusion outlives the record, works for records never
imported or created by hand, and must not keep `RecordOrigin`'s label and URL.

| Column | Required | Use |
|--------|----------|-----|
| `excludable_type` | yes | morph class |
| `excludable_id` | no | the local id; kept after deletion as a historical key that points at nothing |
| `source_key_hmac`, `external_id_hmac` | no, together | keyed hash of the source identity |
| `content_hash` | no | media only: the file, from any source |
| `case` | yes | `Moderation`, `Privacy`, `Archive`, `Legal` |
| `reason_category`, `note` | `reason_category` yes | the report reason category or `Manual`; the note never holds the removed data |
| `remedy` | yes | `ForceDelete`, `Purge`, `Redact`, `Delist` |
| `snapshot_version_id` | no | the force delete's `Deleted` version; null for privacy |
| `snapshot_expires_at`, `legal_hold` | no | D12 |
| `channel`, `token_id` | yes, no | `app`, `api`, `mcp`, `filament`, `erasure_request`, `console`; the token when there was one |
| `erasure_request_id`, `report_id` | no | where it came from |
| `executed_at`, `execution_status` | | the job's outcome |
| `created_by`, `revoked_at`, `revoked_by`, `revocation_reason`, timestamps | | |

At least one of local id, source identity and content hash. Unique on type plus source identity. No title, slug
or URL: a title can be the very name that was erased. An exclusion on a whole source is not allowed.

**Keyed hash.** HMAC-SHA256 with a dedicated keyring, `CORE_EXCLUSIONS_HMAC_KEYS` in the environment, not `APP_KEY`:
rotating the application key must not lose every match. Each row stores the id of its key (open point 9). The rows are **pseudonymous personal data kept as a suppression list**, for that purpose only, and
the documentation says so.

**A CRUD entity like the others.** Read for the history, with filters on type, case, reason, channel, author,
status and revoked. Insert by hand, to exclude what was never imported: an external id or a media file. Update for
revocation and legal hold, with their permissions. Generic delete denied through `DeniesGenericCrudWrites`: rows
are never deleted.

### D9. Matching at import

In Core's shared import path, before an importer writes:

- `ExternalRecordState::Excluded`, next to `Missing`, `Unchanged` and `Changed`.
- `ImportRowOutcome::Excluded`, next to `Created`, `Updated` and `Skipped`, counted in import reports.
- It blocks **updates as well as creations**: after a `Redact` or `Delist` the source answers with a new
  fingerprint, `inspect()` says `Changed`, and the importer would write the old text back.
- Media are also matched by `content_hash`: an import skips the file; a manual upload of an excluded file shows a
  warning and the editor decides.

CMS importers and the proprietary importers go through `RecordOriginRegistry` and `ImportRowContext`; whether
every importer does is checked in the plan.

### D10. Excluded records cannot be restored

Decided by the owner: no restore from the bin, no return to any version, soft deleted or live. Paths found on
2026-10-13:

| Path | Where |
|------|-------|
| Restore from the bin | `SoftDeletes::restore()`, called by `CrudService` and Filament |
| Restore of media | `HasMedia::restoreMedia()`, `restoreAllMedia()` |
| Approved restore | `HasApprovals` runs `Operation::Restore` when approved, possibly much later |
| Return to a version | `HasVersions::revertToVersion()`, `Version::revertWithoutSaving()` |
| Restore of a revision | `HasVersionedRelations::restoreToRevision()` |
| Seed reconciliation | `SeedReconciler` |

**Guard points in Core:** a global listener on `restoring`; a check in `VersionSetManager` for `Revert` sets, and
in `revertWithoutSaving()` and `restoreToRevision()` if they do not pass there; pending `Restore` modifications
cancelled when an exclusion is written. The refusal is an `ExcludedRecordException`, never a `false`, since
`CrudService` reads `false` from `restore()` as "captured for approval". The snapshot of D2 is covered by the same
guard: it cannot be used while the exclusion holds. A test per path proves the refusal.

### D11. Revocation is explicit

A revocation is an update with its own permission, a mandatory reason and a trace; the row stays. Afterwards
imports match again, and a record whose snapshot still exists can be recreated from it by a staff action. A media
recreated that way has no file if Spatie deleted it (open point 1); restoring media is not promised. After a
`Purge` there is nothing to restore.

### D12. Snapshot retention and legal hold

Decided by the owner on 2026-10-13. A seeded setting, `exclusions.snapshot_retention_days`, default 180: the
six months during which the Digital Services Act lets a user complain about a moderation decision (art. 20). The
purge does not scan `core_versions`: it reads the exclusions whose snapshot expired and that carry no legal hold,
deletes each version **by `snapshot_version_id`** through D7's history primitive, and empties the column; the
exclusion stays. Other `Deleted` versions are never touched. No new version change type. The purge is a command
registered in `core_cron_jobs`, as the other purges of the instance are, so an administrator sees and schedules
it from the same place.

`legal_hold` suspends expiry and restricts who can read the snapshot to the users with the permission for it.
Setting and lifting the hold are recorded.

### D13. Backups

Backups are not rewritten. Excluded data in them is beyond use: not read, not restored for other purposes, gone at
rotation. After any restore of a backup, the command `core:exclusions:reapply` removes again every record that
matches an active exclusion, with the remedy the exclusion records; the deployment documentation makes it a step
of every restore.

### D14. A report is the public entry point

A report whose reason is in the `Privacy` category is converted by staff into an erasure request, linked to it. A
moderation report can end in a force delete of the reported record, with the report's reason category passed to
D2. Spec `2026-10-13-content-reports-design.md`.

### D15. Execution of a request is queued, per item, idempotent

One job per item, retried on failure, with a report per participant. A failed participant leaves the item
`Failed` with what ran. Execution runs with approval capture off for the request's targets only, under a
mechanism named for erasure and scoped to the job; `HasApprovals::setForcedApprovalUpdate()` is read first and
reused only if its meaning fits. The pending modifications of the targets are removed.

### D16. Filament and the Vue UI

- **Filament.** On excludable models the force delete confirmation asks the reason category and a note, says
  that the record will not be imported or restored again, and for a media lists the duplicates by
  `content_hash` with the choice to include them. Resources: exclusions, read-only with revoke, manual insert and
  legal hold; erasure requests with their workflow.
- **Vue UI.** The delete modal already shows "Annulla · Sposta nel cestino · Cancella definitivamente" to users
  with `forceDelete` (stack spec `2026-05-29-laraplate-ui-design.md`, *Delete / soft delete*). For contents and
  media, "Cancella definitivamente" gains the same reason category, note and duplicates. Its design belongs to the
  stack repository, starting from `ui-preview-v2.html`. The backend gives a capability flag per record type and the
  duplicates of a media.

### D17. Delivery order

1. Participants and the purge primitive for Core, CMS and AI (D6, D7).
2. Excludable force delete, the exclusions table, import matching, the restore block, revocation, snapshot
   retention and legal hold, with the Filament part (D2, D8 to D12, D16).
3. Backup reapply (D13).
4. Erasure requests with their workflow and minimisation (D3, D4, D5, D15).
5. `Delist`.
6. `Redact`, the hardest: it rewrites text, translations and history.
7. MCP, through its phase 2: the `delete` tool inherits D2; agents open reports.

### D18. Capabilities: declared in code, switched per installation

Decided by the owner on 2026-10-13, as a rule for this spec and the reports spec. The project already works this
way for soft deletes, locking and versioning: the model declares a capability in code, a per-table setting turns it
on or off, and the seeder gives it a default. Approvals follow the same rule:

- `approvalOperations()` keeps saying which operations a model **can** put under approval.
- A per-table setting, seeded like `soft_deletes.enabled.*` and `locking.enabled.*`, says which of them are
  **active**. Its default is what the model returns today, so nothing changes for existing models.
- Approvals still apply only to writers who cannot decide alone (`HasApprovals::approvalGate()`): a holder of
  `approve`, a superadmin and the console act directly. An editor who may approve media changes an article's image
  without asking anyone.

**Media.** `Media` gets `HasApprovals`, can put `Delete` and `ForceDelete` under approval, and has **none active by
default**: uploads, edits and Spatie's own saves, such as conversions, never wait. An installation that moderates
user media turns the deletions on for that table. Its pending deletion strategy is `Hide`, so a reported image does
not stay visible while it waits; that applies only where the installation turned deletions on.

The approvals spec carries the matching amendment (`2026-09-27-core-approvals-subsystem-design.md`).

## Permissions

`forceDelete` on the record's table for a moderation exclusion. On erasure requests: `select`, `insert`, `update`
and the domain actions `verify_identity`, `decide`, `execute`. On exclusions: `select`, `insert`, `update`, plus
`revoke`, `legal_hold` and `read_snapshot`; generic delete denied. A default role for the person in charge holds
them. No default role grants `decide`, `execute`, `revoke`, or `forceDelete` on contents and media, to a service
account.

## Testing

- Force delete on an excludable model: derived copies gone, snapshot kept, exclusion written with snapshot id,
  category, channel and token; captured force deletes write nothing until executed; soft delete writes nothing.
- `Purge`: no row, version, pending modification, embedding, search document, file, conversion, draft, duplicate,
  analysis or snapshot remains; a shared analysis survives while another media holds the hash; the exclusion has
  no snapshot id.
- A media target removes its duplicates and lists the contents pointing at it.
- `Redact`: new value stored, no version holds the old one. `Delist`: absent from search, listings, sitemap and
  API, present at its address, not undone by a revert.
- Import: excluded records neither created nor updated, by keyed source identity and by hash; outcome `Excluded`.
- Restore: every path of D10 refuses; the snapshot cannot be used while the exclusion holds; after revocation a
  snapshot recreates the record.
- Retention: an expired snapshot is deleted and the exclusion stays; a legal hold stops expiry.
- Reapply after a restored backup removes the excluded records again.
- History: no exclusion row is ever deleted; a revoked one stays with its revocation.
- The keyed hash matches under every key of the keyring and does not depend on `APP_KEY`.
- No erased value in item reports, exclusions, notes or logs.

## Open points

Verified in the code on 2026-10-13. The proposed solutions were confirmed by the owner on 2026-10-13 and are
part of the design; the section keeps its name so earlier references still resolve.

1. **Media files.** Read in `spatie/laravel-medialibrary` 11.23.9, `MediaObserver::deleted()`: files and
   conversions are removed when `isForceDeleting()` is true, which the CRUD force delete sets. Nothing to add for
   erasure. A delete with soft deletes switched off did not set it; fixed in Core `047fa3c` and `1a13e6e`.
2. **Search.** Scout removes the document on force delete, and on soft delete as well since `scout.soft_delete` is
   `false` in Core's config. The gap is **content embeddings**: only media drop theirs. Proposed: Core's participant
   removes every `ModelEmbedding` of the record, all locales.
3. **Translations.** `cms_contents_translations` cascades at database level (`cascadeOnDelete()`), with no model
   events: the content's snapshot does not hold them and their own versions remain. Proposed: on excludable models,
   translations are deleted through Eloquent before the content, inside one version set; the exclusion keeps the
   **set's id**, so a recreation brings back content and translations together; a privacy purge also removes the
   translations' versions.
4. **Media in bodies.** No server-side embedding of media in content text was found; editors may paste URLs.
   Proposed: the CMS participant searches the translations' text for the media's uuid, file name and URL, and lists
   the matches in the review.
5. **Reverts and recreation.** `restoreToRevision()` opens a `Revert` set, so `VersionSetManager` covers it;
   `revertWithoutSaving()` has no caller other than `revert()` and gets the check anyway. No primitive recreates a
   record from a `Deleted` snapshot: every revert works on a live record. Proposed: a new `VersioningService`
   primitive, used only after a revocation.
6. **Version sets.** `restoreToRevision()` already skips, and reports, a subject that no longer exists when forced.
   Proposed: a purged member is marked as such in its set, and restoring the others skips it and reports it.
7. **User accounts.** Proposed: an item type `User`, calling the existing user deletion as a definitive one, with
   the AI purge it already triggers. Contents the user wrote are not deleted: the author becomes a "removed user".
8. **Seeded values.** Proposed: request due date one month with reminders at 7 and 2 days; request contact and
   message deleted 12 months after closure; snapshot retention 180 days (decided, D12). All as settings.
9. **HMAC key rotation.** Correction of the previous revision: rehashing is impossible, the plain values are not
   stored. Proposed: a keyring. Each row stores the id of its key, new rows use the current key, matching tries
   every key, and a key is never dropped while rows use it.
10. The plan: `docs/superpowers/plans/2026-10-13-right-to-erasure.md`.

## Sources

Read on 2026-10-13. Not legal advice; the operator of each instance validates the legal frame.

- [Regulation (EU) 2016/679, GDPR](https://eur-lex.europa.eu/eli/reg/2016/679/oj): article 5(2) accountability, article 12(3) one-month term, article 17 right to erasure and its exceptions, article 19 notification of recipients.
- [Regulation (EU) 2022/2065, Digital Services Act](https://eur-lex.europa.eu/eli/reg/2022/2065/oj): article 20, internal complaint-handling for at least six months after a decision; basis of the 180-day snapshot retention default.
- [ICO, Right to erasure](https://ico.org.uk/for-organisations/guide-to-dp/guide-to-the-uk-gdpr/individual-rights/right-to-erasure): backups put beyond use and deleted at rotation. UK GDPR, used as guidance.
- [EDPB, Guidelines 01/2025 on Pseudonymisation](https://www.edpb.europa.eu/our-work-tools/documents/public-consultations/2025/guidelines-012025-pseudonymisation_es): pseudonymised data remains personal data. Adopted for consultation in January 2025; whether a final version was published was not checked.
- [Hunton, summary of the EDPB guidelines](https://www.hunton.com/insights/publications/edpb-advises-on-pseudonymisation-for-gdpr-compliance): secondary source.
- [Cass. SS.UU. 22 July 2019 n. 19681, text](https://www.moltocomuni.it/wp-content/uploads/sentenza-22-luglio-2019-n.-19681.pdf), with the comment by [Peron, MediaLaws](https://www.medialaws.eu/wp-content/uploads/2019/11/peron.pdf): balance with historical recounting; online archives not its subject.
- [Agenda Digitale on online archives and deindexing](https://www.agendadigitale.eu/sicurezza/privacy/la-notizia-diffamatoria-va-rimossa-dagli-archivi-online-del-giornale-ordinanza-della-cassazione/): secondary source citing later decisions, Cass. 7559/2020 and 9147/2020, not verified here.
