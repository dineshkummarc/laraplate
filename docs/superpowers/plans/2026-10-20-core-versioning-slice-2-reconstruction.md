---
status: completed
---
# Core Versioning Rebuild, Slice 2 — Reconstruction and Complete Anchors

> **For agentic workers:** execute task by task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Goal:** a revert rebuilds the state the record really had at the target revision (C08), from a creation row that is a complete image (C09), in a creation set marked as a complete anchor (D53); the columns and models outside the document stay out of the history (D8, D10, D21, D61).

**Parent plan:** `docs/superpowers/plans/2026-10-20-core-versioning-rebuild.md`, Slice 2. **Spec:** `docs/superpowers/specs/2026-07-29-revision-centric-aggregate-history-design.md`. **Decisions:** C08, C09, D8, D10, D21, D53, D60, D61 in `docs/superpowers/specs/2026-08-04-revision-centric-aggregate-history-review.md`.

**Architecture:** the reconstruction is one method of Core's `Version`, `stateAsOf()`, used by `revertWithoutSaving()`, so both revert entry points (`revertToVersion()` and the scalar part of `restoreToRevision()`) share it. The creation set is promoted to `baseline` by `ActiveVersionSet` when the root's `Created` row is its first row. A marker contract excludes comments and ratings from versioning, and the translation models are excluded through `ITranslated`.

**Tech Stack:** PHP 8.5, Laravel 12, Pest. Modules: `Core`, `CMS` (markers on `Comment` and `ContentRating`).

## Global Constraints

- As in the parent plan: `declare(strict_types=1);`, explicit types, PHPDoc over inline comments, `#[Override]` only on real overrides, tests first, Pint from the root on an explicit file list (`.ai/rules/modules.md`), commits inside each module repository, `/usr/bin/git`.
- No data migration (D12).
- Out of scope, left to their slices: the epoch (Slice 5), the per-root sets and the capture rule (Slice 3), the translation rows on the owner (Slice 4), the aggregate gate of D45 rule 2 and D47 (Slice 6). An encrypted versionable attribute is replayed as stored: the row does not record which attributes were encrypted, and no model uses `$encryptedVersionable` today; noted, not changed here.

---

### Task 1: The soft-delete row is a `DIFF`

A soft delete records one column, but `writePendingVersionDelete()` stores it with the model's strategy, so a `SNAPSHOT` model writes a `trashed` row labelled `SNAPSHOT` with only `deleted_at` in it. The reconstruction of Task 2 starts from the nearest `SNAPSHOT` row and would take that row for a complete image.

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php` (`capturePendingVersionDelete()`, `writePendingVersionDelete()`)
- Test: `Modules/Core/tests/Integration/Versioning/DeleteVersioningStrategyTest.php`

- [x] **Step 1: Write the failing test**

```php
it('records a soft delete of a SNAPSHOT model as a DIFF of deleted_at', function (): void {
    $article = SoftDeletedVersionedArticle::query()->create(['title' => 'First']);
    $article->useVersionStrategy(VersionStrategy::SNAPSHOT);

    $article->delete();

    $trashing = Version::query()->latest('id')->firstOrFail();

    expect($trashing->change_type)->toBe(VersionChangeType::Trashed)
        ->and($trashing->version_strategy)->toBe(VersionStrategy::DIFF)
        ->and(array_keys($trashing->contents))->toBe(['deleted_at']);
});
```

Check first that `SoftDeletedVersionedArticle` exposes `useVersionStrategy()` (it extends or mirrors `VersionedArticle`); add it to the stub if it does not.

- [x] **Step 2: Run it and see it fail.** Run: `php artisan test --compact Modules/Core/tests/Integration/Versioning/DeleteVersioningStrategyTest.php`. Expected: FAIL (`SNAPSHOT`).
- [x] **Step 3: Store the soft-delete row as `DIFF`.** In `capturePendingVersionDelete()` the soft branch keeps no strategy; in `writePendingVersionDelete()` the `Trashed` change is written with `strategy: VersionStrategy::DIFF`. Narrow the `@var` shape of `$pending_version_delete` accordingly (the soft branch loses `strategy`).
- [x] **Step 4: Run** `php artisan test --compact Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Helpers`. Expected: PASS.
- [x] **Step 5: Commit** `fix(core): a soft delete is a DIFF of deleted_at whatever the strategy`.

### Task 2: Reconstruct the state as of a revision (C08, D61)

**Files:**
- Create: `Modules/Core/app/Versioning/Exceptions/MissingVersionAnchorException.php`
- Modify: `Modules/Core/app/Models/Version.php` (`stateAsOf()`, `revertWithoutSaving()`)
- Test: `Modules/Core/tests/Integration/Versioning/VersionReconstructionTest.php` (create)

- [x] **Step 1: Write the failing tests** (schema as in `HasVersionsTest`: `VersionedArticle::TABLE` with `title`, a nullable `subtitle`, timestamps and `softDeletes()`; the stub's `$versionable` must list `subtitle` too, or the test uses a stub without a `$versionable` list):

```php
it('rebuilds the state at a revision from the nearest snapshot, not from the live row', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'T1', 'subtitle' => 'S1']);
    $article->update(['title' => 'T2']);
    $article->update(['subtitle' => 'S2']);
    $third = $article->versions()->reorder('id')->get()->last();
    $article->update(['title' => 'T3']);

    expect($third->stateAsOf())->toMatchArray(['title' => 'T2', 'subtitle' => 'S2']);
});

it('follows the strategy stored on each row, not the model current one', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'T1', 'subtitle' => 'S1']);
    $article->update(['title' => 'T2']);
    $second = $article->versions()->reorder('id')->get()->last();
    $article->useVersionStrategy(VersionStrategy::SNAPSHOT);

    expect($second->stateAsOf())->toMatchArray(['title' => 'T2', 'subtitle' => 'S1']);
});

it('refuses to rebuild a revision with no snapshot at or before it', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'T1']);
    $article->versions()->where('change_type', VersionChangeType::Created)->delete();
    $article->update(['title' => 'T2']);

    expect(fn () => $article->versions()->reorder('id')->get()->last()->stateAsOf())
        ->toThrow(MissingVersionAnchorException::class);
});

it('reverts to the rebuilt state and leaves the bin column as it is', function (): void {
    $article = SoftDeletedVersionedArticle::query()->create(['title' => 'T1', 'subtitle' => 'S1']);
    $article->update(['title' => 'T2']);
    $target = $article->versions()->reorder('id')->get()->last();
    $article->delete();
    $article->restore();
    $article->update(['subtitle' => 'S2']);

    $article->revertToVersion($target->getKey());

    expect($article->fresh()->only(['title', 'subtitle', 'deleted_at']))->toBe(['title' => 'T2', 'subtitle' => 'S1', 'deleted_at' => null]);
});
```

The first test is the evidence of C08 (the old loop gives `T3 / S2`). The last pins D61: the target state had `deleted_at` null and so does the live row here, so add a second case where the record is in the bin when reverted and stays there.

- [x] **Step 2: Run them and see them fail.** Expected: FAIL (`stateAsOf()` undefined).
- [x] **Step 3: The exception**

```php
final class MissingVersionAnchorException extends RuntimeException
{
    public static function forVersion(int|string $versionId): self
    {
        return new self("No complete snapshot of the record exists at or before version {$versionId}: its state cannot be rebuilt.");
    }
}
```

- [x] **Step 4: `stateAsOf()`** on `Version`. The root stream is the rows of the same versionable with no `relation_path`; revision order is `(version_set_id, sequence)`. The anchor is the latest of them at or before this row whose stored strategy is `SNAPSHOT` and whose `change_type` is not `Deleted` (a tombstone holds its image in `original_contents`, it is not a state of a live record). Query without the eager `versionable` (`newQueryWithoutRelationships()`): two queries, the anchor and then the rows after it up to this one, applied forward, last write wins.

```php
    /**
     * The versionable columns of the record as of this row: the nearest snapshot of the record at or before it,
     * then every later row up to this one applied forward in revision order, last write wins (C08). Each row's
     * stored strategy decides, never the model's current one.
     *
     * @return array<string, mixed>
     *
     * @throws MissingVersionAnchorException
     */
    public function stateAsOf(): array
    {
        $at_or_before = fn (Builder $query): Builder => $query
            ->where('versionable_type', $this->versionable_type)
            ->where('versionable_id', $this->versionable_id)
            ->whereNull('relation_path')
            ->where(fn (Builder $q): Builder => $q
                ->where('version_set_id', '<', $this->version_set_id)
                ->orWhere(fn (Builder $same): Builder => $same
                    ->where('version_set_id', $this->version_set_id)
                    ->where('sequence', '<=', $this->sequence)));

        $anchor = $at_or_before($this->newQueryWithoutRelationships())
            ->where('version_strategy', VersionStrategy::SNAPSHOT)
            ->where('change_type', '!=', VersionChangeType::Deleted)
            ->orderByDesc('version_set_id')
            ->orderByDesc('sequence')
            ->first() ?? throw MissingVersionAnchorException::forVersion($this->getKey());

        $state = $anchor->contents;

        $later = $at_or_before($this->newQueryWithoutRelationships())
            ->where(fn (Builder $q): Builder => $q
                ->where('version_set_id', '>', $anchor->version_set_id)
                ->orWhere(fn (Builder $same): Builder => $same
                    ->where('version_set_id', $anchor->version_set_id)
                    ->where('sequence', '>', $anchor->sequence)))
            ->orderBy('version_set_id')
            ->orderBy('sequence')
            ->get();

        foreach ($later as $row) {
            $state = $row->getStoredVersionStrategy() === VersionStrategy::SNAPSHOT
                ? $row->contents
                : array_merge($state, $row->contents);
        }

        return $state;
    }
```

A later `SNAPSHOT` cannot appear in `$later` (the anchor is the latest one); the branch stays for clarity and costs nothing. Adapt the closures to the types PHPStan wants (`Builder<self>`).

- [x] **Step 5: `revertWithoutSaving()`** replaces the two strategy branches:

```php
    public function revertWithoutSaving(): ?Model
    {
        $versionable = $this->getCompleteVersionable();

        if (! $versionable instanceof Model) {
            return null;
        }

        $state = $this->stateAsOf();

        // A revert never moves the record into or out of the bin: that is the bin's own operation (D20, D61).
        if (method_exists($versionable, 'getDeletedAtColumn')) {
            unset($state[$versionable->getDeletedAtColumn()]);
        }

        $versionable->setRawAttributes(array_merge($versionable->getRawOriginal(), $state));

        return $versionable;
    }
```

`resolveReplayVersionStrategy()` is no longer called by the revert; keep it if other callers or tests use it (`grep -rn resolveReplayVersionStrategy Modules`), otherwise remove it together with its tests only with the user's approval (AGENTS.md: tests are not deleted without approval).

- [x] **Step 6: Update the old expectations.** The three `revertWithoutSaving` tests of `VersionModelTest` build rows in separate sets after the user's own `Created` row; check that they still pass under the new rule and fix only the ones whose expectation encoded the old merge over the live row.
- [x] **Step 7: Run** `php artisan test --compact Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Models Modules/Core/tests/Integration/Helpers`, then the aggregate restore tests (`grep -rln restoreToRevision Modules/Core/tests`). Expected: PASS.
- [x] **Step 8: Commit** `feat(core): rebuild the state at a revision from its nearest snapshot (C08, D61)`.

**Found at execution (2026-10-22):** the tests use a new stub, `VersionedDocument` (a bin and no `$versionable` list), since `VersionedArticle` versions only `title`. A revert of a record in the bin did nothing at all: the `versionable` relation does not find a trashed record, so `revertWithoutSaving()` returned null; both entry points now hand the version the record they are called on. `Version` declares its columns as `@property`, as `Entity` does, so the column names of the new queries type-check without an ignore. The DIFF test of `VersionModelTest` gains the creation snapshot every real history has. `resolveReplayVersionStrategy()` has no caller left in the application and stays, with its tests, until the user decides.

### Task 3: The creation row is read back (C09)

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php` (`createInitialVersion()`)
- Test: `Modules/Core/tests/Integration/Versioning/VersionWriterCharacterizationTest.php`

- [x] **Step 1: Write the failing test.** A table whose column takes its value from a database default, never assigned at insert:

```php
it('writes the creation row as a complete image, defaults included', function (): void {
    Schema::table(VersionedArticle::TABLE, function (Blueprint $table): void {
        $table->string('status')->default('draft');
    });

    $article = VersionedArticle::query()->create(['title' => 'First']);

    expect($article->versions()->where('change_type', VersionChangeType::Created)->firstOrFail()->contents)
        ->toMatchArray(['title' => 'First', 'status' => 'draft']);
});
```

The stub's `$versionable` list (`['title']`) would hide `status`: use a stub without the list or extend the list in this test's stub, following the file's existing schema helper.

- [x] **Step 2: Run it and see it fail.** Expected: FAIL (`status` missing).
- [x] **Step 3: Read the row back.** In `createInitialVersion()` replace `$model->getAttributes()` with the raw attributes of `$model->getRefreshedModel($model)` (`getRawOriginal()`), still filtered by `filterVersionableImage()`. One `SELECT` per creation, inside the transaction of the insert when there is one.
- [x] **Step 4: Run** `php artisan test --compact Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Models Modules/Core/tests/Integration/Helpers` and the seeding tests (`Modules/Core/tests/Feature/Seeding`). Expected: PASS; a test that asserted the old partial image is updated to the complete one.
- [x] **Step 5: Commit** `fix(core): the creation version is read back after the insert (C09)`.

**Found at execution (2026-10-22):** the tests sit in `VersionReconstructionTest` with the `VersionedDocument` stub (a `status` column with a database default), not in `VersionWriterCharacterizationTest`, whose stub versions only `title`; a second test reverts to the creation and gets the default back.

### Task 4: The creation set is a baseline (D53, D60)

**Files:**
- Modify: `Modules/Core/app/Versioning/ActiveVersionSet.php` (`storeAsBaseline()`, `versionSet()`, `reset()`)
- Modify: `Modules/Core/app/Versioning/VersionWriter.php`
- Test: `Modules/Core/tests/Integration/Versioning/VersionSetManagerTest.php`

- [x] **Step 1: Write the failing tests**

```php
it('stores the creation set of a root as a baseline and later sets as changes', function (): void {
    $article = VersionSetManagerArticle::query()->create(['title' => 'Before']);
    $article->update(['title' => 'After']);

    $kinds = VersionSet::query()->orderBy('id')->pluck('kind')->all();

    expect($kinds)->toBe([VersionSetKind::Baseline, VersionSetKind::Change]);
});

it('lets a nested run with ordinary options join a set stored as a baseline', function (): void {
    $article = new VersionSetManagerArticle(['title' => 'Before']);
    $manager = app(VersionSetManagerInterface::class);

    $manager->run(VersionSetRoot::forModel($article), function () use ($article, $manager): void {
        $article->save();
        $manager->run(VersionSetRoot::forModel($article), fn () => $article->update(['title' => 'After']), new VersionSetOptions);
    });

    expect(VersionSet::query()->pluck('kind')->all())->toBe([VersionSetKind::Baseline])
        ->and(Version::query()->count())->toBe(2);
});

it('never stores a revert set as a baseline', function (): void {
    // a revert of a created record: its set stays Revert
});
```

Write the third with the existing revert fixtures of the file. Check that `VersionSetManagerArticle` is versioned (it writes `Created` rows); if the second test cannot open `run()` for an unsaved model, use the pattern the file already uses for pre-persist roots.

- [x] **Step 2: Run them and see them fail.** Expected: FAIL (`change` stored).
- [x] **Step 3: `ActiveVersionSet`.** A private `?VersionSetKind $stored_kind`, cleared by `reset()`; `versionSet()` stores `$this->stored_kind ?? $options->kind`; and:

```php
    /**
     * Stores the set as a baseline: its first row is the creation of its root, a complete anchor (D53). The
     * scope's options keep their kind, so a nested run() with ordinary options still joins it (D60). Too late
     * once the set row exists, and never for a revert.
     */
    public function storeAsBaseline(): void
    {
        if (! $this->version_set instanceof VersionSet && $this->options()->kind === VersionSetKind::Change) {
            $this->stored_kind = VersionSetKind::Baseline;
        }
    }
```

- [x] **Step 4: `VersionWriter::write()`**, in the scoped branch before `nextSequence()`:

```php
        if ($change->type === VersionChangeType::Created && $change->relationPath === null && $active->root()->model() === $change->model) {
            $active->storeAsBaseline();
        }
```

The fallback branch reaches the same code through its own `run()`, so the creation of a root saved outside any scope is covered too.
- [x] **Step 5: Run** `php artisan test --compact Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Models Modules/SAO/tests/Feature/Services/TicketTimelineServiceTest.php`. Expected: PASS.
- [x] **Step 6: Commit** `feat(core): store the creation set of a root as a baseline (D53, D60)`.

**Found at execution (2026-10-22):** the tests sit in a new `CreationBaselineTest` with the `VersionedDocument` stub, since `VersionSetManagerArticle` is not versioned. The nested `run()` on a model saved inside the scope already worked; only the stored kind was missing.

### Task 5: Lock columns stay out of the history (D21)

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php` (`getDontVersionable()`)
- Test: `Modules/Core/tests/Integration/Helpers/HasVersionsTest.php` (with the `VersionedLockableArticle` stub)

- [x] **Step 1: Write the failing test:** a lockable versioned record created, locked by a user and then updated under `SNAPSHOT`: no version row contains `locked_at`, `locked_user_id` or `locked_until`; a revert to the creation leaves the live lock as it is. Create the lock columns in the test schema with `MigrateUtils::timestamps($table, hasLocks: true)` or the columns the stub expects.
- [x] **Step 2: Run it and see it fail.** Expected: FAIL (the read-back creation row of Task 3 now carries the lock columns).
- [x] **Step 3: Exclude them.** In `getDontVersionable()`, next to the optimistic lock column:

```php
        if ($this instanceof ILockableModel) {
            array_push($columns, $this->getLockedAtColumn(), $this->getLockedByColumn(), $this->getLockedUntilColumn());
        }
```

- [x] **Step 4: Run** `php artisan test --compact Modules/Core/tests/Integration/Helpers Modules/Core/tests/Integration/Versioning Modules/Core/tests/Feature/Locking` (or the folder holding the lock tests). Expected: PASS.
- [x] **Step 5: Commit** `fix(core): keep the lock columns out of the version history (D21)`.

**Found at execution (2026-10-22):** `VersionedLockableArticle` versions only `title`, so the tests use a new stub, `VersionedLockableDocument` (no `$versionable` list, `SNAPSHOT`), in `LockColumnsOutOfHistoryTest`. Before the fix a revert by the holder dropped the live lock.

### Task 6: Comments, ratings and translations are never versioned (D8, D10)

**Files:**
- Create: `Modules/Core/app/Contracts/INeverVersionedModel.php`
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php` (`isNeverVersioned()`, `getVersionStrategy()`)
- Modify: `Modules/Core/app/Seeding/ModelCapabilityScanner.php` (`hasVersions`)
- Modify: `Modules/Core/app/Services/ForcedVersionStrategySettings.php` (the panel hides and rejects a stale row of such a table)
- Modify: `Modules/CMS/app/Models/Comment.php`, `Modules/CMS/app/Models/ContentRating.php` (implement the marker)
- Test: `Modules/Core/tests/Integration/Helpers/HasVersionsTest.php`, `Modules/CMS/tests/Feature/Models/NeverVersionedModelsTest.php` (create)

- [x] **Step 1: Write the failing tests.** In CMS: `Comment`, `ContentRating` and every translation model (`ContentTranslation`, `CommentTranslation`, `ContributorTranslation`, `TagTranslation`, and Core's `TaxonomyTranslation`) return `false` from `getVersionStrategy()` even when a `versioning.strategy.{table}` setting says `DIFF`; creating a comment writes no version row; the Core seeder produces no `versioning.strategy.cms_comments`, `versioning.strategy.cms_contents_ratings` nor `versioning.strategy.*_translations` row (assert on the rows `CoreDatabaseSeeder` builds, as the existing seeding tests do); `ForcedVersionStrategySettings::isForced()` is true for those names.
- [x] **Step 2: Run them and see them fail.**
- [x] **Step 3: The marker**

```php
/**
 * A model whose rows are never versioned, whatever the per-table setting says: a contribution of somebody else
 * to a record, such as a comment or a rating, which is not part of the document (D8). The translation models are
 * never versioned either, through ITranslated: their state belongs to the owner's history (D10, D28).
 */
interface INeverVersionedModel {}
```

- [x] **Step 4: `HasVersions`**

```php
    /**
     * Whether this model stays out of the history whatever its setting says (D8, D10).
     */
    public static function isNeverVersioned(): bool
    {
        return is_a(static::class, INeverVersionedModel::class, true) || is_a(static::class, ITranslated::class, true);
    }
```

and `getVersionStrategy()` returns `false` first when `static::isNeverVersioned()`.
- [x] **Step 5: Seeder and panel.** `ModelCapabilityScanner`: `hasVersions: isset($traits[HasVersions::class]) && ! $model_class::isNeverVersioned()`. `ForcedVersionStrategySettings::names()` also lists the setting names of the never-versioned models, so a row left from an older seed is hidden and refused like a forced one. `Comment` and `ContentRating` implement `INeverVersionedModel`.
- [x] **Step 6: Run** the new tests, `Modules/Core/tests/Feature/Seeding`, the `SettingResource` tests (`grep -rln ForcedVersionStrategySettings Modules/Core/tests`) and `Modules/CMS/tests`. Expected: PASS; seeding counts that included the removed rows are updated.
- [x] **Step 7: Commit** in Core (`feat(core): never version comments, ratings and translations (D8, D10)`) and CMS (`feat(cms): comments and ratings are never versioned (D8)`).

Note: until Slice 4 writes the translation rows on the owner (D28), a translation change leaves no history at all; the rows the translation models wrote of their own were never used by a revert.

**Found at execution (2026-10-22):** the rule lives in a small class, `Modules\Core\Versioning\NeverVersionedModels::includes()`, not in a static method of the trait: the scanner and the panel hold a `class-string<Model>`, on which PHPStan cannot see a trait method. The Core Integration folder now exhausts the 2 GB of one process and is run per subfolder.

### Task 7: Documentation and closing of the slice

**Files:**
- Modify: `Modules/Core/docs/rag/MODULE.md` (versioning section), `Modules/CMS/docs/rag/MODULE.md` if it describes the versioning of comments, ratings or translations
- Modify: the review log status line and the spec's implementation lists
- Modify: this plan and the parent plan (tick Slice 2)

- [x] **Step 1: `MODULE.md`:** the revert rebuilds the state from the nearest snapshot (`stateAsOf()`, refused without one), never moves the record in or out of the bin, the creation row is read back and its set is a `baseline`, the lock columns are outside the history, and comments, ratings and translation models are never versioned (`INeverVersionedModel`, `ITranslated`).
- [x] **Step 2: Status lines:** review log and spec list C08, C09, D8, D10, D21, D53 and D61 as implemented.
- [x] **Step 3: Run** the full regression per suite (Core per folder, CMS, SAO, ERP, MES, AI, Shop, `tests/`) and record the result here.
- [x] **Step 4: Commit** the docs in each module and in `laraplate/`; add the delivery status below and tick Slice 2 in the parent plan.

## Delivery status (2026-10-22): delivered

All seven tasks are done, each with its tests written first. Commits in `Modules/Core`: `229f6f9a` (soft delete as a `DIFF`), `1bd83830` (`stateAsOf()`, C08, D61), `d19ba806` (creation row read back, C09), `3fea7eb4` (creation set as `baseline`, D53, D60), `1f183744` (lock columns, D21), `a6fb1139` (never-versioned models, D8, D10); in `Modules/CMS`: `d65a27e`.

Divergences from the plan, each recorded under its task: the tests use two new stubs (`VersionedDocument`, `VersionedLockableDocument`) because the existing ones version only `title`; the never-versioned rule lives in `NeverVersionedModels::includes()` instead of a static method of the trait; `Version` declares its columns as `@property`; a revert of a record in the bin, which used to do nothing, now works and keeps the record in the bin. `resolveReplayVersionStrategy()` has no caller left and is kept, with its tests, until the user decides.

Regression on 2026-10-22, per suite: Core Feature, Unit and every Integration subfolder green (the Integration folder as a whole exhausts 2 GB in one process), CMS, ERP, MES and Shop green. Failing, not caused by this slice: 14 tests of `Modules/AI/tests/Feature/EmbeddingSwitch*` (the verifier calls `getEmbeddingsProvider()` since AI commit `57559ed` and the mocks do not expect it), `tests/Feature/Filament/ResourceFormPagesCloseOrCancelTest` (the CRM pages in progress), and one SAO test that failed once on a random `key_prefix` collision and passed on the rerun of the whole suite.

**Documented in:** `Modules/Core/docs/rag/MODULE.md`, `Modules/CMS/docs/rag/MODULE.md`.
