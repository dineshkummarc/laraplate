# Extender attribute overlay in the CRUD API: design

**Status:** Implemented (2026-10-14). Plan: `docs/superpowers/plans/2026-10-14-extender-attribute-overlay.md`.

**Date:** 2026-10-14

**Scope:** `Modules/Shop` (`Product`), `Modules/Core` (a generic contract and the property resolver), `Modules/CMS` docs of the content extension seam.

**Related:** `docs/superpowers/specs/2026-09-17-cms-content-extension-seam-design.md` (C1 to C18, E2a), `docs/superpowers/specs/2026-10-14-stored-property-filters-design.md` (the resolver this extends), `Modules/CMS/docs/CONTENT_EXTENSION.md`.

---

## 1. Purpose

`Product` merges its extended `Content` in PHP (E2a): `$product->title` reads and writes the content's title as if it were a product attribute. The CRUD answer does not: a product serializes as its own ten columns plus a nested `content` object, and a filter on `title` finds no such column. Clients read `content.translations[0].title` and filter on `content.title`, which is the knowledge of the storage that the CRUD API is meant to hide.

Success: a product list or detail carries `title`, `slug` and the dynamic fields at the root, and `{"property": "title", "operator": "like", "value": "%moon%"}` works on `products`, exactly as on `contents`.

## 2. Decisions

**M1. The overlay is a `toArray()` override, not a Resource class.** The CRUD wraps every model in a generic `JsonResource` that calls `toArray()`, and the output rules (`$hidden`, anonymous hiding, media projection, requested columns, relation authorization) hang on model attributes. A Resource per model would have to reimplement them and could drift from the ACL. The existing overlays work the same way: `HasTranslations::toArray` (translated fields) and `HasPlace::toArray` (place fields). A stable external contract for `/api/v1` is a separate concern and may add Resources later without removing this.

**M2. What is overlaid.** The array of the merged content (`$content->toArray()`, already carrying its translation and expanded dynamic fields) onto the product array, for the keys that are not native to the product (`isNativeKey`: own columns, loaded attributes, mutators, methods such as relations). A native key is never replaced, so `id`, `created_at`, `company_id` and `content` stay the product's. Nothing is overlaid when the content is not loaded: serialization never triggers a lazy load.

**M3. The nested `content` object stays.** Existing readers keep working; clients move to the root keys at their own pace.

**M4. Hiding is inherited.** The content serializes through its own rules, so what it hides, the product does not expose. `Content` does not declare attributes hidden from the anonymous user today (only `Contributor` does); if it ever does, the overlay follows without change. A test pins that a key hidden on the content is not overlaid.

**M5. Requested columns are not followed.** Like the translation and place overlays, the overlay adds its keys whatever `columns` the client asked for; it only restricts the product's own columns. Recorded so nobody expects otherwise.

**M6. Filters resolve mounted attributes.** Core gets a small contract, `Contracts\IMountsRelatedAttributes` (`mountedRelation(): string`, `isMountedAttribute(string $key): bool`), implemented by `Product` (`content`, "not native"). `ModelPropertyResolver` gains a third kind, `MOUNTED`, checked after the column, translatable and dynamic kinds of the model itself: a plain property on such a model becomes the same filter on the mounted relation, which the relation filter path already resolves on `Content` (translated and dynamic). `product.title` on a variant works through the existing relation path with no further code. Core stays ignorant of `Content`.

**M7. Where the code lives.** In `Product`, next to the `getAttribute()` and `setAttribute()` it completes. `ExtendsContentTrait` keeps the lifecycle and the save bridge. When a second extender exists, the merge (getAttribute, setAttribute, toArray, `isMountedAttribute`) moves into the trait.

**M8. Out of scope.** Sorting and facets on mounted attributes; `Contributor` and its `User`, whose merge was removed on purpose (2025-12-03) and which hides `user` and `user_id` today. There are no cross-module references to resolve: a client asks the call for the relations it needs (`items.item`) and the answer is a complete recordset, so nothing is opened in another module (owner decision, 2026-10-20).

## 3. Behaviour

| Request | Before | After |
|---|---|---|
| product list / detail | `content.title` nested only | `title`, `slug`, dynamic fields at the root; `content` still present |
| filter `title` on `products` | error, no such column | filter on `content.title`, request locale |
| filter `content.title` on `products` | works | unchanged |
| filter `product.title` on `product_variants` | needs `product.content.title` | works |
| a key hidden on the content | not at root | not at root |
