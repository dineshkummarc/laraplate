---
status: open
created_on: 2026-10-14
---
# Stored property filters in the CRUD API. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Tick each step when it is done, in the same work block. A step dropped on purpose is `- [-]` with its reason on the same line.

**Status:** Open. Tasks 1 to 3 delivered and committed; Task 4 documentation written, plan closing pending.

**Goal:** a flat filter property resolves to a column, a translated field of the request locale, or a dynamic field in either JSON container, with no client knowledge of the storage.

**Architecture:** `ModelPropertyResolver` classifies a property from the model traits and the memoized schema; `QueryBuilder::applyFilter` delegates non-column properties to `applyStoredPropertyFilter`, which re-enters `applyFilter` inside `whereHas('translations')` or inside an OR group of JSON path conditions.

**Tech Stack:** PHP 8.5, Laravel 12, Pest 4. No new dependency.

**Spec:** `docs/superpowers/specs/2026-10-14-stored-property-filters-design.md`, decisions D1 to D7.

## Global Constraints

- Every PHP file `declare(strict_types=1)`; braces always; explicit types; `final`; PHPDoc over inline comments; English code and docs.
- Tests live in the module that owns the entity (`Modules/CMS/tests`); never declare classes in a test file. `php artisan config:clear` before tests.
- Pint from the laraplate root with explicit files.
- `QueryBuilder.php` also carries the uncommitted hunks of `2026-10-14-scope-filters.md`: stage only your own hunks (`git add -p`), never the other plan's.
- Commits go to the repository that owns each file, explicit paths; never push.

## Task 1: Resolver and filter application (D1 to D5)

**Files:** create `Modules/Core/app/Services/Crud/ModelPropertyResolver.php`; modify `Modules/Core/app/Services/Crud/QueryBuilder.php`; test `Modules/CMS/tests/Feature/Controllers/ContentsStoredPropertyFilterTest.php`.

- [x] **Step 1: Failing tests.** A translated field by its plain name in the request locale; shared and translated dynamic fields in either container; a real column stays a column.
- [x] **Step 2: `ModelPropertyResolver`** (column, translatable, dynamic; main and translation containers from the schema).
- [x] **Step 3: `applyStoredPropertyFilter` and `whereTranslation`** in `QueryBuilder`.
- [ ] **Step 4: Run the touched tests and the CRUD suites** (`Modules/Core/tests/Integration/Services`, `Modules/CMS/tests/Feature/Controllers`), Pint on explicit files, commit with `git add -p`.

## Task 2: Locale and edge cases (D2, D5)

**Files:** the same test file; `QueryBuilder.php` (`whereTranslation`).

- [x] **Step 1: Failing tests.** `in`, `not like` and `eq` on a translated field; a dynamic filter inside an OR group beside a translated one; a relation path leaf (`categories.name`) resolved on the related model; only plain names are classified as stored properties (`translations.title`, `components->x`, columns are not).
- [x] **Step 2: Fix what fails.** The fallback tests written first failed for a reason that changed the design: `App::getLocale()` reads `config('app.locale')`, so request locale and default locale are the same value and no fallback can run. `whereTranslation` lost its fallback branch; D2 rewritten.
- [-] **Step 3: Fallback to the default locale** dropped: unreachable (see Step 2), recorded in D2.
- [x] **Step 4: Run the touched tests and the CRUD suites, Pint, commit.**

## Task 3: A second model shape (D1, D4)

**Files:** `Modules/Core/tests/Stubs/Crud/DynamicOnlyStubModel.php`, `Modules/Core/tests/Integration/Services/Crud/QueryBuilderStoredPropertyTest.php`.

- [x] **Step 1: Pick the model.** No production model uses `HasDynamicContents` without translations (`Content`, `Contributor`, `Taxonomy` all use `HasTranslatedDynamicContents`); a stub with a `components` table covers the shape.
- [x] **Step 2: Tests.** Classification, equals, `in` and `like` on the `components` column, a field no record has, columns still filtered, the condition kept inside an OR group. They passed on the first run, no fix needed.
- [x] **Step 3: Run the touched tests, Pint, commit.**

## Task 4: Documentation

**Files:** `Modules/Core/docs/CRUD_SYSTEM.md`, `Modules/Core/docs/rag/GLOSSARY.md`.

- [x] **Step 1: Document** the resolution order, the locale rule, the both-containers rule, the limits and the pointer to the search endpoint for full text (`CRUD_SYSTEM.md`, section "Translated and Dynamic Fields"; glossary row).
- [ ] **Step 2: Close the plan:** `## Delivery status` section and a `**Documented in:**` line, `status: closed`, update both INDEX files. Done when the UI plan's Task 5 has confirmed the filters against the real apps.

## Out of scope

- 422 for undeclared dynamic names, sorting, facets and numeric comparisons on dynamic fields (D5, D6).
- Making ERP, MES or Shop entities `Searchable`: an owner decision per module, tracked in the module's own plans.
