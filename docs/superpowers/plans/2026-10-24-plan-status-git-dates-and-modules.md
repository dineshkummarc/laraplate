---
status: completed
---
# plan-status: Git Dates and Plan Modules Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `scripts/plan-status.php` the facts the public website still lacks about every plan: which modules it belongs to, and when its work started and finished, read from git.

**Architecture:** Two additions. `PlanParser` puts a `modules` field in every plan (front-matter `module:` first, then the plan's own `Modules/{Name}/docs/plans/` location, then the modules its task files name, then `platform`). A new `--git-dates` option adds `started` and `finished`, computed by a new `LastChange::ticks()` from `git log -p` of the plan file, through the `git()` helper `LastChange` already has. The last activity the website shows is the existing `updated` field (last commit that touched the file), not a new one. Without the option the output is unchanged apart from `modules`.

**Tech Stack:** PHP 8.5 (the script already uses `mb_trim()`), git CLI, the bash test harness `scripts/tests/plan-status-test.sh`.

**Spec:** `docs/superpowers/specs/2026-10-23-website-design.md` in `getlaraplate/website` (sections 7.3 and 9). Consumer: that repository's plan `docs/superpowers/plans/2026-10-24-website-v1.md`, Task 5.

## Global Constraints

- Every PHP change keeps `declare(strict_types=1);`, explicit types and the script's style (final classes, private methods, PHPDoc array shapes).
- No new dependency. Git is reached only through `LastChange::git()`.
- Without `--git-dates`, JSON, table and HTML outputs are what they are today, except the new `modules` key in JSON plan entries.
- Dates are the committer date as `YYYY-MM-DD` (`%cs`), like `updated`.
- Module slugs are the lowercased directory names under `Modules/` (`core`, `cms`, `ai`, `erp`, `mes`, `sao`, `crm`, `shop`), plus `platform` when a plan names no module.
- The tool never edits a plan.
- Tests: `bash scripts/tests/plan-status-test.sh <name-filter>` (also `composer run plan-status:test`). Format: `vendor/bin/pint --format agent <explicit file list>`.
- PHP 8.5 is required to run the script and its tests.

## Review Focus

1. A plan file git does not track (new, uncommitted): `started` and `finished` are `null`, nothing on stderr leaks into the JSON. Test in Task 2.
2. A plan inside a module submodule (a plan file under `Modules/SAO/docs/plans/`): ticks are read from the module's own repository. Test in Task 2.
3. A step unticked and ticked again: `started` stays the first tick, `finished` is the last one. Test in Task 2.
4. A front-matter `module:` naming an unknown slug (`module: billing`): kept, lowercased, not dropped. Test in Task 1.
5. Uppercase `- [X]` counts as a tick, as it does for step counting. Test in Task 2.

---

### Task 1: `modules` for every plan

**Files:**
- Modify: `scripts/plan-status.php` (`PlanParser::parse()` return array, new private method)
- Test: `scripts/tests/plan-status-test.sh`

**Interfaces:**
- Produces: plan array key `'modules' => list<string>`, always present, never empty. `private function modules(array $front_matter, string $path, array $tasks): array` on `PlanParser`; `$path` is the workspace-relative plan path, `$tasks` the parsed tasks (each with `files: list<string>`).

- [x] **Step 1: Write the failing tests** as `test_` functions in `scripts/tests/plan-status-test.sh`, reading the JSON with `php -r` like `test_last_change_is_the_last_commit_that_touched_the_file`:

```bash
test_modules_come_from_the_front_matter()          # "module: AI, core, billing"  -> ai,core,billing
test_modules_come_from_a_plan_inside_a_module()    # a plan file under Modules/SAO/docs/plans/ -> sao
test_modules_rank_the_modules_task_files_name()    # Modules/Core x2, Modules/AI x1 -> core,ai
test_modules_fall_back_to_platform()               # no module anywhere -> platform
```

- [x] **Step 2: Run them to see them fail**

Run: `bash scripts/tests/plan-status-test.sh modules`
Expected: 4 failed (no `modules` key).

- [x] **Step 3: Implement `modules()` and return `'modules' => $this->modules($front_matter, $this->workspace->relative($file), $tasks)` from `parse()`**

First match wins: front-matter `module` split on commas, trimmed, lowercased; `~(?:^|/)Modules/([^/]+)/docs/plans/~` on the path; counts of `~^Modules/([^/]+)/~` over every task's `files`, by count descending then first appearance; `['platform']`.

- [x] **Step 4: Run the whole harness to see it pass**

Run: `bash scripts/tests/plan-status-test.sh`
Expected: all passed.

- [x] **Step 5: Format and commit**

```bash
vendor/bin/pint --format agent scripts/plan-status.php
git add scripts/plan-status.php scripts/tests/plan-status-test.sh
git commit -m "feat(plan-status): report the modules of every plan"
```

### Task 2: `--git-dates`

**Files:**
- Modify: `scripts/plan-status.php` (`LastChange`, `PlanParser::all()` and `parse()`, option handling next to `$with_evidence`, `usage()`)
- Test: `scripts/tests/plan-status-test.sh`

**Interfaces:**
- Consumes: `LastChange::git(string $dir, array $args): ?string` (private, same class), Task 1's `parse()` array.
- Produces: `public function ticks(string $file): array` on `LastChange`, returning `array{started: ?string, finished: ?string}`. Option `--git-dates`; with it every plan array gains `'started' => ?string` and `'finished' => ?string`, `finished` only when the plan `state` is `done`. `PlanParser::all(?string $repo_filter, ?string $name_filter, bool $with_evidence, bool $with_git_dates = false): array`.

- [x] **Step 1: Write the failing tests.** Each builds a git repository in the workspace and commits with `GIT_AUTHOR_DATE` and `GIT_COMMITTER_DATE` set:

```bash
test_git_dates_come_from_ticked_steps()            # add 2026-01-01; tick one 01-05; tick rest 01-09; edit text 01-12
                                                   # -> started 2026-01-05, finished 2026-01-09, updated 2026-01-12
test_git_dates_leave_finished_empty_while_open()   # tick one of two on 02-03 -> started 02-03, finished null
test_git_dates_leave_started_empty_without_ticks() # -> started null
test_git_dates_keep_the_first_tick_after_a_retick()# tick 04-02, untick 04-04, tick 04-06 -> started 04-02, finished 04-06
test_git_dates_count_an_uppercase_x()              # "- [X]" on 05-02 -> started 05-02
test_git_dates_read_the_repository_owning_the_plan()# Modules/SAO is a nested repository; tick there on 06-02 -> started 06-02
test_git_dates_are_null_for_an_untracked_plan()    # written, never committed -> started null, finished null, JSON valid
test_git_dates_are_absent_without_the_option()     # no "started" key in the JSON
```

- [x] **Step 2: Run them to see them fail**

Run: `bash scripts/tests/plan-status-test.sh git_dates`
Expected: failures on every test reading `started`.

- [x] **Step 3: Implement `ticks()`, thread `$with_git_dates` from `--git-dates` through `all()` and `parse()`, and document the option in `usage()`**

`ticks()` calls `git(dirname($file), ['log', '--reverse', '--format=%x00%cs', '-p', '--', basename($file)])`; `null` output gives both dates `null`. Walking the lines: a line starting with `"\0"` sets the current day; a line matching `~^\+\s*- \[[xX]\]~` sets `started` once and `finished` every time. Usage line under `--no-evidence`:

```
  --git-dates                add started and finished from git history (first and
                             last commit that ticked a step); updated is the last change
```

- [x] **Step 4: Run the whole harness to see it pass**

Run: `bash scripts/tests/plan-status-test.sh`
Expected: all passed.

- [x] **Step 5: Check it on the real backlog**

Run: `php scripts/plan-status.php --format=json --no-evidence --git-dates | php -r '$p = json_decode(stream_get_contents(STDIN), true)["plans"]; echo count(array_filter($p, fn ($x) => $x["started"] !== null)), " of ", count($p), PHP_EOL;'`
Expected: most plans have a `started` date, no PHP warning.

- [x] **Step 6: Format and commit**

```bash
vendor/bin/pint --format agent scripts/plan-status.php
git add scripts/plan-status.php scripts/tests/plan-status-test.sh
git commit -m "feat(plan-status): add --git-dates for when a plan started and finished"
```

### Task 3: Documentation and closing

**Files:**
- Create: `Modules/Core/docs/PLAN_STATUS.md`, committed in `Modules/Core`
- Modify: `Modules/Core/README.md` (section *Scripts*, subsection *Plan status* linking the doc), committed in `Modules/Core`
- Modify: `scripts/plan-status.php` (`usage()` paragraph on front matter), `.ai/rules/plans.md`, `docs/superpowers/plans/INDEX.md`, this plan

- [x] **Step 1: Extend the front-matter paragraph of `usage()`**: "`module:` (one slug or a comma list) sets the plan's modules; otherwise they come from the plan's location under Modules/*/docs/plans or from the Modules/<Name>/ paths its tasks name, and `platform` when there are none."
- [x] **Step 2: Write `Modules/Core/docs/PLAN_STATUS.md`**: what the script reports, the `--root`, `--open`, `--sort`, `--format=json` and `--git-dates` options, the date rules (`started` first tick, `finished` last tick of a done plan, `updated` last change, `null` when git does not know the file), the module rules from Step 1, and that the public website reads `--format=json --git-dates`. Add a short *Plan status* subsection under *Scripts* in `Modules/Core/README.md` linking it.
- [x] **Step 3: Add to `.ai/rules/plans.md`** "Set `module:` when a plan's files do not show its module", with the reason: the website roadmap groups plans by module and falls back to `platform`.
- [x] **Step 4: Run** `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php` and `bash scripts/tests/plan-status-test.sh` — both PASS.
- [x] **Step 5: Close the plan** per `.ai/rules/plans.md`: every box resolved, front matter `status: completed`, a `## Delivery status (date): ...` section with a `**Documented in:**` line naming `Modules/Core/docs/PLAN_STATUS.md` and `Modules/Core/README.md`, and the plan's line in `docs/superpowers/plans/INDEX.md`.
- [x] **Step 6: Commit** in `Modules/Core`, then in laraplate

```bash
git -C Modules/Core add docs/PLAN_STATUS.md README.md && git -C Modules/Core commit -m "docs(core): document plan-status, its modules and git dates"
git add scripts/plan-status.php .ai/rules/plans.md docs/superpowers/plans/ Modules/Core
git commit -m "docs: plan-status modules and git dates delivered"
```

## Delivery status (2026-10-24): shipped

**Documented in:** `Modules/Core/docs/PLAN_STATUS.md`, `Modules/Core/README.md`.

`scripts/plan-status.php` puts `modules` on every plan and, with `--git-dates`, `started` and `finished` read from git. Both follow the tasks as written; the harness `scripts/tests/plan-status-test.sh` covers them.

Divergences from the plan:

- The tests ran on PHP 8.5.12 in Docker, since the development machine has no PHP 8.5.
- Pint was not run: `vendor/` could not be installed there, so the style was matched by hand.
- Two example paths of the form `Modules/SAO/docs/plans/<file>.md` in the Review Focus and Task 1 were reworded: the closed-plan test reads every such path in a delivered plan as a documentation pointer that must exist.
- Step 4 `php artisan test` was replaced locally by a script repeating the assertions of `tests/Unit/ClosedPlansPointToDocumentationTest.php`; the equivalent check passed locally. CI does not run that test today (the workflow in `.github/workflows/laravel.yml` uses PHP 8.4, checks out without submodules and has not run on a pull request), and no workflow runs the harness `scripts/tests/plan-status-test.sh` either. Only `Modules/Core` is checked out among the submodules, so pointers into the other modules were not resolved locally.
- The website spec (section 9) asked for Pest tests in `tests/Unit/`; the plan used the bash harness `scripts/tests/plan-status-test.sh` instead, decided in commit 5f438ba before execution.
