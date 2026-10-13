# Content Reports: Design

**Status:** Draft (for review)
**Date:** 2026-10-13 (revised three times the same day)
**Author:** swolley + Claude
**Related:**
- `2026-10-12-right-to-erasure-design.md`: a `privacy` report becomes an erasure request.
- `2026-05-15-modification-moderation-design.md`: moderation of changes, which this is not.
- `2026-10-07-api-access-control-design.md`: the `/api` guard, anonymous user and rate limit.
- `2026-09-12-mcp-server-design.md`: an agent may open a report.

## Goal

Anyone who sees a published content, media, comment or contributor can report it to the staff with a reason
chosen from a list, for example "needs rectification" or "inappropriate content". The staff sees the reports
grouped per item, decides, and records the outcome. A report is the single public door for problems with what is
published, erasure requests included.

## Decided by the owner

Recorded on 2026-10-13.

1. Reporting is distinct from commenting: a report goes to the staff, not to the public.
2. A report carries a reason chosen from a list of values.
3. The model and the route are new: nothing exists today.
4. **A reason can trigger an action.** Reasons the staff elects as such request the deletion of the reported
   record through Core approvals, so it waits for a decision (D5).
5. **The existing `Hide` and `Block` strategies are kept as they are.** What a record looks like while its
   deletion waits is its model's `pendingDeletionStrategy()`, unchanged; the report does not choose it.
6. **Removing a reported record needs no route of its own.** It is the existing force delete; on contents and
   media it also writes the exclusion (erasure spec D2), with the report's reason category.
7. **Guests may report by default**, with a rate limit per address. A seeded setting turns it off.
8. **A reaction depends on the installation.** `RequestDeletion` works only where the installation turned on
   approvals for deletes on that table (erasure spec D18). Elsewhere the report only opens the case.
9. **Media are reportable** like contents. Their deletion waits for approval only where the installation turned
   it on for media; by default it does not.
10. **Seeded reasons: the short list** of D2, six reasons in Italian and English, editable afterwards.
11. **The reporter's contact and message are kept 180 days** after the case closes, a setting; category, dates and
    outcome stay.
12. **Trigger: a trusted role or a threshold.** At once from a role the staff marks as trusted; otherwise at 3
    distinct reporters, editable per reason. A single anonymous report never suffices.
13. **Only content is reported, not people.** A comment is reportable; its author is not. The staff sees the author
    from the case.

Everything else below is proposed.

## Current state

Verified on 2026-10-13: no report model, no report route, no reason list in Core or CMS. What exists nearby:

- Moderation of **changes**: `HasApprovals`, `Modification`, `ModerationAdapterRegistry`. It decides whether a
  pending write is applied. A report is about something already published, by someone who may not be a user, and
  shares nothing with that flow beyond the staff who handle it.
- In-app notifications: `core_notifications`, `routes/notifications.php`, `PendingApprovalsNotification` as a
  model for a staff notification.
- `Modules\Core\Models\Taxonomy`: an abstract base for value lists, activatable and sortable.

## Decisions

### D1. In Core, generic

The model, the reasons, the route and the staff surface live in Core. A model becomes reportable by implementing
`IReportable` and being registered, as moderation adapters are. CMS registers `Content`, `Comment` and
`Contributor`; Core registers `Media`. A later module, Shop for products, registers its own without touching Core.
The contract gives what the staff screen needs: a label, a link, the owner to notify if any.

### D2. Reasons are data with a fixed category

`core_report_reasons`, on the `Taxonomy` base: translatable label, description, active, order. The administrator
edits the list. Each reason has a **category from a fixed enum**, which decides where the report goes; the label
is free, the routing is not.

| Category | Seeded reason (decided 2026-10-13) | Natural outcome |
|----------|-----------------------------|-----------------|
| `Rectification` | "Needs rectification" | editorial change |
| `Privacy` | "Contains my personal data" | converted into an erasure request |
| `Inappropriate` | "Inappropriate content", "Spam" | review, possibly unpublish |
| `Legal` | "Copyright infringement" | legal review |
| `Other` | "Other" | review |

A reason can require the free text, so that "Other" is never empty. A reason can be limited to some reportable
types, so that "Copyright infringement" is not offered on a comment if the administrator does not want it.

### D3. The report

`core_reports`:

| Column | Use |
|--------|-----|
| `reportable_type`, `reportable_id` | what is reported |
| `report_reason_id` | the reason, and through it the category |
| `message` | free text, bounded |
| `reporter_id` | the user, when authenticated |
| `reporter_contact` | optional, for an anonymous reporter who wants an answer |
| `locale`, `url` | where the reporter was |
| `status` | `Open`, `InReview`, `Resolved`, `Dismissed` |
| `handled_by`, `handled_at`, `resolution`, `resolution_note` | the staff's answer |
| `erasure_request_id` | set when converted (D7) |
| timestamps | |

`resolution` is an enum: `Corrected`, `Unpublished`, `Removed`, `ConvertedToErasure`, `NoAction`. The note is for
the staff; what the reporter is told is a separate, shorter text.

### D4. Reports are grouped per item

The staff works on cases, not rows. A case is the open reports on one reportable, with a count per category and
the first and last date. Resolving a case resolves its open reports with one outcome. Reports arriving after a
case is closed open a new one, and the screen shows the previous outcome.

### D5. A reason can request the deletion

Decided by the owner: a reason can carry a **reaction**, `None` or `RequestDeletion`. With `RequestDeletion`, a
report that meets the trigger asks Core approvals to delete the reported record: the delete is captured as a
pending `Modification` with `Operation::Delete`, exactly as a delete by a user without the right to delete alone
would be. Nothing new is built for the waiting state.

**What the record looks like while it waits is its model's choice, unchanged.** `HasApprovals` already has
`PendingDeletionStrategy`:

- `Hide`: the record disappears for everyone who cannot decide on the deletion; who holds `approve` still sees it.
- `Block`: the record stays visible and refuses changes, except the attributes its model declares writable.

`pendingDeletionStrategy()` returns `Block` unless a model overrides it. A blog whose posts must vanish when
reported overrides it to `Hide` on that model; an editorial content model keeps `Block`. Decided by the owner: the
strategies stay as they are; a report does not pick one per request.

**The staff decides through the approval.** Approving the deletion deletes the record and resolves the case as
`Removed`. Rejecting it brings the record back as it was and resolves the case as `NoAction`, or whatever the staff
chooses. Removing it for good instead is a force delete, which on contents and media writes the exclusion
(erasure spec D2).

**Who requests.** The pending modification is authored by a system actor named for reports, not by the reporter:
an anonymous reporter cannot be a modifier, and the reporter's identity must not reach the record's history. The
AI module's `ModerationSystemUser` is a precedent to follow, not to reuse, since Core cannot depend on AI.

**Trigger.** Decided by the owner on 2026-10-13: the reaction fires at once when the reporter holds a role the staff
marks as trusted, and otherwise when 3 distinct reporters are reached, a value editable per reason. A single
anonymous report never hides anything by itself, which is what keeps reporting from becoming a way to remove
content someone dislikes.

**Scope of a reaction.** A reason's reaction can be limited to some reportable types, so that user-written posts
react and editorial contents do not.

**The author is told.** The owner of the reported record, if any, is notified that it is under review, without the
reporter's identity, and can answer on the case.

**Limits found in the code, 2026-10-13.**
- Only models using `HasApprovals` can wait for a deletion. `Content` does; `Media` gets it, with deletions under
  approval only where the installation turns them on (erasure spec D18).
- A record whose model does not require approval for deletes would be deleted at once by the capture path. The
  reaction must refuse to run on such a model rather than delete it.

### D6. Routes

- `POST` under `/app`, session, for authenticated users and for guests if the setting allows it.
- `POST` under `/api/v1`, through `AuthenticateApiRequest` with its switch and anonymous user.
- `GET` of the active reasons for a reportable type, so a client can build its form.
- The staff works from Filament and the generic CRUD, under the usual permissions.
- Removing a reported record is not a report route: it is the force delete of the CRUD route
  `/delete/{module}/{entity}` or of the media route, with the report's reason category (erasure spec D2).

Both posts are rate limited per client address and per user. A reporter cannot report the same item twice while
their earlier report is open; the second attempt answers as accepted and changes nothing, so the limit does not
tell a scraper which items exist.

### D7. A privacy report becomes an erasure request

A report in the `Privacy` category has a staff action "open erasure request". It creates the request of
`2026-10-12-right-to-erasure-design.md`, with the reported item as first target, the report linked and the
report's contact copied. The report is then `Resolved` with `ConvertedToErasure`. Conversion is a person's act:
a report proves nothing about who sent it.

### D8. The reporter's data

- The reporter's identity is never shown to the owner of the reported item, only to the staff.
- The contact and the message have a retention: 180 days after the report is closed by default, a setting, then
  removed by a purge registered in `core_cron_jobs`. The category, the dates and the outcome stay for statistics.
- An anonymous report stores no address; the rate limit uses it in memory only.

### D9. Notifications

A new case notifies the users who hold the permission to handle reports, through the in-app notifications, once
per case and not per report. A reporter with an account is notified of the outcome; a reporter with a contact
only gets the outcome by whatever channel the instance uses, outside this spec.

### D10. MCP

An agent reading a mailbox opens a report, through MCP, with the email text as the message and the channel
recorded. It reads the status of the reports it opened. It does not handle, resolve or convert them. Removing a record is a
force delete, under `forceDelete` and the token ability, once MCP opens writes (erasure spec D2).

### D11. Legal note

The EU Digital Services Act, articles 16 and 17, asks hosting services for a notice mechanism for illegal content and a statement of
reasons for decisions. When an instance hosts content written by its users, this model is the natural base for
it. Whether it is enough is a legal question for the operator; the spec does not claim compliance.

## Permissions

Per table: `select`, `insert`, `update` on reports and reasons. `insert` on reports is granted to the `guest` and
`anonymous` roles only if the setting for guest reports is on. Domain actions: `handle`, `resolve`, `dismiss`,
`convert_to_erasure`. A default staff role holds them.

## Testing

- Reporting each registered type works; an unregistered type is refused.
- A reason limited to some types is refused on the others; a reason requiring text refuses an empty message.
- Duplicates by the same reporter on an open report change nothing and answer as accepted.
- Rate limit per address and per user.
- Cases group open reports; resolving a case closes them; a later report opens a new case.
- A reason with `RequestDeletion`: below the trigger nothing happens; at the trigger a pending delete exists,
  authored by the system actor; the record follows its model's strategy, hidden with `Hide`, frozen with
  `Block`; approving deletes it, rejecting brings it back.
- A single anonymous report never triggers a reaction.
- The reaction refuses a model without `HasApprovals` or without approval on deletes.
- A privacy report converts into an erasure request with the link and the contact.
- The reporter's identity is absent from anything the reported item's owner can read.
- Retention removes contact and message, keeps category, dates and outcome.
- One notification per case.

## Open points

1. Decided 2026-10-13: the short list of D2, in Italian and English.
2. Decided 2026-10-13: guests may report by default, with a rate limit.
3. Decided 2026-10-13: 180 days.
4. Decided 2026-10-13: no, only the comment.
5. The plan: `docs/superpowers/plans/2026-10-13-content-reports.md`.
6. Decided 2026-10-13: `HasApprovals` on `Media`, deletions under approval only where the installation turns them on.
7. Withdrawn 2026-10-13: removal is a force delete, so no domain action needs `/api/v1` for this spec.
8. Decided 2026-10-13: trusted role or 3 distinct reporters, editable per reason.

## Sources

Read on 2026-10-13. Not legal advice.

- [Regulation (EU) 2022/2065, Digital Services Act](https://eur-lex.europa.eu/eli/reg/2022/2065/oj): article 16, notice and action mechanisms; article 17, statement of reasons.
- [Regulation (EU) 2016/679, GDPR](https://eur-lex.europa.eu/eli/reg/2016/679/oj): article 17 for a privacy report converted into an erasure request.
