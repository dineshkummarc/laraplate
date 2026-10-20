# Shop product admin (Filament write-surface) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a superadmin create and edit a `Product` — its backing CMS `Content`, its `ProductVariant`s, and each variant's ERP-`Item` composition — through a Filament backoffice resource, and make the backend properly writable for it.

**Architecture:** A Shop write **action** centralises aggregate creation/update (build the `Content`, link it through the content-extension seam, create variants and composition in one transaction) so the UI and any future API share one write path. A Filament `ProductResource` drives it: a 4-step **create Wizard** (Product -> Content -> Variants -> Composition) and a **tabbed edit** form. The wizard separates `Product` and `Content`, so the transparent-merge mass-assignment gap never arises; the Content step reuses CMS `ContentForm`.

**Tech Stack:** PHP 8.5, Laravel 12, Filament 5 (via `Coolsam\Modules\Resource` auto-discovery), Pest + Filament testing, the CMS content-extension seam, Core `Entity`/`Preset`, ERP `Item`/`BelongsToCompany`.

**Spec:** `docs/superpowers/specs/2026-10-20-shop-product-admin-design.md`

## Global Constraints

- Every PHP file `declare(strict_types=1);`; braces; explicit types; `#[Override]` on overrides; `final` where siblings are final; constructor property promotion. No em-dashes in code/docs.
- **Filament is backoffice only** (`.ai/rules/filament.md`): build the superadmin maintenance surface; the merchant catalog UX is `laraplate-ui` work, recorded out of scope, not built here.
- **Default preset only** (spec PA4): the Content attaches to the plan-#1 product `Entity` + its seeded default `Preset`; no storefront `Category` selection, no per-category presets (plan #3).
- Content is created through the seam (`setTempContent()` + save), mirroring `ProductFactory::makeBackingContent()`; never by mass-assigning content keys onto `Product` (spec PA3).
- Step 2 reuses `Modules\CMS\Filament\Resources\Contents\Schemas\ContentForm` (spec PA3); resources extend `Coolsam\Modules\Resource`, use Core Filament utils (`HasForm`, `HasTranslatedResourceLabels`) like `ContentResource`.
- Model invariants from plan #1 stand and are not re-implemented: single default per product, `role` in `{main,component}`, soft-delete cascade. The form surfaces them; the models enforce them.
- Permissions only from `Core\Support\PermissionName`.
- Tests are Pest; Filament tests under `Modules/Shop/tests/Feature/Filament/`, mirroring `Modules/CMS/tests/Feature/Filament/ContentResourceTest.php`. No classes declared in test files. If a run aborts on a `TestEnvironmentGuard` cached-config error, run `php artisan config:clear`.
- Run `vendor/bin/pint --format agent <files>` from the laraplate root with an explicit file list before each commit. Shop is a git submodule on `master`: stage only your files, commit + push per task.

## Review Focus

- **A new product must create exactly one `Content` on the product entity + default preset, not a new entity** — a regression here silently forks the entity model (Task 1 test `creates one content on the product entity and default preset`).
- **A translatable content field (title) set through the create path must persist** — the merge write-through is the subtle part (Task 1 test `persists the content title through the create action`).
- **Two variants marked default in one submit must resolve to exactly one** — the wizard can submit multiple; the model's single-default rule must win (Task 1 test `a create with two default variants ends with one default`).
- **A composition row with a `role` outside `main`/`component`, or a soft-deleted/absent `item_id`, is rejected** — bad admin input must not persist (Task 1 test `rejects an invalid composition role and a missing item`).
- **The products table must not list another company's or a soft-deleted product, and a user without product permission is denied** — tenancy/visibility/authz on the backoffice surface (Task 3 tests `the table scopes to the active company and excludes trashed` and `an unauthorized user cannot access the resource`).

---

### Task 1: Backend write actions + backing-content resolver

**Files:**
- Create: `Modules/Shop/app/Actions/CreateProductAction.php`
- Create: `Modules/Shop/app/Actions/UpdateProductAction.php`
- Modify: `Modules/Shop/app/Models/Product.php` (add the static backing-content resolver)
- Modify: `Modules/Shop/database/factories/ProductFactory.php` (reuse the resolver — DRY)
- Test: `Modules/Shop/tests/Feature/ProductWriteActionTest.php`

**Interfaces:**
- Produces:
  - `Product::newBackingContent(): \Modules\CMS\Models\Content` (static) — builds an unsaved `Content` attached to the product `Entity` + default `Preset` (the logic currently in `ProductFactory::makeBackingContent()`, promoted here and reused by the factory).
  - `CreateProductAction::handle(array $data): Product` — `$data` shape: product columns (`kind`, `is_published_in_shop`, `featured`, `release_date`, `metadata`), `content` => array of content fields (title, slug, components, valid_from/to), `variants` => list of `['is_default'=>bool, 'attributes'=>array, 'items'=>list of ['item_id'=>int,'quantity'=>string,'role'=>string]]`. Creates the full aggregate in one transaction derived from the owning models: build the Content via `newBackingContent()` + fill its fields, `setTempContent()` on a new `Product`, save (seam stamps `extended_type`/`content_id`), then create variants and their items. Returns the saved `Product`.
  - `UpdateProductAction::handle(Product $product, array $data): Product` — updates the product columns and the merged content fields, and syncs variants/composition (create/update/delete to match `$data`), preserving the invariants.

- [x] **Step 1: Write the failing tests** — `ProductWriteActionTest` (call `setupShopEntities()` first):
  - `creates one content on the product entity and default preset`: `CreateProductAction::handle([...])` yields a `Product` whose `content` is one CMS `Content` on the seeded product entity + default preset (assert the entity type and presettable), and exactly one content row was created.
  - `persists the content title through the create action`: pass `content.title`; reload the product via a fresh query; the title is persisted on the content and reads back through the product root.
  - `a create with two default variants ends with one default`: pass two variants both `is_default=true`; after create, `variants()->where('is_default', true)->count() === 1`.
  - `rejects an invalid composition role and a missing item`: a variant item with `role='accessory'` or a non-existent/soft-deleted `item_id` throws `ValidationException`; nothing partial persists (transaction rolls back).
  - `updates columns, content and composition`: `UpdateProductAction::handle($product, [...])` changes a product column, the content title, and adds/removes a variant item; assert the resulting state and that removed rows are gone.
- [x] **Step 2: Run them, verify they fail.** `php artisan test --compact Modules/Shop/tests/Feature/ProductWriteActionTest.php`
- [x] **Step 3: Implement** `Product::newBackingContent()` (promote from the factory; refactor `ProductFactory` to call it), `CreateProductAction`, `UpdateProductAction`. Business logic in the actions (project convention); transaction from the owning model, no raw `DB::`. Reuse the model invariants (do not re-validate role/default in the action beyond passing data through; the models reject bad values).
- [x] **Step 4: Run them, verify they pass.**
- [x] **Step 5: Commit** — `feat(shop): product write actions and backing-content resolver`.

---

### Task 2: `ProductResource` + create wizard

**Files:**
- Create: `Modules/Shop/app/Filament/Resources/Products/ProductResource.php`
- Create: `Modules/Shop/app/Filament/Resources/Products/Pages/{ListProducts,CreateProduct,EditProduct}.php`
- Create: `Modules/Shop/app/Filament/Resources/Products/Schemas/ProductWizard.php` (the 4 wizard steps)
- Test: `Modules/Shop/tests/Feature/Filament/ProductResourceTest.php`

**Interfaces:**
- Consumes: `CreateProductAction` (Task 1); CMS `ContentForm` (step 2); `Product` (Task 1 resolver).
- Produces: a registered Filament resource (slug `shop/products`), its create page using a `Wizard` whose submit calls `CreateProductAction::handle()`.

- [ ] **Step 1: Write the failing test** — `ProductResourceTest` (mirror CMS `ContentResourceTest`, `setupShopEntities()` first): `a product is created through the wizard` — using Filament's testing helpers, fill step 1 (Product columns), step 2 (Content: title/slug/components/validity, via the reused `ContentForm` fields), step 3 (one default variant), step 4 (one `main` item referencing an `Item::factory()`), submit; assert a `Product` with its content, one default variant, and one composition row exist.
- [ ] **Step 2: Run it, verify it fails.**
- [ ] **Step 3: Implement** `ProductResource` (extend `Coolsam\Modules\Resource`, `$model = Product::class`, slug `shop/products`, navigation group/icon like `ContentResource`, `HasTranslatedResourceLabels`), `CreateProduct` page rendering `ProductWizard` and calling `CreateProductAction` on submit, and `ProductWizard` with 4 `Step`s: (1) Product fields; (2) the CMS `ContentForm` schema; (3) a `Repeater` of variants with an `is_default` toggle; (4) per-variant `VariantItem` rows (`item_id` select from `erp_items`, `quantity`, `role` select `main`/`component`) — choose the cleanest Filament 5 nesting (items nested in the step-3 variant repeater, or a step-4 repeater keyed on step-3 variants). Follow the `filament-development` skill and verify Filament 5 wizard/repeater APIs via `search-docs`; do not assume. `ListProducts`/`EditProduct` may be stubs until Task 3.
- [ ] **Step 4: Run it, verify it passes.**
- [ ] **Step 5: Commit** — `feat(shop): product resource with a create wizard`.

---

### Task 3: Edit (tabs), table, permissions

**Files:**
- Modify: `Modules/Shop/app/Filament/Resources/Products/Pages/EditProduct.php` (+ a shared edit schema, e.g. `Schemas/ProductForm.php`)
- Create: `Modules/Shop/app/Filament/Resources/Products/Tables/ProductsTable.php`
- Create: `Modules/Shop/app/Policies/ProductPolicy.php` (+ permission names in `Core\Support\PermissionName` / the Shop permission seed, following a sibling)
- Modify: `Modules/Shop/app/Filament/Resources/Products/ProductResource.php` (table, relations, authorization)
- Test: extend `Modules/Shop/tests/Feature/Filament/ProductResourceTest.php`

**Interfaces:**
- Consumes: `UpdateProductAction` (Task 1), `ProductResource` (Task 2).
- Produces: the edit page (tabbed sections Product/Content/Variants/Composition driven by `UpdateProductAction`), the products table, and the product permissions wired to the resource.

- [ ] **Step 1: Write the failing tests** — add to `ProductResourceTest`:
  - `a product is edited through the tabbed form`: edit an existing product's column, content title and a variant item; assert persisted via `UpdateProductAction`.
  - `the table scopes to the active company and excludes trashed`: with products in two companies and one soft-deleted, the list shows only the active company's live products (query `Product`, not `Content`).
  - `an unauthorized user cannot access the resource`: a user without the product permission is denied (403/redirect) on list/create/edit.
- [ ] **Step 2: Run them, verify they fail.**
- [ ] **Step 3: Implement** `ProductsTable` (columns: title from merged content, `kind`, `is_published_in_shop`, `featured`, company), the tabbed `EditProduct` form calling `UpdateProductAction`, `ProductPolicy` + the product permission names (mirror how CMS `Content` ties to `PermissionName`), and wire authorization on `ProductResource`.
- [ ] **Step 4: Run them, verify they pass.**
- [ ] **Step 5: Commit** — `feat(shop): product edit, table and permissions`.

---

### Task 4: Documentation and gate

**Files:**
- Modify: `Modules/Shop/docs/rag/MODULE.md` (admin write-surface section: the resource, the create wizard, the edit tabs, the write actions, the default-preset limit, what is left to laraplate-ui and plan #3)
- Modify: `Modules/Shop/README.md` if any env/config was introduced (none expected)
- Test: run the module suite once green.

- [ ] **Step 1: Write the `MODULE.md` admin section** (present behaviour, not the plan).
- [ ] **Step 2: Run `php artisan test --compact Modules/Shop/tests`** and confirm green; then Pint from the root on all PHP touched by the plan.
- [ ] **Step 3: Add this plan's `## Delivery status` + a `**Documented in:** Modules/Shop/docs/rag/MODULE.md` line** (enforced by `tests/Unit/ClosedPlansPointToDocumentationTest.php`).
- [ ] **Step 4: Commit** — `docs(shop): document the product admin write-surface`.

---

## Notes / out of scope (recorded)

- Merchant-facing catalog management UX -> `laraplate-ui` (proprietary Vue app), a later plan.
- Storefront categories, per-category presets, facets, browse price/stock snapshot, `/app` read endpoints -> plan #3.
- A programmatic `/app` product-write API and the generic `fill()` mass-assignment routing of content keys on `Product` -> deferred; the wizard sidesteps it (spec PA3). The `CreateProductAction`/`UpdateProductAction` are the reusable write path a future API would call.
- Generalising the wizard into a shared multi-extender framework -> deferred until a second production content-extender exists (likely CRM/ERP, per the brainstorm).
