# Content Reports: Design

**Status:** Draft (for review)
**Date:** 2026-10-13
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

| Category | Seeded reason, as an example | Natural outcome |
|----------|-----------------------------|-----------------|
| `Rectification` | "Needs rectification", "Wrong information" | editorial change |
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

### D5. No automatic removal by default

A threshold of reports that hides an item automatically is a way to remove inconvenient content by reporting it
in bulk. It is not built by default. If an instance needs it, it is a seeded setting, off, per category, and it
hides pending review, never deletes.

### D6. Routes

- `POST` under `/app`, session, for authenticated users and for guests if the setting allows it.
- `POST` under `/api/v1`, through `AuthenticateApiRequest` with its switch and anonymous user.
- `GET` of the active reasons for a reportable type, so a client can build its form.
- The staff works from Filament and the generic CRUD, under the usual permissions.

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
- The contact and the message have a retention: a seeded number of days after the report is closed, then
  removed. The category, the dates and the outcome stay for statistics.
- An anonymous report stores no address; the rate limit uses it in memory only.

### D9. Notifications

A new case notifies the users who hold the permission to handle reports, through the in-app notifications, once
per case and not per report. A reporter with an account is notified of the outcome; a reporter with a contact
only gets the outcome by whatever channel the instance uses, outside this spec.

### D10. MCP

An agent reading a mailbox opens a report, through MCP, with the email text as the message and the channel
recorded. It reads the status of the reports it opened. It does not handle, resolve or convert them. This is the
only write the erasure spec allows an agent.

### D11. Legal note

The EU Digital Services Act asks hosting services for a notice mechanism for illegal content and a statement of
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
- No automatic hiding with the default settings.
- A privacy report converts into an erasure request with the link and the contact.
- The reporter's identity is absent from anything the reported item's owner can read.
- Retention removes contact and message, keeps category, dates and outcome.
- One notification per case.

## Open points

1. The seeded reason list and its translations.
2. Whether guests may report by default.
3. Retention days for the reporter's contact and message.
4. Whether a report can target a comment's author as well as the comment.
5. The plan, after the erasure plan's first two steps or alongside them: the two specs share the staff surface.
