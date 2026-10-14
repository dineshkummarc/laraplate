# Scope filters in ACLs and CRUD queries: design

**Status:** Delivered (2026-10-14) with its plan, `docs/superpowers/plans/2026-10-14-scope-filters.md`; the sections below are the decision as approved, and the plan's delivery status lists what execution changed (contents ACL kept on columns, the database search engine enforcing scopes at reload, the facets ACL fix). Current behaviour is described in `Modules/Core/docs/ACL_SYSTEM.md` (Scope Filters), `Modules/Core/docs/CRUD_SYSTEM.md` and `Modules/Core/docs/rag/API_ACCESS_CONTROL.md`.

**Date:** 2026-10-14

**Scope:** `Modules/Core` (filter tree, ACL storage and validation, CRUD query builder, ACL application, search,
Filament ACL screen, request filters) and `Modules/CMS` (default ACLs of the anonymous api reader).

**Related:** `docs/superpowers/specs/2026-10-07-api-access-control-design.md` (ACLs, relation filters,
fail-closed cast, hidden columns), `Modules/Core/docs/ACL_SYSTEM.md`, `Modules/Core/docs/CRUD_SYSTEM.md`.

---

## 1. Purpose

An ACL or a CRUD request narrows rows with a tree of conditions on columns. A condition that the model
already names as a local scope (`valid`, `active`, `published`) has to be rewritten column by column in
every ACL: the CMS anonymous reader repeats the validity window of `HasValidity::valid()` for contents and
for categories. When the model changes what "valid" means, every stored copy is wrong.

This design lets a filter tree name a model scope, with parameters, so an ACL says what it means and follows
the model.

Success: an administrator writes `{"scope": "valid"}` in an ACL; it is applied wherever the ACL applies
(CRUD reads, relation filters, ACL-checked reads, search rehydration); an undeclared scope, a scope that does
more than add conditions, or a wrong parameter is refused when the ACL is saved and again when it is used;
a client request can use only the scopes a model exposes to clients.

## 2. Today

The tree has three node kinds, used by ACLs (`FiltersGroupCast`) and by CRUD requests
(`ListRequestData::parseFilters()`):

- `Filter`: `property`, `operator` (`=`, `!=`, `>`, `>=`, `<`, `<=`, `like`, `not like`, `in`,
  `between`), `value`. A dotted property becomes a `whereHas` on the relation; a `null` value becomes
  `whereNull` / `whereNotNull`.
- `FiltersGroup`: nodes joined by `and` / `or`, nested.
- `RelationFilter`: `relation`, a nested group applied to the related records, `morph_types` for morph
  relations (ACLs only; requests do not parse it).

ACL values accept placeholders resolved at use time by `AuthorizationService`: `@now`, `@today`,
`@user.<attribute>`. The cast accepts exactly these shapes and refuses anything else (fail closed).

Walkers of the tree, each to be taught the new node: `FiltersGroupCast`, `Rules\QueryBuilder` (shape),
`Rules\AclRelationFilters` (targets), `Services\Crud\QueryBuilder::recursivelyApplyFilters()`,
`AuthorizationService::applyFiltersRecursively()` and `resolvePlaceholders()`,
`ScoutSearchConstraintApplier`, `ACLsTable` (description), `ListRequestData` (request parsing).

## 3. Decisions

| # | Topic | Decision |
|---|---|---|
| S1 | Node | A fourth node, `ScopeFilter`: `{"scope": "<name>", "parameters": [...]}`. `parameters` is optional, a list, positional after the builder. Exact keys, as for the other nodes. Only local scopes can be named. A filter only adds conditions: it never removes or negates a global scope, which stays applied as the model decides, and a scope is never negated (no `not`). |
| S2 | Declaration | A scope is usable as a filter only when its method carries the attribute `#[FilterScope]`. Name and parameter types come from the method signature, so the declaration cannot drift from the code. A scope declared in a trait is declared for every model using it. |
| S3 | Client exposure | `#[FilterScope(client: true)]` makes a scope usable in CRUD requests; the default is ACLs only. A scope may read columns a client cannot filter on (hidden or anonymous-hidden columns), so it would let a client probe their values; ACLs are written by administrators and do not have this risk. |
| S4 | Parameters | Scalars (`string`, `int`, `float`, `bool`, `null`) and dates (`CarbonInterface`, from an ISO 8601 string). Arrays and objects are refused. Values are checked against the parameter types; a missing required parameter or an extra one is refused. In ACLs a parameter accepts the same placeholders as a filter value. Client requests get no placeholders, as today. |
| S5 | Purity | A scope runs inside its own nested `where` (`$query->where(fn ($q) => $q->{scope}(...))`), in AND and OR groups alike, so its conditions never leak out of their group. After the call the nested query must hold only `where` conditions: joins, orders, selected columns, groups, havings, limits or unions refuse the filter. A scope that does more than narrow rows cannot be used as a filter even if declared. |
| S6 | Relations | A scope node inside a `RelationFilter` is resolved and checked against the related model (each morph type of a morph relation). |
| S7 | Fail closed | An undeclared scope, a non-client scope in a request, a wrong parameter or an impure scope: refused when an ACL is saved (validation error), refused when used (an ACL that no longer resolves denies, as a broken relation filter does today), `422` in a request. |
| S8 | Search | Search engines take no scope. A scope node is left out of the engine filters exactly as a relation filter is (dropped from an AND group, the whole OR group left out) and enforced when the hits are reloaded from the database. |
| S9 | Declared scopes in v1 | `HasValidity`, client-usable because its columns are serialized: `valid`, `published`, `draft`, `scheduled`, `expired`, `validAt(date)`, `expiredAt(date)`, `expiring(hours)`. Not declared: `validityOrdered` (it orders) and `withValidityFilter` (a duplicate of `validAt`). `HasActivation`, ACL only because the activation column is hidden from serialization and a client could probe it: `active`, `inactive`. Other modules declare their own scopes when they need them. |
| S10 | Filament | Filament does not use the CRUD query builder and is for superadmins only, so it gets no scope feature. Its ACL screen must only keep working on ACLs that hold scope nodes: the JSON editor's validation is the shape and target rules of the `ACL` model (S7), and the ACL table describes a scope node instead of failing on it. |
| S11 | CMS defaults | The anonymous reader's ACLs use scopes for new installations: contents `valid`; categories `valid`, `active` and the CMS entity relation filter. Stored ACLs of existing installations are not rewritten (the seeder never overwrites an ACL) and keep working. |
| S12 | Compatibility | Additive: existing trees, stored ACLs and request formats are unchanged. The request format gains an optional node, documented for client authors; the proprietary UI may adopt it later. |

## 4. Components

- `Modules\Core\Casts\ScopeFilter`: readonly DTO, `scope` and `parameters`, `toArray()`.
- `Modules\Core\Attributes\FilterScope`: method attribute, `client` flag.
- `Modules\Core\Support\ScopeGuard`: per model class (memoized per class), the declared filter scopes with
  their parameter types and client flag, read by reflection over the class and its traits;
  `assertFilterable(Model, ScopeFilter, bool $client)` checks declaration, client flag and parameters and
  returns the coerced parameters; `apply(Builder, ScopeFilter, string $boolean)` runs the scope in its nested
  `where` and enforces purity.
- Every walker of section 2 handles the node; each refuses an unknown node kind, so a node added later
  cannot be skipped silently.

## 5. Error behaviour

| Where | Undeclared or impure scope, bad parameter |
|---|---|
| ACL save (Filament, CRUD on `core_acls`) | validation error naming the scope and the reason |
| ACL use (CRUD read, ACL-checked read, search rehydration) | the read is denied; the error is logged with the ACL id, never the parameter values |
| CRUD request | `422` with the reason |

## 6. Testing

- Cast and shape rule: the node round-trips; extra keys, a non-list `parameters`, an object parameter are refused.
- Declaration: a declared trait scope is found on a using model; an undeclared one is refused; a non-client
  scope is refused in a request and accepted in an ACL.
- Parameters: types, dates from ISO strings, required and optional, too many; placeholders resolved in ACLs.
- Purity: a declared scope that joins, orders or selects is refused at save and at use.
- Semantics: `valid` in an AND group, in an OR group (its conditions stay grouped), inside a relation
  filter, inside a morph relation filter.
- Search: a scope in an AND group is enforced at rehydration; an OR group holding a scope is left out of the
  engine and still enforced.
- CMS: the anonymous reader sees the same contents and categories as with the column ACLs (the existing
  visibility tests stay green on the scope ACLs).

## 7. Out of scope

- Negation of a scope (`not`), and any removal of a global scope; a scope's opposite is declared as its own scope (`active` / `inactive`).
- A guided ACL editor in Filament.
- Scopes in sorts, facets or group-by.
- Rewriting stored ACLs of existing installations.
