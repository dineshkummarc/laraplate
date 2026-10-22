# Core Version Actor Resolution Implementation Plan

> **For agentic workers:** execute task by task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Status:** Blocked until `docs/superpowers/plans/2026-10-20-core-versioning-rebuild.md` is complete, every slice. Do not start before.

**Goal:** A version row is attributed to the explicit actor of its version set, else the signed-in user, else the system service account, else nobody.

**Spec:** `docs/superpowers/specs/2026-10-22-core-version-actor-resolution-design.md`.

**Architecture:** One resolver class in Core's versioning namespace. `VersionChange` and `HasVersionedRelations` call it instead of reading the model attribute directly, and the version row takes the same value as the set's `actor`. Names and signatures are confirmed in Task 1 against the rebuilt code, which this plan cannot see yet.

**Tech Stack:** PHP 8.5, Laravel 12, Pest. Modules: `Core`; `ERP`, `CMS`, `MES`, `SAO` only if the audit of Task 1 finds a model that needs a change.

## Global Constraints

- As in the rebuild plan: `declare(strict_types=1);`, explicit types, braces on every control structure, PHPDoc over inline comments, `#[Override]` only on real overrides, tests first, Pint from the root on an explicit file list (`.ai/rules/modules.md`), commits inside each module repository, `/usr/bin/git`.
- No data migration: the rebuild discards the existing history (its decision D12).
- Tests run with `php artisan test --compact <path>` from `laraplate/`.

---

### Task 1: Re-baseline on the rebuilt code

**Files:**
- Read: `Modules/Core/app/Models/Concerns/HasVersions.php`, `HasVersionedRelations.php`
- Read: `Modules/Core/app/Versioning/VersionWriter.php`, `ActiveVersionSet.php`, `VersionSetManager.php`, `Data/VersionChange.php`, `Data/VersionSetOptions.php`
- Modify: this plan, with the findings

- [ ] **Step 1: Confirm the rebuild is closed.** Every slice of `2026-10-20-core-versioning-rebuild.md` is ticked and the plan carries its delivery status. If not, stop.
- [ ] **Step 2: Re-read the files above.** Note in this plan where a fact in the spec's "What the code does today" no longer holds.
- [ ] **Step 3: Audit the models.** List every class that uses `HasVersions`, whether it has a `user_id` attribute and what that attribute means. Record the table here. Decide the first open point of the spec (model attribute as opt-in, or removed) and write the decision here.
- [ ] **Step 4: Check impersonation.** Find how an impersonating session sets the signed-in user and record whether the author is the impersonator or the impersonated. Settle the fourth open point of the spec.

### Task 2: Write the failing tests

**Files:**
- Create: `Modules/Core/tests/Unit/Versioning/VersionActorResolverTest.php`
- Create: `Modules/Core/tests/Integration/Versioning/VersionActorAttributionTest.php`
- Create, if the audit needs it: a model in `Modules/Core/tests/Stubs/` with a `user_id` of another meaning

- [ ] **Step 1: Resolver cases.** Explicit actor beats session; session beats system; system when nobody is signed in; `null` without the service account.
- [ ] **Step 2: End-to-end cases.** A system actor declared on the set is kept inside a request with a signed-in user. Membership rows follow the same resolver. The set's `actor` and the version rows' user agree.
- [ ] **Step 3: Foreign `user_id`.** A model whose `user_id` has another meaning is not attributed to that user, as the audit decided.
- [ ] **Step 4: Run them and see them fail.** Run: `php artisan test --compact Modules/Core/tests/Unit/Versioning/VersionActorResolverTest.php Modules/Core/tests/Integration/Versioning/VersionActorAttributionTest.php`. Expected: FAIL.

### Task 3: The resolver

**Files:**
- Create: the resolver class in `Modules/Core/app/Versioning/`
- Modify: `VersionChange`, `HasVersions`, `HasVersionedRelations`, `VersionWriter`, as Task 1 found them

- [ ] **Step 1: Create the resolver** with the order of the spec: explicit set actor, signed-in user, system service account, `null`.
- [ ] **Step 2: Resolve the system account** from `config('permission.users.system')`, cached for the request. A missing account gives `null` and never throws.
- [ ] **Step 3: Wire it** into `VersionChange` and `HasVersionedRelations` in place of the direct read of the model attribute. Split the two jobs of `getUserForeignKeyName()` if the audit asked for it.
- [ ] **Step 4: Run the tests of Task 2 and the versioning suites.** Run: `php artisan test --compact Modules/Core/tests/Unit/Versioning Modules/Core/tests/Integration/Versioning`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(core): resolve the author of a version from the set, the session or the system`.

### Task 4: Models the audit flagged

- [ ] **Step 1: One change per flagged model,** each with its own test, in its own module repository. Skip the task and say so here if the audit flagged none.
- [ ] **Step 2: Run the suites of every touched module.**
- [ ] **Step 3: Commit** in each touched module repository.

### Task 5: Consumer cleanup and documentation

- [ ] **Step 1: Shop auction.** If the auction configuration model still overrides `getVersionUserId()`, remove the override and keep its test green.
- [ ] **Step 2: Documentation.** Update the versioning section of `Modules/Core/docs/rag/MODULE.md` with the resolution order, and the Core README only if it names the old rule.
- [ ] **Step 3: Close the plan.** Add the delivery status with the divergences from this plan and a `**Documented in:**` line naming the Core docs. Tick the steps above.
