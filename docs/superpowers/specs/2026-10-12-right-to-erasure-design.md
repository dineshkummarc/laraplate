# Right to Erasure for Contents and Media: Design

**Status:** Draft (for review)
**Date:** 2026-10-12
**Author:** swolley + Claude
**Related:**
- `2026-09-12-mcp-server-design.md`: the MCP door is the last step of this spec, not its subject.
- `2026-09-27-core-approvals-subsystem-design.md`: approvals capture deletes at the model.
- `2026-07-21-aggregate-version-sets-design.md`: history is kept per record and per set.
- `2026-09-18-media-ai-analysis-search-design.md`: analyses are shared by content hash.
- `2026-07-02-cms-content-provenance-ai-assistance-design.md`: `RecordOrigin`, references.

## Goal

A person asks for their data to be removed from the published contents and media of an instance. The
request is recorded, a person in charge decides it, and the decision is carried out on every copy the
instance holds, not only on the visible record. The result is provable afterwards without keeping the
erased data.

An external agent, for example one that reads a mailbox, may **open** a request through MCP. It never
decides one and never executes one.

## Why this needs a design and not a `delete`

Verified in the code on 2026-10-12.

| Copy | What a delete does to it today | Source |
|------|--------------------------------|--------|
| The row | Soft delete by default. `Modules\Core\SoftDeletes\SoftDeletes` keeps the row unless the model or the setting `soft_deletes_{table}` turns it off. | `SoftDeletes::softDeletesEnabledBySettings()` |
| History | A hard delete of a versioned model **writes the whole image into a version**. A soft delete records the deleted-at column, and every earlier version keeps its full content. | `HasVersions::$pending_version_delete`; `Overrides\Model` uses `HasVersions` |
| Pending approvals | A captured operation is stored as a `Modification` with its payload. Deleting a `Content` with approvals on is itself captured, not executed. | `HasApprovals::captureOperation()`, `Modification` |
| Embeddings | Removed for `Media` on force delete only. No generic removal for other models was found. | `MediaLifecycleObserver::forceDeleted()` |
| Search index | `Media` reindexes its owner on delete. Removal of the record's own document is to verify per driver. | `MediaLifecycleObserver` |
| Media files | `Media` uses Core's own `SoftDeletes`, not Laravel's. Whether Spatie deletes the files and conversions on a soft delete, on a force delete, or never, is **to verify** in the installed package. | `Models/Media.php` |
| Media analyses | Captions and transcripts, shared by every media with the same `content_hash`. Deleted only when the last media with that hash is force deleted. | `AI\Observers\MediaAnalysisRefcountObserver` |
| Media drafts | A separate table and collection. | `Models/MediaDraft.php` |
| Translations | Rows of `ContentTranslation`. Behaviour on delete is to verify. | `CMS\Models\Translations` |
| Outbox | Events with a payload, published to the outside. | `Models/OutboxEvent.php` |
| Provenance | `RecordOrigin` maps an external source id to a local record: an import would bring an erased record back. | `Models/RecordOrigin.php` |

No erasure flow exists for contents or media. The only erasure in the codebase is
`ai:erase-rag-queries`, which removes one user's logged documentation questions, and
`PurgeAssistantDataOfDeletedUserObserver`, which removes a deleted user's conversations and suggestions.

## Scope

**In:** CMS contents with their translations, references, contributors, comments and ratings; Core media
with files, conversions, drafts and metadata; the copies listed above in Core, CMS and AI.

**Out, stated so nobody assumes them:**
- ERP documents. Fiscal records have legal retention. A request that touches them is answered with that basis, not executed.
- Backups. The instance cannot rewrite them. The operator's retention policy is the answer, and the request report names it.
- External search engines and third parties that already copied the content. The report lists what was published and when; delisting there is a manual act.
- Application logs, rotated by their own policy.
- User accounts. Deleting a user is an existing flow; this spec may call it, it does not redesign it.

## Decisions

### D1. Three remedies, not one

| Remedy | Effect | Typical case |
|--------|--------|--------------|
| `Purge` | Every copy of the record is removed. A tombstone stays. | A media depicting the person, a contributor profile. |
| `Redact` | The record stays; named fields or passages are replaced. Every earlier version holding the old value is removed. | An article that names the person among others. |
| `Delist` | The record stays published at its address, but leaves internal search, listings, sitemaps and the API, and carries a no-index signal. | Journalism kept for the record, no longer findable by the name. |

The decision picks the remedy per item. A request can mix them. `Delist` needs a flag the CMS does not
have today; its exact shape is a plan detail.

### D2. A request is a record, decided by a person

`core_erasure_requests` holds: subject name and contact, channel (`email`, `form`, `mcp`, `manual`),
received at, due at, status, the person handling it, identity verification (by whom, how, when), the
decision and its legal reason. `core_erasure_request_items` holds one target each: morph type and id, the
proposed and the decided remedy, the fields to redact, the outcome and the report.

Status: `Received`, `IdentityVerified`, `UnderReview`, `Decided`, `Executing`, `Completed`, `Failed`,
`Rejected`. Every transition is a domain action with its own permission, so `/app`, Filament and MCP use
the same path.

The due date is computed from the received date and a setting, seeded at one month, which is the GDPR
default term. Extending it is a recorded decision, not an edit of the date. The controller's legal
obligations are theirs to configure; the code enforces only that a date exists and that a reminder fires.

### D3. Participants, not a central purger

Core defines `IErasureParticipant` and a registry, on the pattern of `ModerationAdapterRegistry` and
`OwnerAuthorizerRegistry`:

- `findCandidates(ErasureSubject $subject): list<ErasureTarget>`: optional, read-only, used to prepare a request.
- `copiesOf(ErasureTarget $target): list<string>`: what this participant holds for the target, for the review screen.
- `erase(ErasureTarget $target, ErasureRemedy $remedy): ErasureReport`: idempotent.

Each module removes its own copies. Core: rows, versions, set membership, pending modifications,
embeddings, search documents, media files and drafts, outbox payloads, provenance. CMS: contents,
translations, pivots, comments, ratings, contributors, references. AI: media analyses, with the refcount
respected. Core knows no CMS or AI class. A module that is disabled is reported as not executed.

### D4. History is purged, not kept

An erasure that leaves the old value in `core_versions` has not erased it. `Purge` and `Redact` remove
every version holding the old value, and the membership of the record in version sets. A Core primitive on
`VersioningService` does it, never a raw query from a module. Restoring an erased record is no longer
possible, and the review screen says so before the decision.

### D5. Erasure is not captured by approvals

The decision on the request is the approval. Execution runs with approval capture off for the targets of
that request only, and the pending `Modification` rows of those targets are removed, since their payloads
hold the data. The mechanism must be named for erasure and scoped to one job. The existing
`HasApprovals::setForcedApprovalUpdate()` is read first and reused only if its meaning fits.

### D6. Tombstones prove the result and block re-import

After execution, the item keeps: target type and key, remedy, executed at, the participants that ran and
their counts. No erased value. For a `Purge`, the `RecordOrigin` source identity becomes a tombstone that
importers check, so a later `cms:import` does not recreate the record. Importers live outside the AGPL
backend; the check is in Core's import contract, so they inherit it without knowing this spec.

### D7. Execution is queued, per item, idempotent

One job per item, retried on failure, with a report per participant. A failed participant leaves the item
`Failed` with the list of what ran, so a retry finishes the rest. The request is `Completed` only when
every decided item is.

### D8. The external agent opens, nothing more

Through MCP, with a token on a service account that holds only `insert` and `select` on erasure requests:

| Tool | Does |
|------|------|
| `open_erasure_request` | Creates a request in `Received`, with the channel, the sender, the text of the message as evidence and proposed targets. |
| `find_erasure_candidates` | Read-only search through the participants' `findCandidates`, under the service account's permissions. |
| `erasure_request_status` | Status and due date of a request the agent opened. |

The agent cannot verify identity, decide, execute or extend. A message is untrusted text: it can name
anyone. A human verifies the identity before the request moves on. The answer to the requester is sent
when the request is `Completed` or `Rejected`, by the agent or by a person, with the report's summary.

### D9. Notifications and reminders

A new request notifies the users holding the decide permission, through the in-app notifications. A
scheduled command reminds them as the due date approaches and flags overdue requests.

### D10. The evidence has its own retention

The request itself holds personal data: the subject's contact and the message. After closure it is kept
for a configured period, seeded at a value the owner sets, then reduced to the tombstone.

## Permissions

Generated per table as every other entity: `select`, `insert`, `update` on requests and items. Three
domain-action permissions on top: `verify_identity`, `decide`, `execute`. A default role for the person in
charge holds all of them. No default role grants `decide` to a service account.

## Testing

- Each participant: after `Purge`, no row, version, pending modification, embedding, search document, file, conversion, draft or analysis of the target remains; a shared analysis survives while another media holds the hash.
- `Redact`: the record holds the new value, no version holds the old one.
- `Delist`: the record is absent from search, listings and the API, and present at its address.
- Re-import of a purged record is refused by its tombstone.
- Approvals: execution is not captured; pending modifications of the target are gone.
- Idempotency: running an item twice changes nothing the second time.
- MCP: the service account can open and read, and gets 403 on every other action.
- No erased value appears in the item's report or in the logs.

## Open points

1. Spatie's file deletion under Core's `SoftDeletes`: read the installed package and settle it first. It decides whether `Purge` must delete files itself.
2. Search document removal per driver, and whether a soft delete removes the document today.
3. Translation rows on delete.
4. The shape of the `Delist` flag in CMS and how listings and sitemaps honour it.
5. Version sets: removing one record's membership without breaking the set's restore of the others.
6. The seeded retention of closed requests (D10).
7. Whether a request may target a user account, and then call the existing user deletion with its AI purge.
8. The plan, written after points 1 to 3, since they size the participants.
