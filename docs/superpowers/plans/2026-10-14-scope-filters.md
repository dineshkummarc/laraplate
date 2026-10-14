---
status: open
created_on: 2026-10-14
---
# Scope filters in ACLs and CRUD queries. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Tick each step when it is done, in the same work block. A step dropped on purpose is `- [-]` with its reason on the same line.

**Status:** Open. No task started.

**Goal:** a filter tree (ACL or CRUD request) can name a model scope with parameters, `{"scope": "valid"}`, applied wherever the tree applies, refused when undeclared, impure or badly parameterised.

**Architecture:** a fourth node `ScopeFilter` beside `Filter`, `FiltersGroup` and `RelationFilter`. Scopes opt in with the `#[FilterScope]` attribute (with a `client` flag); `ScopeGuard` reads declarations by reflection, checks parameters and applies the scope inside its own nested `where`, refusing anything but `where` conditions. Every walker of the tree handles the node.

**Tech Stack:** PHP 8.5, Laravel 12, Filament 5, Pest 4. No new dependency.

**Spec:** `docs/superpowers/specs/2026-10-14-scope-filters-design.md`, decisions S1 to S12.

## Global Constraints

- Every PHP file `declare(strict_types=1)`; braces always; explicit types; `#[Override]`; `final` where siblings are final; PHPDoc over inline comments; English code and docs.
- Tests live in the module that owns the code; support classes in `tests/Stubs`, never declared in a test file. Never run tests with a cached config (`php artisan config:clear` first).
- Pint from the laraplate root with explicit files. No silencing of static analysis.
- Fail closed: every walker refuses an unknown node kind; nothing a loose reading drops may widen access.
- Commits go to the repository that owns each file, explicit paths, only your own hunks; never push.

## Task 1: Node, attribute, guard (S1, S2, S4, S5)

**Files:** create `Modules/Core/app/Casts/ScopeFilter.php`, `Modules/Core/app/Attributes/FilterScope.php`, `Modules/Core/app/Support/ScopeGuard.php`; stubs in `Modules/Core/tests/Stubs/`.

- [x] **Step 1: Failing tests.** `ScopeGuard` finds a `#[FilterScope]` scope declared on a model and on a trait the model uses; ignores an undeclared `#[Scope]`; reports the client flag; coerces parameters (scalars, ISO date to `CarbonInterface`, optional with default) and refuses wrong types, missing required, extra, arrays and objects; applies a pure scope inside a nested `where` (its `orWhere` stays inside the group in an OR context); refuses a declared scope that joins, orders, selects, groups, limits or unions.
- [x] **Step 2: DTO and attribute.**
- [x] **Step 3: Guard**, declarations memoized per class.
- [x] **Step 4: Run the touched tests, Pint, commit.**

## Task 2: Storage and validation (S1, S7)

**Files:** `Modules/Core/app/Casts/FiltersGroupCast.php`, `Modules/Core/app/Rules/QueryBuilder.php`, `Modules/Core/app/Rules/AclRelationFilters.php` (or a sibling rule `AclScopeFilters` registered in `Modules/Core/app/Models/ACL.php`).

- [x] **Step 1: Failing tests.** The cast round-trips a scope node with and without parameters, alone, in groups and inside relation filters; refuses extra keys, a non-list `parameters`, a missing or empty name; a stored group holding only a scope node constrains something. Saving an ACL with an undeclared scope, a bad parameter or an impure scope fails validation with a message naming the scope; a scope inside a relation filter is checked against the related model, and against each morph type.
- [x] **Step 2: Cast and shape rule.**
- [x] **Step 3: Target rule.**
- [x] **Step 4: Run the touched tests, Pint, commit.**

## Task 3: Application (S5, S6, S7)

**Files:** `Modules/Core/app/Services/Crud/QueryBuilder.php` (`recursivelyApplyFilters`, relation filters), `Modules/Core/app/Services/Authorization/AuthorizationService.php` (`applyFiltersRecursively`, `applyRelationFilter`, `resolvePlaceholders`).

- [x] **Step 1: Failing tests.** An ACL `{"scope": "valid"}` limits a CRUD list, a detail and an ACL-checked read to valid rows; in an OR group beside a column filter the scope's own conditions stay grouped; inside a relation filter and a morph relation filter it narrows the related records; a placeholder parameter (`@now`, `@user.<attribute>`) is resolved; an ACL whose scope no longer resolves denies the read and logs the ACL id without parameter values; every walker throws on an unknown node kind.
- [x] **Step 2: CRUD query builder.**
- [x] **Step 3: Authorization service and placeholder resolution.**
- [x] **Step 4: Run the touched tests, Pint, commit.**

## Task 4: Client requests (S3, S7, S12)

**Files:** `Modules/Core/app/Casts/ListRequestData.php` (`conformFilterNode`), request validation of filters.

- [x] **Step 1: Failing tests.** A request filter `{"scope": "valid"}` on a model declaring `valid` with `client: true` narrows the list; an ACL-only scope (`active`) gets `422`; an undeclared scope and a bad parameter get `422`; a request placeholder is not resolved; the scope combines with the caller's ACL (AND), never widening it.
- [x] **Step 2: Parsing and validation.**
- [x] **Step 3: Run the touched tests, Pint, commit.**

## Task 5: Search (S8)

**Files:** `Modules/Core/app/Search/Services/ScoutSearchConstraintApplier.php`.

- [x] **Step 1: Failing tests.** A scope node is never pushed to the engine; in an AND group it is dropped from the engine filters and enforced at rehydration; an OR group holding a scope is left out whole and still enforced; the database engine applies it directly.
- [x] **Step 2: Applier.**
- [x] **Step 3: Run the touched tests, Pint, commit.**

## Task 6: Declarations (S9)

**Files:** `Modules/Core/app/Models/Concerns/HasValidity.php`, `Modules/Core/app/Models/Concerns/HasActivation.php`.

- [x] **Step 1: Failing tests.** The scopes listed in S9 are declared with the stated client flag; `validityOrdered` and `withValidityFilter` are not; every declared scope passes the purity check on a real model.
- [x] **Step 2: Attributes.**
- [x] **Step 3: Run the touched tests, Pint, commit.**

## Task 7: Filament (S10)

**Files:** `Modules/Core/app/Filament/Resources/ACLS/Schemas/ACLForm.php`, `Modules/Core/app/Filament/Resources/ACLS/Tables/ACLsTable.php`.

- [ ] **Step 1: Failing tests.** The ACL table describes a scope node with its parameters instead of failing; the form refuses an undeclared scope through the model rules of Task 2. No other Filament change (S10).
- [ ] **Step 2: Description and helper text.**
- [ ] **Step 3: Run the touched tests, Pint, commit.**

## Task 8: CMS defaults (S11)

**Files:** `Modules/CMS/database/seeders/CMSDatabaseSeeder.php`, `Modules/CMS/tests/Feature/Seeders/CMSDatabaseSeederTest.php`.

- [ ] **Step 1: Failing tests.** A fresh seed gives the anonymous api reader a contents ACL `valid` and a categories ACL `valid`, `active` and the CMS entity relation filter; references and media keep their relation filters on the owner content, now expressed with `valid`; the existing visibility tests (`ContentGuestVisibilityAclTest`, `CategoryGuestVisibilityAclTest`, search and media) stay green; a re-seed leaves a stored column ACL untouched.
- [ ] **Step 2: Seeder.**
- [ ] **Step 3: Run the CMS seeder and visibility tests, Pint, commit.**

## Task 9: Documentation and closing

**Files:** `Modules/Core/docs/ACL_SYSTEM.md` (Filter Format), `Modules/Core/docs/CRUD_SYSTEM.md` (request filters), `Modules/Core/docs/rag/API_ACCESS_CONTROL.md`, `Modules/CMS/README.md`, `Modules/CMS/docs/rag/MODULE.md`, plan and spec indexes.

- [ ] **Step 1: Docs.** The node, the attribute and its client flag, parameters and placeholders, purity, errors, search behaviour, the declared scopes, how a module declares its own.
- [ ] **Step 2: Close.** Delivery status, `**Documented in:**`, spec status, indexes; run `tests/Unit/ClosedPlansPointToDocumentationTest.php`; ask the user to run the full suite.
