# plan-status: Git Dates and Plan Modules Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `scripts/plan-status.php` the two facts the public website needs about every plan: which modules it belongs to, and when its work started, finished and was last touched, read from git.

**Architecture:** Two additions to `PlanParser`. A `modules` field is always present in the plan array (front-matter `module:` first, then the plan's own `Modules/{Name}/docs/plans/` location, then the modules its task files name, then `platform`). A new `--git-dates` option adds `started`, `finished` and `last_activity`, computed from `git log -p` of the plan file in the repository that owns it. Without the option the output is unchanged.

**Tech Stack:** PHP 8.5 (the script already uses `mb_trim()`), git CLI, Pest 4.

**Spec:** `../docs/superpowers/specs/2026-10-23-website-design.md` in the stack repository (sections 7.3 and 9). Consumer: the website plan `docs/superpowers/plans/2026-10-24-website-v1.md` in the stack repository, Task 5.

## Global Constraints

- Every PHP change keeps `declare(strict_types=1);`, explicit types and the script's existing style (final classes, `private function`, PHPDoc array shapes).
- No new dependency. Git is called through the CLI the script already assumes is present.
- Without `--git-dates` the JSON, table and HTML outputs are byte-for-byte what they are today, except for the new `modules` key in JSON plan entries.
- Dates are the committer date as `YYYY-MM-DD` (`git log --format=%cs`).
- Module slugs are the lowercased directory names under `Modules/` (`core`, `cms`, `ai`, `erp`, `mes`, `sao`, `crm`, `shop`), plus `platform` for plans that name no module.
- The tool never edits a plan.
- Tests run from the laraplate root: `php artisan test --compact <path>`. Format: `vendor/bin/pint --format agent <explicit file list>`.
- The suite needs PHP 8.5; a container with an older PHP cannot run it.

## Review Focus

1. A plan file that is not tracked by git (new, uncommitted): all three dates are `null`, no warning on stdout, the JSON stays valid. Test in Task 2.
2. A plan inside a module submodule (`Modules/SAO/docs/plans/x.md`): dates come from the module's own repository, not from laraplate's. Test in Task 2.
3. A step re-ticked after being unticked (`- [x]` → `- [ ]` → `- [x]`): `started` stays the first tick, `finished` is the last one. Test in Task 2.
4. A front-matter `module:` naming an unknown slug (`module: billing`): kept as written, lowercased, not dropped. Test in Task 1.
5. Uppercase `- [X]` ticks count as ticks, as they do for the existing step counting. Test in Task 2.

---

### Task 1: `modules` for every plan

**Files:**
- Modify: `scripts/plan-status.php` (`PlanParser::parse()` return array; new private method)
- Test: `tests/Unit/PlanStatusModulesTest.php`

**Interfaces:**
- Produces: plan array key `'modules' => list<string>`, always present, never empty. Method `private function modules(array $front_matter, string $path, array $tasks): array` on `PlanParser`, where `$path` is the workspace-relative plan path and `$tasks` are the parsed task arrays (each has `files: list<string>`).

- [ ] **Step 1: Write the failing tests** in `tests/Unit/PlanStatusModulesTest.php`, Pest style like `PlanStatusCancelledStepsTest.php` (temporary `--root`, `exec` the script with `--format=json --no-evidence`, clean up in `finally`). One helper writes plan files and returns the decoded report.

```php
it('takes modules from the front-matter, as a comma list', function (): void {
    // plan front-matter: "module: AI, core, billing"
    expect(planByFile($report, 'a.md')['modules'])->toBe(['ai', 'core', 'billing']);
});

it('takes the module from a plan stored inside the module', function (): void {
    // file at Modules/SAO/docs/plans/2026-01-01-b.md, no front-matter, no task files
    expect(planByFile($report, '2026-01-01-b.md')['modules'])->toBe(['sao']);
});

it('ranks the modules named in task files, most cited first', function (): void {
    // tasks list `Modules/Core/app/X.php`, `Modules/Core/tests/XTest.php`, `Modules/AI/app/Y.php`
    expect(planByFile($report, 'c.md')['modules'])->toBe(['core', 'ai']);
});

it('falls back to platform when no module is named', function (): void {
    expect(planByFile($report, 'd.md')['modules'])->toBe(['platform']);
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Unit/PlanStatusModulesTest.php`
Expected: FAIL, undefined array key `modules`.

- [ ] **Step 3: Implement `modules()` and add `'modules' => $this->modules($front_matter, $this->workspace->relative($file), $tasks)` to the array `parse()` returns**

Order of precedence, first match wins: `module` front-matter key split on commas, trimmed, lowercased; `~(?:^|/)Modules/([^/]+)/docs/plans/~` on the path; counts of `~^Modules/([^/]+)/~` over every task's `files`, sorted by count descending then by first appearance; `['platform']`.

- [ ] **Step 4: Run the tests to see them pass**

Run: `php artisan test --compact tests/Unit/PlanStatusModulesTest.php tests/Unit/PlanStatusCancelledStepsTest.php`
Expected: PASS (the cancelled-steps test proves the existing output still parses).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --format agent scripts/plan-status.php tests/Unit/PlanStatusModulesTest.php
git add scripts/plan-status.php tests/Unit/PlanStatusModulesTest.php
git commit -m "feat(plan-status): report the modules of every plan"
```

### Task 2: `--git-dates`

**Files:**
- Modify: `scripts/plan-status.php` (`PlanParser::all()`, `PlanParser::parse()`, new private method, option handling near `$with_evidence`, `usage()`)
- Test: `tests/Unit/PlanStatusGitDatesTest.php`

**Interfaces:**
- Consumes: Task 1's `parse()` return array.
- Produces: option `--git-dates`; with it, every plan array gains `'started' => ?string`, `'finished' => ?string`, `'last_activity' => ?string`. `PlanParser::all(?string $repo_filter, ?string $name_filter, bool $with_evidence, bool $with_git_dates = false): array`. Method `private function gitDates(string $file, string $state): array` returning `array{started: ?string, finished: ?string, last_activity: ?string}`.

- [ ] **Step 1: Write the failing tests** in `tests/Unit/PlanStatusGitDatesTest.php`. A helper initialises a git repository in a temporary root (`git init`, local `user.name` and `user.email`) and commits with `GIT_AUTHOR_DATE` and `GIT_COMMITTER_DATE` set, so dates are exact.

```php
it('dates a plan from its ticked steps', function (): void {
    // 2026-01-01 add plan with three "- [ ]" steps; 2026-01-05 tick one;
    // 2026-01-09 tick the other two; 2026-01-12 edit a paragraph only
    expect($plan['started'])->toBe('2026-01-05')
        ->and($plan['finished'])->toBe('2026-01-09')
        ->and($plan['last_activity'])->toBe('2026-01-12');
});

it('leaves finished empty while the plan is open', function (): void {
    // 2026-02-01 add; 2026-02-03 tick one of two
    expect($plan['started'])->toBe('2026-02-03')->and($plan['finished'])->toBeNull();
});

it('leaves started empty when nothing is ticked', function (): void {
    expect($plan['started'])->toBeNull()->and($plan['last_activity'])->toBe('2026-03-01');
});

it('keeps the first tick as the start after an untick and a retick', function (): void {
    // 2026-04-02 tick; 2026-04-04 untick; 2026-04-06 tick again (plan done)
    expect($plan['started'])->toBe('2026-04-02')->and($plan['finished'])->toBe('2026-04-06');
});

it('counts an uppercase X as a tick', function (): void {
    expect($plan['started'])->toBe('2026-05-02');
});

it('reads dates from the repository that owns the plan', function (): void {
    // Modules/SAO is its own git repository inside the root repository; the plan is committed there on 2026-06-02
    expect($plan['last_activity'])->toBe('2026-06-02');
});

it('reports null dates for a plan git does not know', function (): void {
    // plan file written but never committed
    expect($plan['started'])->toBeNull()->and($plan['last_activity'])->toBeNull();
});

it('adds no date keys without the option', function (): void {
    expect($plan)->not->toHaveKey('started')->not->toHaveKey('last_activity');
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Unit/PlanStatusGitDatesTest.php`
Expected: FAIL on the first assertion that reads `started`.

- [ ] **Step 3: Implement `gitDates()`, thread `$with_git_dates` from the option through `all()` and `parse()`, and document the option in `usage()`**

Run `git -C <directory of the file> log --reverse --format=@@%cs -p -- <file name>` through `exec` with `escapeshellarg` and stderr discarded; a non-zero exit or no output gives all three `null`. Walk the output, oldest commit first: every `@@YYYY-MM-DD` line sets the current date and `last_activity`; a line matching `~^\+\s*- \[[xX]\]~` sets `started` once and `finished` every time. Return `finished` only when `$state` is `done`. Usage line, under `--no-evidence`:

```
  --git-dates                add started, finished and last_activity from git history
                             (first ticked step, last ticked step, last commit)
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `php artisan test --compact tests/Unit/PlanStatusGitDatesTest.php tests/Unit/PlanStatusModulesTest.php tests/Unit/PlanStatusCancelledStepsTest.php`
Expected: PASS.

- [ ] **Step 5: Check it on the real backlog**

Run: `php scripts/plan-status.php --format=json --no-evidence --git-dates | php -r 'echo count(array_filter(json_decode(stream_get_contents(STDIN), true)["plans"], fn ($p) => $p["last_activity"] !== null)), PHP_EOL;'`
Expected: a number equal to the count of committed plans (about 120 on 2026-10-24), and no PHP warning.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --format agent scripts/plan-status.php tests/Unit/PlanStatusGitDatesTest.php
git add scripts/plan-status.php tests/Unit/PlanStatusGitDatesTest.php
git commit -m "feat(plan-status): add --git-dates for started, finished and last activity"
```

### Task 3: Documentation and closing

**Files:**
- Modify: `scripts/plan-status.php` (`usage()` paragraph on front-matter)
- Create: `Modules/Core/docs/PLAN_STATUS.md`, committed in `Modules/Core`
- Modify: `Modules/Core/README.md` (section *Scripts*, new subsection *Plan status* linking the doc), committed in `Modules/Core`
- Modify: `.ai/rules/plans.md`
- Modify: `docs/superpowers/plans/INDEX.md`, this plan

- [ ] **Step 1: Extend the front-matter paragraph of `usage()`**: after the sentence on `status:`, add "`module:` (one slug or a comma list) sets the plan's modules; otherwise they come from the plan's location under Modules/*/docs/plans or from the Modules/<Name>/ paths its tasks name, and `platform` when there are none."

- [ ] **Step 2: Write `Modules/Core/docs/PLAN_STATUS.md`:** what the script reports, the `--root`, `--open`, `--format=json` and `--git-dates` options, the date rules (first tick, last tick for done plans, last commit, `null` for files git does not know) and the module rules from Step 1, and that the public website reads `--format=json --git-dates`. Add a three-line *Plan status* subsection under *Scripts* in `Modules/Core/README.md` that links it.

- [ ] **Step 3: Add to `.ai/rules/plans.md`** a rule "Set `module:` when a plan's files do not show its module", with one line on why: the website roadmap groups plans by module and falls back to `platform`.

- [ ] **Step 4: Run the documentation tests**

Run: `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php`
Expected: PASS.

- [ ] **Step 5: Close the plan:** tick the boxes, add a `**Documented in:**` line under the header naming `Modules/Core/docs/PLAN_STATUS.md` and `Modules/Core/README.md` (Scripts, Plan status), add a `## Delivery status (date): ...` section, and add the plan's line to `docs/superpowers/plans/INDEX.md`.

- [ ] **Step 6: Commit** in `Modules/Core` (doc and README), then in laraplate (script, rule, plan, index, submodule pointer)

```bash
git -C Modules/Core add docs/PLAN_STATUS.md README.md && git -C Modules/Core commit -m "docs(core): document plan-status, its modules and git dates"
git add scripts/plan-status.php .ai/rules/plans.md docs/superpowers/plans/ Modules/Core
git commit -m "docs: plan-status modules and git dates delivered"
```
