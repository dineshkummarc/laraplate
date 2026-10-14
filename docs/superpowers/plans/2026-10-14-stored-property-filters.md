---
status: open
created_on: 2026-10-14
---
# Stored property filters in the CRUD API. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Tick each step when it is done, in the same work block. A step dropped on purpose is `- [-]` with its reason on the same line.

**Status:** Open. Task 1 built in the working tree (feature test and implementation pass), not committed.

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

## Task 2: Locale fallback and edge cases (D2, D5)

**Files:** the same test file; `QueryBuilder.php` if a case fails.

- [ ] **Step 1: Failing tests.** With fallback enabled a record translated only in the default locale is matched there, and a record with both translations is matched on the request locale only; `in`, `not like`, null and `!=` on a translated and on a dynamic field; a dynamic filter inside an OR group and inside a relation filter (`categories.<dynamic>`); a name with a dot or an arrow is not treated as stored (unchanged behaviour).
- [ ] **Step 2: Fix what fails.**
- [ ] **Step 3: Run the touched tests, Pint, commit.**

## Task 3: A second model shape (D1, D4)

**Files:** a model with `HasDynamicContents` only (a `components` column and no translations); test in its module.

- [ ] **Step 1: Pick the model** (Core `Taxonomy` is translated; look for one with `HasDynamicContents` alone in `Modules/*/app/Models`; if none exists, cover `mainContainers()` with a unit test on a stub in `tests/Stubs`).
- [ ] **Step 2: Failing test and fix.**
- [ ] **Step 3: Run the touched tests, Pint, commit.**

## Task 4: Documentation

**Files:** `Modules/Core/docs/CRUD_SYSTEM.md`, `docs/rag/` page for the CRUD filters.

- [ ] **Step 1: Document** the resolution order, the locale rule, the both-containers rule, the limits (D5, D6) and the pointer to the search endpoint for full text (D7).
- [ ] **Step 2: Close the plan:** `## Delivery status` section and a `**Documented in:**` line, `status: closed`, update both INDEX files.

## Out of scope

- 422 for undeclared dynamic names, sorting, facets and numeric comparisons on dynamic fields (D5, D6).
- Making ERP, MES or Shop entities `Searchable`: an owner decision per module, tracked in the module's own plans.
