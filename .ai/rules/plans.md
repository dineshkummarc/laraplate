---
paths:
  - 'docs/superpowers/plans/**'
---

# Plans

## Check plan status before starting or resuming work
Before deciding what to work on next, list the development plans and their progress: `bash plan-status` from the laraplate-stack root (whole workspace), or `php scripts/plan-status.php` from laraplate (also `composer run plan-status`). Add `--open` to show only plans still open. Prefer finishing or closing an open plan over starting a new one: do not run many plans in parallel and leave others unfinished.

## Mark a dropped step `- [-]` and say why
A step deliberately not done is `- [-]`, with its reason on the same line: plan-status counts it as resolved but not done, and shows a task whose steps are all cancelled as CANCELLED. Use it when a plan closes without some of its work, and record the decision in the plan's delivery status too. Never leave dropped work as `- [ ]`: the plan would read as open forever.

## Close a plan in its front matter and its delivery status together
A finished plan carries `status: completed` in its YAML front matter and a `## Delivery status (date): ...` section with its `**Documented in:**` line. plan-status reads the front matter, `tests/Unit/ClosedPlansPointToDocumentationTest.php` reads the section: one without the other makes the two disagree. Use `completed`, never `closed` or `done`. A plan replaced by a later one is `status: superseded`, and its delivery status names the successor. Every box is resolved first: `- [x]` or `- [-]` with its reason.

## Set `module:` when a plan's files do not show its module
plan-status gives every plan its modules from the front-matter `module:` (one slug or a comma list), then from the plan's location under `Modules/*/docs/plans/`, then from the `Modules/<Name>/` paths its tasks name. A plan that matches none, for example one about a script or a convention, reads as `platform`. The website roadmap groups plans by module and falls back to `platform`, so add `module: core` (or `module: ai, cms`) to the front matter whenever the plan belongs to a module its files do not name.
