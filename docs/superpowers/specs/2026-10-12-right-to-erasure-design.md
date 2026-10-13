# Right to Erasure for Contents and Media: Design

**Status:** Draft (for review)
**Date:** 2026-10-12 (revised 2026-10-13, twice)
**Author:** swolley + Claude
**Related:**
- `2026-10-13-content-reports-design.md`: a report is the entry point that opens an erasure request.
- `2026-09-12-mcp-server-design.md`: the MCP door is the last step of this spec, not its subject.
- `2026-09-27-core-approvals-subsystem-design.md`: approvals capture deletes and restores at the model.
- `2026-07-21-aggregate-version-sets-design.md`: history per record and per set; reverts are version sets.
- `2026-09-18-media-ai-analysis-search-design.md`: analyses are shared by `content_hash`.
- `2026-07-22-module-import-command-framework-design.md`: `RecordOrigin` and the import path.

**Revision 2026-10-13.** The first draft was reviewed with the owner. Added: a media as a target on its own
(D2), import exclusions replacing the `RecordOrigin` tombstone (D7), the restore block (D8), the report as
entry point (D10) and a delivery order (D13). The draft's sections were rewritten in place: it had not been
approved, so nothing it said had been relied on.

## Goal

A person asks for their data to be removed from the published contents or media of an instance. The request
is recorded, a person in charge decides it, and the decision is carried out on every copy the instance holds,
not only on the visible record. An erased or excluded record can neither come back through an import nor be
restored from history. The result is provable afterwards without keeping the erased data.

Staff exclude a record directly from `/app`, `/api` or an MCP client (D14). An erasure request is the
workflow for the cases that need a decision on someone else's data; it ends in the same action.

## Decided by the owner

Recorded on 2026-10-12 and 2026-10-13.

1. Erasure applies to a **media on its own**, not only to a content and its attachments.
2. Excluded records are listed in a **dedicated table, morph-like**: class, optional local id, optional source
   identity, matched during imports so that what is excluded is not imported again.
3. An excluded record **cannot be restored from versions**, whether it was soft deleted or is still live.
4. For a live excluded record the block is **total**: no return to any version, before or after the exclusion.
5. An exclusion is lifted only by an **explicit revocation**.
6. Reports are a new model and route, with a reason chosen from a list of values (spec
   `2026-10-13-content-reports-design.md`).
7. **Exclusion is a direct action on every surface.** On `/app`, `/api` and MCP the same action force deletes a
   record with every copy and adds it to the exclusions table (D14). It does not need an erasure request.
8. **The exclusions table is also the history of exclusions**, not only the import filter (D15).
9. **`exclude` is governed by the `forceDelete` permission**, not by a permission of its own: whoever may delete
   a record for good obtains the same result. Recorded 2026-10-13, replacing D14's first wording.
10. **The action is needed in Filament and in the Vue UI, for contents and media** (D16).
11. **No route of its own.** `exclude` is a domain action on the existing route `POST /{action}/{module}/{entity}`;
    a report's reason never deletes, it can only request a deletion through approvals (reports spec D5).

Everything else below is proposed and open to review.

## Why a `delete` is not enough

Verified in the code on 2026-10-12.

| Copy | What a delete does to it today | Source |
|------|--------------------------------|--------|
| The row | Soft delete by default; `Modules\Core\SoftDeletes\SoftDeletes` keeps the row unless the model or the setting `soft_deletes_{table}` turns it off. | `SoftDeletes::softDeletesEnabledBySettings()` |
| History | A hard delete of a versioned model **writes the whole image into a version**. A soft delete records the deleted-at column; every earlier version keeps its full content. | `HasVersions::$pending_version_delete`; `Overrides\Model` uses `HasVersions` |
| Pending approvals | A captured operation is a `Modification` with its payload. Deleting a `Content` with approvals on is itself captured, not executed. | `HasApprovals::captureOperation()` |
| Embeddings | Removed for `Media` on force delete only. No generic removal for other models was found. | `MediaLifecycleObserver::forceDeleted()` |
| Search index | `Media` reindexes its owner on delete. Removal of the record's own document is to verify per driver. | `MediaLifecycleObserver` |
| Media files | `Media` uses Core's own `SoftDeletes`, not Laravel's. Whether Spatie deletes files and conversions on a soft delete, a force delete, or never, is **to verify** in the installed package. | `Models/Media.php` |
| Duplicate media | The same file uploaded twice is two rows with one `content_hash`. | `MediaAnalysisRefcountObserver` |
| Media analyses | Captions and transcripts, shared by `content_hash`, deleted with the last media holding the hash. | same |
| Media drafts | A separate table and collection. | `Models/MediaDraft.php` |
| Inline references | A content body can point at a media file. Whether bodies embed media URLs, and how, is **to verify**. | |
| Translations | `ContentTranslation` rows. Behaviour on delete is to verify. | `CMS\Models\Translations` |
| Outbox | Events with a payload, published outside. | `Models/OutboxEvent.php` |
| Provenance | `RecordOrigin` holds `source_label` and `url`, and its `referable_id` is mandatory. | `create_record_origins_table` |
| Imports | `RecordOriginRegistry::inspect()` answers `Missing`, `Unchanged` or `Changed`; an importer creates or updates accordingly. Nothing says "do not". | `Import/Support/RecordOriginRegistry.php` |

The only erasures in the codebase today: `ai:erase-rag-queries` for a user's logged documentation questions,
and `PurgeAssistantDataOfDeletedUserObserver` for a deleted user's conversations.

## Scope

**In:** CMS contents with translations, references, contributors, comments and ratings; Core media with files,
conversions, drafts, metadata and duplicates; the copies above in Core, CMS and AI.

**Out:**
- ERP documents. Fiscal records have legal retention; a request touching them is answered with that basis.
- Backups. The report names the operator's retention policy.
- External search engines and third parties that copied the content. The report lists what was published and when.
- Application logs, rotated by their own policy.
- User accounts: an existing flow, which a request may call (open point 7).

## Decisions

### D1. Three remedies

| Remedy | Effect | Typical case |
|--------|--------|--------------|
| `Purge` | Every copy is removed. An exclusion stays. | A media depicting the person, a contributor profile. |
| `Redact` | The record stays; named fields or passages are replaced; every version holding the old value is removed. An exclusion stays. | An article naming the person among others. |
| `Delist` | The record stays at its address, but leaves internal search, listings, sitemaps and the API, and carries a no-index signal. An exclusion stays. | Journalism kept for the record, no longer findable by name. |

The decision picks the remedy per item; one request can mix them. The `Delist` flag is a new CMS column. It is
**excluded from versioning** (`$dontVersionable`), otherwise returning to an earlier version would list the
content again without passing any check.

### D2. A media is a target on its own

An item can name a `Media` with no content. Executing it acts on every media sharing its `content_hash`, since
the same file uploaded twice is the same data; the review screen lists them before the decision. Contents whose
body points at one of those files are listed too, so that the decision can add a `Redact` on them. A media
removed from a body leaves a broken reference, which the review must show and not hide.

### D3. A request is a record, decided by a person

`core_erasure_requests`: subject name and contact, channel (`report`, `form`, `email`, `manual`), the report it
came from if any, received at, due at, status, handler, identity verification (by whom, how, when), decision and
its legal reason. `core_erasure_request_items`: one target each, morph type and id, proposed and decided remedy,
fields to redact, outcome, report.

Status: `Received`, `IdentityVerified`, `UnderReview`, `Decided`, `Executing`, `Completed`, `Failed`,
`Rejected`. Each transition is a domain action with its own permission, so `/app`, Filament and MCP share one
path.

The due date comes from the received date and a seeded setting, one month by default, the GDPR term. Extending
it is a recorded decision. The code enforces that a date exists and that reminders fire; the legal obligations
are the controller's.

### D4. Participants, not a central purger

Core defines `IErasureParticipant` and a registry, as `ModerationAdapterRegistry` and `OwnerAuthorizerRegistry`
do:

- `findCandidates(ErasureSubject $subject): list<ErasureTarget>`: optional, read-only.
- `copiesOf(ErasureTarget $target): list<string>`: what this participant holds, for the review screen.
- `erase(ErasureTarget $target, ErasureRemedy $remedy): ErasureReport`: idempotent.

Core: rows, versions, set membership, pending modifications, embeddings, search documents, media files,
conversions, drafts and duplicates, outbox payloads, `RecordOrigin` rows. CMS: contents, translations, pivots,
comments, ratings, contributors, references, inline media references. AI: media analyses, with the refcount
respected. Core knows no CMS or AI class. A disabled module is reported as not executed.

### D5. A purge primitive comes first

Before any request exists, Core gets a primitive that removes one record and every derived copy registered by
the participants: history, pending modifications, embeddings, search document, files. It is useful on its own to
an administrator, it is where the cost is, and it can be tested without the workflow around it. `Purge` is that
primitive plus an exclusion.

### D6. History is removed, not kept

`Purge` and `Redact` remove every version holding the old value and the record's membership in version sets,
through a primitive on `VersioningService`, never a raw query from a module. The review screen says before the
decision that nothing can be restored afterwards.

### D7. Import exclusions

`core_import_exclusions`, independent of `RecordOrigin`, because an exclusion must outlive the record, must work
for records never imported or created by hand, and must not keep `RecordOrigin`'s `source_label` and `url`. At
`Purge`, the record's `RecordOrigin` rows are deleted and the exclusion replaces them.

| Column | Required | Use |
|--------|----------|-----|
| `excludable_type` | yes | morph class |
| `excludable_id` | no | the local record, while it exists |
| `source_key_hash`, `external_id_hash` | no, together | SHA-256 of the source identity |
| `content_hash` | no | media only: the file, from any source |
| `reason` | yes | `erasure`, `editorial`, `legal`, `manual` |
| `erasure_request_id`, `report_id` | no | where it came from |
| `created_by`, `revoked_at`, `revoked_by`, `revocation_reason`, timestamps | | |

At least one of local id, source identity and content hash. Unique on type plus source identity, as
`RecordOrigin` is. The source identity is stored hashed: an external id can be a slug carrying a name, and
equality matching works on the hash. An exclusion on a whole source, with no external id, is not allowed: that is
import configuration, and a mistake there would empty a catalogue.

**Matching.** In Core's shared import path, before an importer writes:

- `ExternalRecordState::Excluded`, next to `Missing`, `Unchanged` and `Changed`.
- `ImportRowOutcome::Excluded`, next to `Created`, `Updated` and `Skipped`, counted in import reports.
- It blocks **updates as well as creations**. After a `Redact` or a `Delist` the record still exists; the source
  answers with a new fingerprint, `inspect()` says `Changed`, and without this the importer would write the
  redacted text back.
- A media is also matched by `content_hash`: an import skips the file; a manual upload of an excluded file shows
  a warning and lets the editor decide.

CMS importers already go through `RecordOriginRegistry` and `ImportRowContext`, and so do the proprietary
importers through the same contract, without Laraplate knowing them. Whether every importer passes there is
checked in the plan.

### D8. Excluded records cannot be restored

Decided by the owner: no restore from the bin, no return to any version, whether the record is soft deleted or
live. Restore paths found on 2026-10-13:

| Path | Where |
|------|-------|
| Restore from the bin | `SoftDeletes::restore()`, called by `CrudService` and by Filament |
| Restore of media | `HasMedia::restoreMedia()`, `restoreAllMedia()` |
| Approved restore | `HasApprovals` runs `Operation::Restore` when the modification is approved, possibly much later |
| Return to a version | `HasVersions::revertToVersion()`, `Version::revertWithoutSaving()` |
| Restore of a revision | `HasVersionedRelations::restoreToRevision()` |
| Seed reconciliation | `SeedReconciler` |

No route calling the version methods was found; they are model API and are guarded anyway.

**Guard points in Core, not checks in every caller:**
- A global listener on the `restoring` model event covers the bin, media, CRUD, Filament and approvals.
- `VersionSetManager`, when the set kind is `Revert`, covers `revertToVersion()`. `revertWithoutSaving()` and
  `restoreToRevision()` get the same check; whether they pass through the manager is verified in the plan.
- Creating an exclusion cancels the pending `Restore` modifications of the record; the listener stops any that
  escapes, at approval time.
- The refusal is an `ExcludedRecordException`, mapped to a clear status in the CRUD and a notice in Filament. It
  names the exclusion's reason, never the request's content. It is never a `false` return: `CrudService` reads a
  `false` from `restore()` as "captured for approval" and would answer wrongly.

A test per path of the table proves the refusal. A new restore path that is not guarded makes that test the
place where it is noticed.

### D9. Revocation is explicit

Lifting an exclusion is a domain action with the `decide` permission, a mandatory reason and a trace
(`revoked_at`, `revoked_by`, `revocation_reason`); the row is not deleted. After revocation, restore and import
work again only where data still exists: after a `Purge` there is nothing left to restore.

### D10. A report opens the request

The public entry point is a report (spec `2026-10-13-content-reports-design.md`). A report whose reason belongs
to the `privacy` category is converted by staff into an erasure request, which keeps the link. Requests can also
be opened by hand. People, forms and agents therefore share one door.

### D11. Execution is queued, per item, idempotent

One job per item, retried on failure, with a report per participant. A failed participant leaves the item
`Failed` with what ran, so a retry finishes the rest. The request is `Completed` when every decided item is.
Execution runs with approval capture off for the request's targets only, under a mechanism named for erasure and
scoped to the job; `HasApprovals::setForcedApprovalUpdate()` is read first and reused only if its meaning fits.
The pending modifications of the targets are removed, since their payloads hold the data.

### D12. Evidence has its own retention

A request holds personal data: the subject's contact and the message. After closure it is kept for a configured
period, then reduced to what the exclusion and the item reports need: no name, no contact, no message.

### D13. Delivery order

1. The purge primitive and the participants' `copiesOf` and `erase` for Core, CMS and AI (D4, D5, D6).
2. Import exclusions, the restore block and the direct exclusion action on `/app` and `/api` (D7, D8, D9, D14, D15).
   Usable before any request exists.
3. The request record, its workflow, notifications and reminders (D3, D11, D12).
4. `Delist` (D1): a column, the search, listing, sitemap and API filters, the no-index signal.
5. `Redact` (D1): the hardest, since it rewrites text, translations and history.
6. MCP: the exclusion action as a tool (D14), plus opening reports and reading candidates and statuses. It
   depends on the MCP server's phase 2, since phase 1 is read-only.

### D14. Exclusion is one action on every surface

Decided by the owner on 2026-10-13. `exclude` is a domain action registered in Core for every model that has a
participant, exposed on:

- `/app`, through the internal domain-action route, for the staff screens and Filament;
- `/api/v1`, through the same route under `AuthenticateApiRequest`, once domain actions are exposed there: today
  `POST /{action}/{module}/{entity}` is registered in `Modules/Core/routes/web.php` only (reports spec, open
  point 7);
- MCP, as a named tool, once the server opens writes (phase 2).

One action, one implementation: `Purge` of the record and of every copy (D4, D5, D6), then an exclusion row (D7),
in one queued job with the guarantees of D11. Its arguments are the record, the reason category and an optional
note; for a media, whether to include the duplicates sharing its `content_hash`, shown before confirmation. The
caller gets the exclusion's id and the job's status; the deletion is irreversible and every surface says so before
the call.

The action needs the **`forceDelete` permission** on the record's table (`ActionEnum::ForceDelete`), plus, on
`/api` and MCP, the token ability for it. Decided by the owner: excluding gives the result a force delete gives,
so it needs no permission of its own. It follows the record's approval rule for `Operation::ForceDelete`: a model
that captures force deletes for approval captures exclusions too, on every surface.

An erasure request ends in this action for its `Purge` items; `Redact` and `Delist` also write an exclusion, but
do not delete.

**MCP safeguard: open, see the note to the owner in Open points.**

### D16. The action in Filament and in the Vue UI

Decided by the owner: contents and media get the action in both interfaces.

- **Filament.** An `ExcludeAction` next to the force delete actions that `Modules\Core\Filament\Utils\HasTable`
  already provides, shown only for models with a participant and to users with `forceDelete`. A confirmation
  modal states that the deletion is irreversible, asks the reason category and an optional note, and for a media
  lists the duplicates by `content_hash` with the choice to include them. A bulk variant follows the same rules.
  The exclusions list is a read-only resource with a revoke action (D9, D15).
- **Vue UI.** The UI's delete modal already shows "Annulla · Sposta nel cestino · Cancella definitivamente" to
  users with `forceDelete` (stack spec `2026-05-29-laraplate-ui-design.md`, *Delete / soft delete*). The
  exclusion belongs there, for record types that support it. Its design is the UI's: it is specified in the
  stack repository, starting from `ui-preview-v2.html`, which is the UI's only visual source of truth. The
  backend gives it what it needs: a capability flag per record type and the duplicates of a media.

**Force delete stays as it is**, a separate button: it removes the row and leaves the hard-delete image in
history (D6's table). Whether "Cancella definitivamente" should also purge the history of a model with a
participant is open point 11.

### D15. The exclusions table is the history

Rows are never deleted. A revocation (D9) fills `revoked_at`, `revoked_by` and `revocation_reason` and keeps the
row, so the table answers what was excluded, when, by whom, from where, why, and whether it still holds. Columns
added to D7's list for that purpose:

| Column | Use |
|--------|-----|
| `remedy` | `Purge`, `Redact`, `Delist` |
| `channel` | `app`, `api`, `mcp`, `filament`, `erasure_request`, `console` |
| `token_id` | the API or MCP token used, when there was one |
| `executed_at`, `execution_status` | the job's outcome |

The rows hold no personal data: hashed source identity, `content_hash`, local id, type, reason category and the
people who acted. They therefore have no retention limit, unlike the request's evidence (D12). `excludable_id`
keeps the local id after a purge, as a historical key that points at nothing. No title, slug or URL is stored: a
title can be the very name that was erased.

The staff screens list exclusions with filters on type, reason, channel, author and status, and the revoked ones.

## Permissions

Per table as every entity: `select`, `insert`, `update` on requests, items and exclusions. Domain-action
permissions: `verify_identity`, `decide`, `execute`, and `revoke` on exclusions. Excluding uses `forceDelete`
(D14). A default role for the person in
charge holds all of them. No default role grants `decide`, `execute`, `revoke` or `forceDelete` on contents and media to a service account.

## Testing

- Each participant after `Purge`: no row, version, pending modification, embedding, search document, file,
  conversion, draft, duplicate or analysis of the target remains; a shared analysis survives while another media
  holds the hash.
- A media target removes its duplicates by hash and lists the contents pointing at it.
- `Redact`: the record holds the new value and no version holds the old one.
- `Delist`: absent from search, listings, sitemap and API, present at its address; a revert does not undo it.
- Import: an excluded record is neither created nor updated, by source identity and, for media, by hash; the
  outcome is `Excluded`.
- Restore: every path of D8 refuses an excluded record, soft deleted or live; after revocation it works where
  data exists.
- Approvals: execution is not captured; pending modifications and restores of the target are gone.
- Idempotency: running an item twice changes nothing the second time.
- `exclude` from `/app` and `/api`: same result as a `Purge` item; refused without `forceDelete` or, on `/api`,
  without the token ability; captured when the model captures force deletes; the exclusion row records channel and token.
- History: a revoked exclusion stays listed with its revocation; no row is ever deleted.
- No erased value in item reports, exclusions or logs.

## Open points

1. Spatie's file deletion under Core's `SoftDeletes`: read the installed package first; it decides whether
   `Purge` deletes files itself.
2. Search document removal per driver, and whether a soft delete removes it today.
3. Translation rows on delete.
4. How content bodies reference media files, for D2.
5. Whether `revertWithoutSaving()` and `restoreToRevision()` pass through `VersionSetManager`.
6. Version sets: removing one record's membership without breaking the set's restore of the others.
7. Whether a request may target a user account, and then call the existing user deletion with its AI purge.
8. The seeded retention of closed requests (D12).
9. **MCP safeguard for `exclude`, to decide by the owner.** Over MCP the caller is a model reading untrusted
   text, and the action is irreversible. Options: (a) same as `/api`, permission plus token ability, nothing
   more; (b) over MCP the call is captured by Core approvals and a person confirms it; (c) (b) by default, with
   a per-token pre-approval the owner can grant to a trusted integration. Recommended: (c). The MCP spec
   lists "what a token may pre-approve" among its phase 2 questions; this answers it for one action.
10. The plan, written after points 1 to 5, since they size the participants.
11. **Force delete and history.** Today a force delete keeps the full image in `core_versions`. For models with a
    participant, should "Cancella definitivamente" purge the history too, leaving `exclude` as purge plus ban? It
    would make "definitively" true, and change what force delete does today for every such model.
12. **MCP safeguard**, point 9, now reads: is the model's approval rule for force deletes enough over MCP, or does
    MCP add its own confirmation?
