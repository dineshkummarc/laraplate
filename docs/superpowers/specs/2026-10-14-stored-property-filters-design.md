# Stored property filters in the CRUD API: design

**Status:** Approved (2026-10-14). Task 1 built, not committed. Plan: `docs/superpowers/plans/2026-10-14-stored-property-filters.md`.

**Date:** 2026-10-14

**Scope:** `Modules/Core` (CRUD query builder, `ModelPropertyResolver`, search endpoint reuse) and `Modules/CMS` (feature tests on `contents`).

**Related:** `Modules/Core/docs/CRUD_SYSTEM.md`, `docs/superpowers/specs/2026-10-14-scope-filters-design.md` (the other extension of the filter tree; both touch `QueryBuilder::applyFilter`).

---

## 1. Purpose

A CRUD client filters on `title` or `color`. Whether the entity keeps that value in a column, in the translation table or in a JSON container of dynamic fields is the entity's business, not the client's. Today a flat property is always a column: `title` on `contents` fails, and the CMS UI has to spell `translations.title`. The dynamic fields cannot be filtered at all.

The CRUD API is entity agnostic. It resolves the property.

Success: `{"property": "title", "operator": "like", "value": "%moon%"}` and `{"property": "color", "operator": "eq", "value": "red"}` work on any entity that stores them, with no client knowledge of the storage.

## 2. Decisions

**D1. Resolution order for a flat property** (no dot, `[A-Za-z_][A-Za-z0-9_]*`): a column of the model's table; a translatable field (`HasTranslations::getTranslatableFields()`); a dynamic field (model uses `HasDynamicContents`). A property that is none of these keeps today's behaviour. Dotted paths are unchanged: the leaf of `content.title` is resolved on the related model by the same rule, because relation filters already re-enter `applyFilter`.

**D2. Translated fields are matched in the locale of the request.** The locale of a request is `LocaleContext::get()` (the user's language through `LocalizationMiddleware`). When the model falls back (`translationFallbackEnabledBySettings()`) a record with no translation in that locale is matched on the default locale. No search across all languages.

**D3. Dynamic fields by exclusion.** Not a column and not translatable on a model with dynamic contents means "maybe dynamic". The presets are not consulted: one recordset mixes entities with different presets and fields, so no preset describes it. The field is looked for in every record and is simply absent from the others.

**D4. Both containers are searched.** Whether a dynamic field was configured translatable (`components` of the translation, per locale) or shared (`shared_components` of the main row, or `components` on a model that is not translatable) is not known per record, nor consistent across presets. The filter is an OR over every container that exists, the translation one scoped by D2.

**D5. Operators.** Everything `applyFilter` already supports (equals, not equals, `like`, `in`, null checks) applies to the JSON path as a string value. Numeric and date comparisons on dynamic fields are out of scope.

**D6. Out of scope.** Sorting, grouping and facets on dynamic fields; a 422 for names no preset declares (a typo returns no rows; revisit when a use case exists); making more entities `Searchable`.

**D7. Full text search stays the search endpoint.** `POST /app/crud/search/{module}/{entity}` already serves the Scout and advanced search for `Searchable` models (today `Content`, `Location`, `Media`, `Ticket`). Free text for a palette or a search box belongs there; this design serves structured filters. An entity becomes `Searchable` by the module that owns it, not by this work.

## 3. Behaviour

| Property | Stored as | Condition applied |
|---|---|---|
| `id`, any column | column | as today |
| `title`, `slug` on `contents` | translation table | `whereHas('translations')` on the request locale (default locale as fallback), then the filter on the translation column |
| `color`, declared dynamic | JSON | `shared_components->color` OR `translations.components->color` (locale-scoped) |
| `color` on a model with only `components` | JSON | `components->color` |
| unknown on a model without dynamic contents | none | unchanged |

The ACL application path calls the same `applyFilter`; ACL filters only gain properties that used to be errors, so nothing a stored ACL could express changes (fail closed).

## 4. Compatibility

The CMS app sends `translations.title` today; it keeps working. The UI drops its workarounds in the follow-up plan `docs/superpowers/plans/2026-10-14-palette-record-search.md` of the stack repository.
