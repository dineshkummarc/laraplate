# Shop product admin (Filament write-surface) — design

**Date:** 2026-10-20
**Module:** `Modules/Shop` (consumer of CMS content-extension seam, Core `Entity`/`Preset`, ERP `Item`/`BelongsToCompany`)
**Status:** Design agreed. No implementation started.
**Related:** `2026-09-17-shop-module-design.md` (Shop module decisions E1-E31); `2026-10-06-shop-catalog-foundation.md` (plan #1, shipped — `Product`/`ProductVariant`/`VariantItem`, the seed default preset, the deferred write-surface constraints this plan resolves). This is Shop plan **#2** of the decomposition roadmap. Storefront browsing (categories, per-category presets, facets, browse snapshot, `/app` read endpoints) is plan **#3**; cart/checkout and later slices shift down accordingly.

---

## 1. Purpose

Plan #1 shipped the Shop catalog models but left no way to create or manage a product except test factories. This plan adds the **backoffice write-surface**: a superadmin can create and edit a `Product` — its backing CMS `Content`, its `ProductVariant`s, and each variant's ERP-`Item` composition — through a guided form, and the backend is made properly writable for it.

It also resolves the write-surface constraints deferred by plan #1 for the admin path (content-field routing, the `Product`→entity/preset resolution), and validates that the content-extension seam's create path works through a real UI, not only the factory.

## 2. Scope and non-goals

**v1 (this plan):**
- A Filament `ProductResource` in the superadmin backoffice (`/admin`).
- **Create** through a 4-step **Wizard**: Product → Content → Variants → Composition.
- **Edit** through tabbed sections mirroring the wizard steps.
- The backend accessors the resource needs (a `Product`→`Entity`/`Preset` resolver).
- Product-admin permissions via Core `PermissionName`.
- Feature tests proving create/edit through the resource produce a correct `Product` + `Content` + variants + composition.

**Non-goals (deferred, recorded so the boundary is explicit):**
- **Merchant-facing catalog management UX** (the surface a merchandiser uses every day): per `.ai/rules/filament.md`, day-to-day working surfaces belong to **`laraplate-ui`** (the proprietary Vue app), not Filament. Filament gets only the backoffice/maintenance slice; the full catalog UX is a later `laraplate-ui` plan.
- **Storefront categories, per-category presets, facets/layered navigation, the browse price/stock snapshot, `/app` read endpoints** — plan #3. This plan uses only plan #1's seeded default product preset (`standard`).
- **A programmatic `/app` product-write API and the generic `fill()` mass-assignment routing** of content-owned keys on `Product`. The wizard sidesteps mass-assignment by construction (PA3); a programmatic consumer (the `laraplate-ui` API) can add `fill()` routing when it needs it. Recorded, not built here.
- **Generalising the wizard into a shared multi-extender framework.** `Product` is the only production content-extender today; likely future reuse (CRM/ERP) is noted but not pre-built (PA2).

## 3. Locked decisions

| # | Decision | Rationale |
|---|----------|-----------|
| PA1 | **Filament backoffice resource only; the merchant catalog UX is `laraplate-ui` work, recorded out of scope.** This plan builds the superadmin maintenance/CRUD surface for products. | `.ai/rules/filament.md`: Filament is config/monitoring/superadmin maintenance; the everyday working surface belongs to the module's app in `laraplate-ui`. Build the backoffice part, record the rest as left to the application. |
| PA2 | **Create via a 4-step `Wizard` (Product → Content → Variants → Composition).** This is the project's first Filament wizard. It is built concretely for `Product`; the Content-step schema and the create-extender-via-seam logic are kept cohesive and extractable for likely later reuse (CRM/ERP), but **not** generalised into a framework now (one production extender exists). | A multi-model aggregate (`Product` + `Content` + variants + composition) is clearest created as guided steps. Premature generalisation for a single consumer is YAGNI; cohesion keeps extraction cheap when a second extender appears. |
| PA3 | **The wizard separates `Product` and `Content`, so the transparent-merge mass-assignment gap is avoided by construction.** Step 1 captures `Product`'s own columns; step 2 captures the `Content`. On submit the resource creates the `Content` and links it to the `Product` through the shipped seam (`setTempContent()` + save, exactly as `ProductFactory` does), never by mass-assigning content keys onto the `Product` root. **Step 2 reuses CMS `ContentForm`'s schema** rather than re-implementing the Content fields. | The deferred `fill()` routing constraint (plan #1) exists only for mass-assigning content keys on `Product`; a two-step form that manages each model explicitly does not hit it. Reusing `ContentForm` keeps the Content editing surface DRY with CMS and inherits its dynamic-field handling. |
| PA4 | **Default preset only.** The Content is created on the plan-#1 product `Entity` + its seeded default `Preset` (`standard`); step 2 renders that preset's dynamic fields. No storefront `Category` selection and no per-category presets in this plan. | Per-category attribute sets and the category tree are plan #3 (E20). A product created here is manageable now and categorised for browsing in #3. Keeps this plan a pure write-surface. |
| PA5 | **Backend: a `Product`→`Entity`/`Preset` resolver** (the accessor deferred by plan #1). `Product` exposes how to resolve its Shop product `Entity` and the `Preset` its `Content` is on (the default preset for a new product), so the resource can render the Content's dynamic fields and attach the Content to the right `(entity, preset)`. Shop-only; no CMS edit. | The resource must know the entity/preset to build step 2 and to create the Content correctly. Plan #1 recorded this as needed by its first consumer; this is that consumer. |
| PA6 | **Variants step = repeater honouring the single-default rule; Composition = per-variant `VariantItem` rows.** The Variants step edits `ProductVariant`s (a `is_default` marker with the model's exactly-one-default-per-product rule enforced in the form, plus the label-only `attributes`). The Composition step edits each variant's `VariantItem`s (`item_id` chosen from ERP `erp_items`, `quantity`, `role` in the closed set `main`/`component`). | Mirrors the plan-#1 model structure and its invariants (single default, E31 role set, E7b composition). The form must not let the user create two defaults or an out-of-set role; the model already rejects both, and the form surfaces it. |
| PA7 | **Edit is tabbed sections, not a wizard.** The edit page presents the same four concerns as tabs/sections (Product, Content, Variants, Composition). | A wizard suits linear first-creation; editing an existing product wants direct access to any concern. Filament's edit form renders the step schemas as sections. |
| PA8 | **Permissions via Core `PermissionName`** (view/create/update/delete product), wired to the resource like sibling resources. | Consistent ACL; no bespoke permission mechanism. |
| PA9 | **Tenancy: `company_id` is auto-filled via ERP `BelongsToCompany`; the resource respects the active company context.** It is not a free-text user field; a superadmin operating across companies sets it through the active-company context, not by typing an id. | Matches E23 and how ERP resources behave; avoids a denormalised/typed company id on the form. |

## 4. Components

- `Modules/Shop/app/Filament/Resources/Products/ProductResource.php` (+ `Pages/{ListProducts,CreateProduct,EditProduct}.php`, `Schemas/*`, `Tables/ProductsTable.php`), structured like `Modules/CMS/app/Filament/Resources/Contents/`.
- **Create wizard** (`CreateProduct` page): 4 steps per PA2/PA3/PA6.
- **Edit form** (`EditProduct` page): tabbed sections per PA7.
- **Table**: columns from the merged content (title) + `Product` columns (`kind`, `is_published_in_shop`, `featured`, company), querying `Product` (the extender), unaffected by the Content hidden-scope.
- **Backend accessor** on `Product` per PA5.
- Reuse: CMS `ContentForm` schema (PA3); Core Filament utilities (`Modules/Core/app/Filament/Utils/HasForm.php`, `HasFilamentFormDataSanitizer.php`, `FilamentTraitResolver`) as the sibling resources use them; the content-extension seam (`setTempContent`); ERP `Item` for the composition select.

## 5. Data flow (create)

1. Step 1 collects `Product` columns; `company_id` resolved from the active company (PA9).
2. Step 2 collects the `Content` via the reused `ContentForm` schema, including the default preset's dynamic fields (PA4).
3. Steps 3-4 collect variants and, per variant, the `VariantItem` composition (PA6).
4. On submit, in one transaction derived from the owning models: build the `Content`, attach it to the product `Entity` + default `Preset`, `setTempContent()` on the new `Product`, save the `Product` (the seam stamps `extended_type`/`content_id` and persists the content), then create the variants and their items. The single-default and role invariants are enforced by the models (plan #1).

## 6. Constraints and traps

- The product `Content` must be created on the product `Entity` + default `Preset` (PA4/PA5), never a new entity — mirror `ProductFactory::makeBackingContent()`.
- The table queries `Product`; do not accidentally query `Content` (the seam hides extended contents from plain `Content` reads).
- The Variants step must not allow two defaults nor a role outside `main`/`component`; the models reject both, and the form validates before submit.
- Composition references ERP `Item`s; the select must scope to live items (and, when tenancy is wired, to the product's company — a storefront/checkout concern, so here only basic existence/live scoping).
- No secrets or payment data anywhere (not relevant to this surface, but the module-wide E14 rule stands).
- The wizard is the first in the project; follow the `filament-development` skill and verify Filament 5 wizard/reactive-schema APIs via `search-docs` rather than assuming.

## 7. Ready criteria (before implementation)

- The `Product`→`Entity`/`Preset` resolver (PA5) contract is pinned against plan #1's seeded entity/preset.
- The exact shape of the Composition step (step 4 iterating step-3 variants vs variants carrying nested item repeaters) is chosen at plan time against Filament 5's repeater/wizard capabilities.
- The edit-as-tabs mapping of the four concerns is confirmed.
- Permission names are registered in Core `PermissionName` and wired like a sibling resource.

## 8. Open questions (settle at plan time)

- **Composition step form**: nested repeater inside the Variants step vs a dedicated step 4 keyed on the step-3 variants — pick the cleaner Filament 5 construction.
- **Dynamic-field rendering in step 2**: confirm `ContentForm` already renders the preset's dynamic fields when reused outside the CMS ContentResource context, or what minimal adaptation it needs.
- **Default-variant UX**: how the form marks the single default when the repeater has one row vs many (default the first row; enforce on save).
