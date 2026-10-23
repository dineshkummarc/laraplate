# Core Versioning Rebuild, Slice 3 — Version-Set Manager

> **For agentic workers:** execute task by task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Goal:** the version-set manager keeps one set per root inside one transaction (D43), records whether each set was captured atomically (D47, D52), decides the stored kind of each root's set when it opens (D60), and locks a root without losing what the caller loaded on it (D34).

**Parent plan:** `docs/superpowers/plans/2026-10-20-core-versioning-rebuild.md`, Slice 3. **Spec:** `docs/superpowers/specs/2026-07-29-revision-centric-aggregate-history-design.md`. **Decisions:** D34 (the `lockExistingRoot()` part), D43, D47, D52, D60 in `docs/superpowers/specs/2026-08-04-revision-centric-aggregate-history-review.md`.

**Architecture:** the manager owns a *scope*: one transaction on one connection, opened by the outermost `run()`. Inside it each root touched has its own `ActiveVersionSet`, created the first time a `run()` names that root or the writer writes a change of it; creating one locks the root's row `FOR UPDATE`. `current()` returns the set of the root named by the innermost `run()`; the writer asks the manager for the set of the change's root (`setFor()`). Every set is finished when the outermost `run()` returns. A set stores its capture quality when its row is written: `atomic` when an application transaction was open on the root's connection before the business statement, which is always true inside a scope and, for the writer's fallback scope, true only when the caller had opened one. The transaction `RefreshDatabase` wraps around each test is not an application transaction: Laravel's transaction manager already leaves it out of `callbackApplicableTransactions()`, and the manager reads that.

**Tech Stack:** PHP 8.5, Laravel 12, Pest. Module: `Core`.

## Global Constraints

- As in the parent plan: `declare(strict_types=1);`, explicit types, PHPDoc over inline comments, `#[Override]` only on real overrides, tests first, Pint from the root on an explicit file list (`.ai/rules/modules.md`), commits inside the module repository, `/usr/bin/git`, no `Co-Authored-By` trailer.
- One connection per scope stays the rule (`MultipleVersionConnectionsNotSupportedException`): a second root on another connection is refused, as today.
- Out of scope, left to their slices: the wrappers of `save()`, `delete()` and the relation mutators that open the scope before the business SQL (Slice 4, D34, D42, D44), so that `Contributor::save()` writing its `User` and a translation written alone become atomic; the baselines due after an epoch change, a re-enable or a `best_effort` set (Slice 5); `isRevert()` and the revert context (Slice 6, D36).
- The Core Integration folder exhausts 2 GB in one process: run it per subfolder.

---

### Task 1: An application transaction, not the test harness's (D52)

**Files:**
- Create: `Modules/Core/app/Versioning/ApplicationTransactions.php`
- Test: `Modules/Core/tests/Integration/Versioning/ApplicationTransactionsTest.php`

- [x] **Step 1: Write the failing tests**

```php
it('sees no application transaction in a test wrapped by RefreshDatabase', function (): void {
    expect(DB::connection()->transactionLevel())->toBeGreaterThan(0)
        ->and(ApplicationTransactions::isOpenOn(DB::connection()))->toBeFalse();
});

it('sees the transaction the application opened, on its connection only', function (): void {
    DB::connection()->transaction(function (): void {
        expect(ApplicationTransactions::isOpenOn(DB::connection()))->toBeTrue();
    });
});
```

The first test is the one that fails if the harness transaction were counted. Add a case for another connection (the `version_set_affinity` in-memory connection the manager tests already configure) when a transaction is open only on the default one.

- [x] **Step 2: Run them and see them fail.** Expected: FAIL (class missing).
- [x] **Step 3: The helper**

```php
/**
 * Whether the application opened a database transaction on a connection. The one `RefreshDatabase` wraps
 * around each test does not count: Laravel's transaction manager leaves it out of the transactions after-commit
 * callbacks apply to, and so does this (D52).
 */
final class ApplicationTransactions
{
    public static function isOpenOn(Connection $connection): bool
    {
        $name = $connection->getName();

        return app('db.transactions')->callbackApplicableTransactions()
            ->contains(static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $name);
    }
}
```

Check that `DatabaseTransactionRecord::$connection` is public in the installed Laravel version and that `db.transactions` is bound outside tests (the database service provider binds it); adapt to what the code shows.
- [x] **Step 4: Run** the new test file. Expected: PASS.
- [x] **Step 5: Commit** `feat(core): tell an application transaction from the test harness's (D52)`.

### Task 2: Lock the root without losing what the caller loaded (D34)

**Files:**
- Create: `Modules/Core/tests/Stubs/Versioning/OptimisticVersionSetArticle.php` (a `VersionSetManagerArticle` with `HasOptimisticLocking`, implementing `IOptimisticLockableModel`)
- Modify: `Modules/Core/app/Versioning/VersionSetManager.php` (`lockExistingRoot()`)
- Test: `Modules/Core/tests/Integration/Versioning/VersionSetManagerTest.php`

- [x] **Step 1: Write the failing tests.** A stale instance of an optimistically locked root (another write raised `lock_version` after it was loaded) is refused inside `run()` with `StaleModelLockingException` before the operation runs, and nothing is written; an instance with the current `lock_version` keeps it and runs; relations loaded before the scope are still loaded inside it. The existing test `invalidates relations loaded before locking and reloads their current state` encodes the behaviour D34 removes: rewrite it as `keeps the relations loaded before locking`. The test `synchronizes the root from its locked snapshot while preserving intentional dirty values` stays: a root without optimistic locking is still realigned to the locked row.
- [x] **Step 2: Run them and see them fail.**
- [x] **Step 3: `lockExistingRoot()`.** After reading the locked row: when the model is an `IOptimisticLockableModel`, compare `currentLockVersion()` with the locked row's `lockVersionColumn()` and throw `StaleModelLockingException` when they differ; then realign the attributes as today (the lock column is equal by then) and drop the `unsetRelations()` call.
- [x] **Step 4: Run** `php artisan test --compact Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Locking`. Expected: PASS.
- [x] **Step 5: Commit** `feat(core): lock a root without dropping its relations or its lock version (D34)`.

**Found at execution (2026-10-22):** the D34 tests sit in a new `RootLockTest`; the comparison runs only when the instance carries a lock version, as the guarded update itself requires one.

### Task 3: One set per root in one scope (D43)

**Files:**
- Create: `Modules/Core/app/Versioning/VersionScope.php` (the transaction-level state: connection, enlisted connection, depth, the sets per root, the stack of roots named by `run()`)
- Modify: `Modules/Core/app/Versioning/ActiveVersionSet.php` (one root's set only: root, options, set row, stored kind, sequences, `finish()`; the scope state moves out)
- Modify: `Modules/Core/app/Versioning/VersionSetManager.php` (`run()`, `runNested()`, `setFor()`, `current()`, `enlist()`)
- Modify: `Modules/Core/app/Versioning/Contracts/VersionSetManagerInterface.php` (`setFor(Model $root): ActiveVersionSet`)
- Modify: `Modules/Core/app/Versioning/VersionWriter.php` (writes into `setFor($change->model)`)
- Modify: `Modules/Core/app/Providers/CoreServiceProvider.php` if the bindings change (the manager stays scoped)
- Test: `Modules/Core/tests/Integration/Versioning/VersionSetPerRootTest.php` (create), `VersionSetManagerTest.php`

- [x] **Step 1: Write the failing tests** (with `VersionedDocument` and a second versioned stub table, or two `VersionedDocument` rows):
  - a `run()` for document A that calls `run()` for document B gives two sets, each with its own root, rows and sequences starting at 1, in one transaction: an exception thrown inside B's operation rolls back both sets and both writes;
  - a write of B inside A's scope without a `run()` for B (B updated inside A's operation) lands in B's set, not in A's;
  - B's set is opened with the actor of the outer operation and the ordinary kind; a revert of A that writes B gives A a `revert` set and B a `change` set;
  - a nested `run()` for B with explicit options opens B's set with them (a revert of B inside A's scope: B's set is `revert` with B's target, validated against B);
  - a second `run()` for B in the same scope joins B's set; a `run()` for the same root on another PHP instance still throws `DistinctVersionSetRootInstanceException`;
  - B's row is locked when its set opens: a stale B instance is realigned (or refused with Task 2's rule) at that moment;
  - `current()` returns B's set inside B's operation and A's set again after it;
  - a scope that writes nothing for B leaves no set for B.
  In `VersionSetManagerTest`, `rejects a nested different root before its operation runs` encodes the rule D43 replaces: rewrite it as the first case above.
- [x] **Step 2: Run them and see them fail.**
- [x] **Step 3: `VersionScope` and `ActiveVersionSet`.** The scope keeps `connection_name`, `enlisted_connection_name`, `depth`, `list<ActiveVersionSet> $sets` and the stack of sets named by `run()`. A set is found for a root by `VersionSetRoot::matches()` and, for an unsaved root, by instance. `ActiveVersionSet` keeps what belongs to one root: `root()`, `options()`, `connectionName()`, `versionSet()`, `storeAsBaseline()`, `nextSequence()`, `markVersionWritten()`, `finish()`; its public methods used by `HasVersionedRelations` and the tests (`versionSet()`, `nextSequence()`, `markVersionWritten()`, `connectionName()`) keep their signatures.
- [x] **Step 4: The manager.**
  - `run()` outside a scope: as today (open the scope, transaction, lock, validate the revert target, operation, finish), but the finish goes over every set of the scope, in the order they were opened.
  - `runNested()`: same root and same instance, join as today (options compared as today); another root, find or open its set (lock its row, validate its revert target if it is a revert), push it on the stack for the operation and pop it after; the options of an opened set are the ones given, or `new VersionSetOptions(actor: <outer actor>)` when none.
  - `setFor(Model $root)`: the set of that root, opened (and locked) if the scope has none; throws a `LogicException` outside a scope.
  - `current()`: the top of the stack, or null outside a scope.
  - `enlist()`: unchanged rule, kept on the scope.
- [x] **Step 5: The writer** writes into `$this->manager->setFor($change->model)` instead of `current()`, and the baseline check of D53 compares the change's model with that set's root.
- [x] **Step 6: Run** `php artisan test --compact Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Helpers Modules/Core/tests/Integration/Models`. Expected: PASS.
- [x] **Step 7: Commit** `feat(core): one version set per root in one transaction (D43)`.

**Found at execution (2026-10-22):** a set opened by the writer through `setFor()` does not lock its root: the write that brings the change already ran in the scope's transaction and holds the row, and realigning the instance from inside its own `updated` or `deleted` event would undo what the event is doing (after a delete the row is gone and the lock would fail). Only a set opened by a `run()` locks, before its operation. `ActiveVersionSet` is no longer a container binding: the manager creates one per root, and the scope state lives in the new `VersionScope`. `VersionSetRootMismatchException` lost its only use and was removed.

### Task 4: Each set records its capture (D47, D52)

**Files:**
- Modify: `Modules/Core/app/Versioning/ActiveVersionSet.php` (the capture it stores with its row)
- Modify: `Modules/Core/app/Versioning/VersionSetManager.php` (`run()` opens with `atomic`; a new entry point for the writer's fallback)
- Modify: `Modules/Core/app/Versioning/Contracts/VersionSetManagerInterface.php`
- Modify: `Modules/Core/app/Versioning/VersionWriter.php` (the fallback uses the new entry point)
- Test: `Modules/Core/tests/Integration/Versioning/VersionCaptureTest.php` (create)

- [x] **Step 1: Write the failing tests**
  - a save of a versioned model with no scope and no application transaction gives a `best_effort` set, and its scalar revert (`revertToVersion()`) still works;
  - the same save inside `DB::connection()->transaction(...)` gives an `atomic` set;
  - a write inside `run()` gives an `atomic` set; so does a set opened for another root inside the scope (Task 3);
  - a revert set is `atomic`;
  - no set is written as `incomplete` (D52: the value stays in the enum, unused in milestone 1).
- [x] **Step 2: Run them and see them fail.** Expected: FAIL (every set is `best_effort`, the column default).
- [x] **Step 3: Store the capture.** `ActiveVersionSet` gets a `VersionCaptureQuality $capture`, given when the set is opened and written into `capture` by `versionSet()`.
- [x] **Step 4: Decide it in the manager.** `run()` and the sets opened inside a scope are `atomic`: the transaction is open before the operation's SQL. The writer's fallback, which runs after the business statement already happened, goes through a dedicated entry point:

```php
    /**
     * Opens a scope for a change whose business statement already ran, as the writer does when no scope was
     * active. Its set is atomic only when the application had a transaction open on the root's connection, so
     * the business statement and the history commit together; otherwise it is best effort (D47, D52).
     */
    public function runAfterTheFact(VersionSetRoot $root, Closure $operation, VersionSetOptions $options): mixed;
```

It reads `ApplicationTransactions::isOpenOn($root->model()->getConnection())` before opening its own transaction, then behaves like `run()`.
- [x] **Step 5: Run** the new tests, `Modules/Core/tests/Integration/Versioning`, `Helpers`, `Models`, and the SAO timeline test. Expected: PASS.
- [x] **Step 6: Commit** `feat(core): record the capture quality of each version set (D47, D52)`.

**Found at execution (2026-10-22):** the capture belongs to the scope, so the sets the writer opens for other roots inside a fallback scope inherit it: they were written after the fact too. The hand-written schema of the affinity connection in `VersionSetManagerTest` gained the `capture` column.

### Task 5: Each root's set decides its stored kind (D53, D60)

**Files:**
- Modify: `Modules/Core/app/Versioning/VersionWriter.php`, `ActiveVersionSet.php` (only if Task 3 did not already cover it)
- Test: `Modules/Core/tests/Integration/Versioning/CreationBaselineTest.php`

- [x] **Step 1: Write the failing tests:** a root B created inside A's scope gets its own set, stored as `baseline`, while A's set stays `change`; a nested `run()` for B with ordinary options inside B's baseline set joins it; a revert of A that creates nothing keeps A's set `revert`.
- [x] **Step 2: Run them.** If Task 3 already made them pass, record it here and mark Step 3 `- [-]`. They passed at once (2026-10-22): the set the writer opens for B through `setFor()` has B as its root, so the creation promotes B's own set.
- [-] **Step 3: Fix** what fails (nothing failed, see Step 2): the promotion of D53 is decided per root's set, before its row is written.
- [x] **Step 4: Run** `php artisan test --compact Modules/Core/tests/Integration/Versioning`. Expected: PASS.
- [x] **Step 5: Commit** `test(core): the creation set of each root is its own baseline (D53, D60)` (or `feat` if code changed).

### Task 6: Documentation and closing of the slice

**Files:**
- Modify: `Modules/Core/docs/rag/MODULE.md` (versioning section)
- Modify: the review log status line and the spec's implementation lists
- Modify: this plan and the parent plan (tick Slice 3); `docs/superpowers/plans/INDEX.md`

- [x] **Step 1: `MODULE.md`:** one set per root in one transaction, each root's row locked when its set opens, `current()` and `setFor()`, the capture recorded per set (`atomic` inside a scope or an application transaction, `best_effort` otherwise, the test harness transaction not counted), the lock that keeps loaded relations and refuses a stale optimistic lock.
- [x] **Step 2: Status lines:** review log and spec list D34 (the lock part), D43, D47 (stored capture), D52 (manager part) and D60 as implemented.
- [x] **Step 3: Run** the regression per suite (Core per folder and Integration per subfolder, CMS, SAO, ERP, MES, AI, Shop, `tests/`) and record the result here.
- [x] **Step 4: Commit** the docs in Core and in `laraplate/`; add the delivery status below and tick Slice 3 in the parent plan.

## Delivery status (2026-10-22): delivered

All six tasks are done, tests first. Commits in `Modules/Core`: `7067cb7a` (`ApplicationTransactions`, D52), `57317a21` (root lock, D34), `64a9b9f5` (one set per root, D43), `c53cde7e` (capture per set, D47, D52), `b600c596` (per-root baseline, D53, D60); in `Modules/ERP`: the two hand-written secondary-connection schemas gained the `capture` column.

Divergences from the plan, each recorded under its task: a set opened by the writer through `setFor()` does not lock its root, since the business write already holds the row and realigning the instance inside its own model events would undo them; the capture belongs to the scope, so the sets the writer opens inside a fallback scope inherit it; `ActiveVersionSet` is no longer a container binding and `VersionSetRootMismatchException` was removed; Task 5 needed no code.

Regression on 2026-10-22, per suite: Core Feature, Unit, every Integration subfolder and the Integration root files, CMS, SAO, ERP (after the fixture fix), MES, AI and Shop green. Failing, not caused by this slice: `tests/Feature/Filament/ResourceFormPagesCloseOrCancelTest` (the CRM pages in progress).

**Documented in:** `Modules/Core/docs/rag/MODULE.md`.
