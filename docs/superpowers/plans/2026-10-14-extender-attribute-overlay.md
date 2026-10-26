---
status: completed
created_on: 2026-10-14
---
# Extender attribute overlay in the CRUD API. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Tick each step when it is done, in the same work block. A step dropped on purpose is `- [-]` with its reason on the same line.

**Status:** Delivered (2026-10-14). See the delivery status below.

**Goal:** a product serializes the attributes of its merged content at the root, and filters on those attributes resolve through the mounted relation, so clients no longer know the content is a separate record.

**Architecture:** `Product::toArray()` overlays the non-native keys of the loaded content; a Core contract `IMountsRelatedAttributes` lets `ModelPropertyResolver` classify a property as `MOUNTED` and `QueryBuilder` rewrite it to the same filter on the mounted relation.

**Tech Stack:** PHP 8.5, Laravel 12, Pest 4. No new dependency.

**Spec:** `docs/superpowers/specs/2026-10-14-extender-attribute-overlay-design.md`, decisions M1 to M8.

## Delivery status (2026-10-14): delivered

All three tasks shipped: `Product::toArray()` and the `IMountsRelatedAttributes` implementation in `Modules/Shop`, the contract, the `MOUNTED` kind and the query builder rewrite in `Modules/Core`. Tests: `ProductOverlaySerializationTest` and `ProductStoredPropertyFilterTest` (Shop), `QueryBuilderStoredPropertyTest` (Core, stub models).

**Documented in:** `Modules/CMS/docs/CONTENT_EXTENSION.md` (section "The extender in the CRUD API"), `Modules/Core/docs/CRUD_SYSTEM.md` (section "Translated and Dynamic Fields") and `Modules/Core/docs/rag/GLOSSARY.md`.

Divergences:

- The merge code stays in `Product` (spec M7) instead of moving to `ExtendsContentTrait`: the first design proposed the trait, but `isNativeKey()` and `mergedContent()` are private to `Product`, so the overlay sits with them until a second extender exists.
- A dynamic field at the root is not tested in the Shop suite: the overlay takes the content's own `toArray()`, whose dynamic fields the CMS suite covers.

## Global Constraints

- Every PHP file `declare(strict_types=1)`; braces always; explicit types; `#[Override]`; `final` where siblings are final; PHPDoc over inline comments; English code and docs.
- Tests live in the module that owns the code (`Modules/Shop/tests`, `Modules/Core/tests`); stubs in `tests/Stubs`, never declared in a test file. `php artisan config:clear` before tests.
- Pint from the laraplate root with explicit files.
- Commits go to the repository that owns each file, explicit paths, only your own hunks; never push.

## Task 1: Serialize the merged content at the root (M1 to M5, M7)

**Files:** `Modules/Shop/app/Models/Product.php`; test `Modules/Shop/tests/Feature/ProductOverlaySerializationTest.php`.

- [x] **Step 1: Failing tests.** A product list and detail carry `title`, `slug` and a dynamic field at the root and still carry `content`; `id`, `created_at`, `company_id` stay the product's; a key the content hides (or a relation name of the product) is not replaced; an unloaded content adds nothing and runs no query; the root `title` is the one of the request locale, through the CRUD list and detail. A dynamic field at the root is not tested here: the overlay takes the content's own `toArray()`, which `ContentsStoredPropertyFilterTest` and the CMS suite already cover for dynamic fields.
- [x] **Step 2: `Product::toArray()`** overlaying the non-native keys of `mergedContent()->toArray()`.
- [x] **Step 3: Run the touched tests, Pint, commit.**

## Task 2: Contract and filters on mounted attributes (M6)

**Files:** create `Modules/Core/app/Contracts/IMountsRelatedAttributes.php`; modify `Modules/Core/app/Services/Crud/ModelPropertyResolver.php`, `Modules/Core/app/Services/Crud/QueryBuilder.php`, `Modules/Shop/app/Models/Product.php`; tests in `Modules/Core/tests/Integration/Services/Crud` (stub) and `Modules/Shop/tests/Feature`.

- [x] **Step 1: Failing tests.** The resolver classifies a plain property of a model implementing the contract as `MOUNTED` unless it is a column, a mutator or a method; a filter `title` on `products` (list endpoint) equals `content.title`, in the request locale; `like`, `in` and an OR group beside a native column work; `product.title` on `product_variants` works; a native key (`kind`) is still a column filter; a model without the contract is unchanged.
- [x] **Step 2: Contract and resolver kind.**
- [x] **Step 3: `QueryBuilder` rewrite** of a mounted property to `{relation}.{property}`.
- [x] **Step 4: `Product` implements the contract** (`content`, `! isNativeKey`).
- [x] **Step 5: Run the touched tests and the CRUD suites, Pint, commit.**

## Task 3: Documentation and close

**Files:** `Modules/CMS/docs/CONTENT_EXTENSION.md`, `Modules/Core/docs/CRUD_SYSTEM.md`, `Modules/Core/docs/rag/GLOSSARY.md`.

- [x] **Step 1: Document** the overlay (what, native keys, hiding, columns), the mounted filters and the contract.
- [x] **Step 2: Close the plan:** delivery status, `**Documented in:**`, `status: closed`, both INDEX files.

## Out of scope

- Sorting and facets on mounted attributes, `Contributor` and `User` (spec M8). No cross-module references: the call requests the relations it needs.
- The UI switch to the root keys: `docs/superpowers/plans/2026-10-14-palette-record-search.md` of the stack repository, Task 6a.
