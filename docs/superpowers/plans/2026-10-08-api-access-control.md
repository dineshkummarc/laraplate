# API access control: guards, default roles, tokens, related records. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every `/api` route runs on the `api` guard with one optional-token authentication and one switch, permissions are checked on the request's guard on every path, `guest` exposes only the public CMS list, related records obey their own permission and ACL in select and search, writes never touch what the writer cannot see, and users and service accounts can hold scoped tokens.

**Architecture:** Permissions exist per guard; `AuthorizationService` checks against the active guard set by a new `AuthenticateApiRequest` middleware that also owns the API switch, token resolution and the anonymous fallback. ACL filters gain a `RelationFilter` so a role can see media and references through their owner. Relation loading in `QueryBuilder` and in the search path goes through one authorizer that applies the related entity's permission and ACL, except for models that declare themselves parts of their parent. Responses hide declared attributes from the anonymous user and return media in a safe projection on the `api` guard.

**Tech Stack:** PHP 8.5, Laravel 12, Spatie permission, Sanctum 4, Filament 5, Pest 4. No new dependency.

**Spec:** `docs/superpowers/specs/2026-10-07-api-access-control-design.md` (all sections).

## Global Constraints

- Every PHP file `declare(strict_types=1)`; braces always; explicit parameter and return types; `#[Override]` when overriding; `final` where siblings are final; PHPDoc over inline comments; English code and docs.
- Tests live in the module that owns the code (`Modules/Core/tests`, `Modules/CMS/tests`); support classes in that module's `tests/Stubs` or `tests/Support`, never declared inside a test file. Never run tests with a cached config: `php artisan config:clear` first if `bootstrap/cache/config.php` exists.
- Pint from the laraplate root with explicit files: `vendor/bin/pint --format agent <files>`; phpstan clean on the touched module before each commit.
- Schema changes fold into create migrations (pre-stable). Five-driver portability (MySQL, MariaDB, PostgreSQL, Oracle, SQLite).
- Commits go to the repository that owns each file (`Modules/Core`, `Modules/CMS` submodules, `laraplate` for app-level files and submodule pointers), with explicit paths, never `git add -A`.
- Spec A1: every permission exists for `web` and `api`, same names; existing does not mean granted.
- Spec A2: every `/api` route runs on the `api` guard; checks use the request's guard on direct, role and ancestor-role paths; a permission missing for the guard is a 403, never an error.
- Spec A3: a bearer token present but invalid, expired or revoked is a 401, never a fallback; no token means `anonymous` with the `api` roles.
- Spec A4: setting `crud.expose_api` becomes `expose_api` (config `core.expose_api`, default `false`) and gates every `/api` route, Core and modules.
- Spec 5.1: superadmin token 401; address outside `allowed_cidrs` 403, logged without the token; rate limit `core.api.rate_limit_per_minute`, default 600, per token or per client address.
- Spec 9.1: abilities are `api` permission names, `*` refused; a request needs the role permission **and** the ability; personal tokens expire within `core.api_tokens.max_lifetime_days`, default 365.

## Rulings made in this plan (the spec is silent or wrong)

- **R1 Active guard.** The middleware calls `Auth::shouldUse('api')`; `AuthorizationService` reads the guard name from `Auth::getDefaultDriver()`. Session routes keep `web`.
- **R2 Anonymous user.** Resolved by `config('permission.users.guest')`, not the literal `'anonymous'`. On the `api` guard it is set with `Auth::guard('api')->setUser()` and the request user resolver (no `Auth::login`, the guard has no session). The middleware resolves it before the controller runs, so `getAclFilters()` and the `retrieved` hook always see a user on `/api`.
- **R3 Duplicate CRUD routes.** `Modules/Core/routes/api.php` stops requiring `crud.php`, which `registerApiRoutes()` already loads.
- **R4 Relation filter shape.** `RelationFilter(string $relation, FiltersGroup $filters, ?array $morph_types = null)` in `Modules/Core/app/Casts/`, accepted inside a `FiltersGroup` next to `Filter`, stored in the ACL `filters` JSON with a `relation` key. Applied with `whereHas` or, when `morph_types` is set, `whereHasMorph`. In search it is applied only at rehydration (`searchHitsQuery`), never pushed to the engine.
- **R5 Parts of a parent.** Contract `Modules/Core/app/Contracts/IsPartOfParent` with `parentRelation(): string`. A model implements it when it has a non-nullable foreign key to its parent with cascade delete and no Filament resource of its own. Task 4 marks every model matching that rule and lists them in its commit message.
- **R6 Forbidden relations.** An explicitly requested relation (by `relations`, dotted `columns`, relation filters or sorts) whose entity the caller may not select: `AuthorizationException`, answered 403. An appended relation-backed attribute (`cover`) the caller may not read: `null`.
- **R7 Partial relations meta.** Computed on `detail` only (one aggregate count per filtered relation), returned as `meta.relations.{name}.hidden` through a new `ResponseBuilder::setRelationsMeta(array $relations): self`. `list` and `search` do not carry it.
- **R8 Media projection hook.** `Media::toArray()` returns the projection when the active guard is `api` and the user lacks `vend_media.update` on it, so nested media (relations, `cover`) are covered. URLs come from `MediaResource`'s URL building. Spec 7.2 says private disks return signed URLs "as `MediaController` does today": it does not (`MediaResource::resolveUrl()` calls `getUrl()` only). Ruling: a disk whose driver supports temporary URLs returns `getTemporaryUrl()` with `core.media.signed_url_ttl_minutes` (default 60); a local non-public disk returns `null` URLs.
- **R9 Hidden attributes hook.** Trait `HidesAttributesFromAnonymous` implementing the contract `IHidesAttributesFromAnonymous::anonymousHiddenAttributes(): array`, applying `makeHidden()` in `toArray()` when the authenticated user `isGuest()`.
- **R10 Contributors' `shared_components`.** Task 7 reads what the CMS presets put there; if any key can hold contact data (email, phone, address, user reference) it joins the hidden list, otherwise it stays visible. The decision and its evidence go in the commit message and in the delivery status.
- **R11 Guest on `/app`.** With no `web` permission for `guest`, anonymous session reads answer 403. Info routes (`/app/about`, `/app/auth/user/profile-information` for guests) do not check permissions and keep working. Tests that relied on anonymous session reads move to `/api` with `CrudApiExposure::enable()`.
- **R12 Token routes.** `Modules/Core/app/Http/Controllers/ApiTokenController.php` under `Modules/Core/routes/auth.php`: `GET user/tokens` (`core.auth.tokens.list`), `POST user/tokens` (`core.auth.tokens.create`), `DELETE user/tokens/{token}` (`core.auth.tokens.revoke`).

## Review Focus

- **Staff screens after the relation rule**: an admin reading a content with its media and contributors through `/app` must keep seeing them; a default role that loses a relation it used is given the related permission in the seeder. Task 4 and Task 6.
- **Anonymous on `/app` after `guest` (`web`) is emptied**: info and login routes keep working, CRUD reads answer 403. Task 6.
- **A token whose ability matches a permission later revoked from the role**: denied at the next request (role permission AND ability, evaluated per request, not cached on the token). Task 2.
- **Search with a relation filter applied only at rehydration**: never leaks a hidden media; a page may hold fewer hits than requested and the total may count hidden ones. Task 3.
- **A content that expires while a shared anonymous response is cached**: the response cache is keyed per user, so all anonymous callers share it; it must not outlive the content's `valid_to`. Task 6 asserts the cache TTL for anonymous responses does not exceed the response cache's configured TTL and documents the window.

---

## File structure

| Path | Responsibility |
|---|---|
| `Modules/Core/app/Console/PermissionsRefreshCommand.php` | permissions for both guards |
| `Modules/Core/app/Models/User.php`, `Role.php` | guard-aware `hasPermission`; `HasApiTokens`; service account guard |
| `Modules/Core/app/Services/Authorization/AuthorizationService.php` | active guard, anonymous by config, relation filter, relation authorization entry points |
| `Modules/Core/app/Http/Middleware/AuthenticateApiRequest.php` (new) | switch, guard, token, anonymous, CIDR, rate limit |
| `Modules/Core/app/Casts/RelationFilter.php` (new) | relation condition inside ACL filters |
| `Modules/Core/app/Services/Crud/RelationAuthorizer.php` (new) | permission + ACL for related records, used by `QueryBuilder` and the search path |
| `Modules/Core/app/Contracts/IsPartOfParent.php`, `IHidesAttributesFromAnonymous.php` (new), `Models/Concerns/HidesAttributesFromAnonymous.php` (new) | parts and hidden attributes |
| `Modules/Core/app/Services/Crud/CrudService.php`, `QueryBuilder.php` | relation loading and scoped sync |
| `Modules/Core/app/Models/Media.php`, `Http/Resources/MediaResource.php` | safe projection |
| `Modules/Core/app/Http/Controllers/ApiTokenController.php` (new), `Http/Requests/CreateApiTokenRequest.php` (new) | personal tokens |
| `Modules/Core/database/seeders/CoreDatabaseSeeder.php`, `Modules/CMS/database/seeders/CMSDatabaseSeeder.php` | roles per guard, public list, ACLs |
| `Modules/CMS/app/Models/Content.php`, `Helpers/HasMultimedia.php` | references in search, `cover` |
| `database/migrations/2025_03_06_214617_create_personal_access_tokens_table.php` | `allowed_cidrs` |
| `Modules/Core/database/migrations/2024_03_30_150000_integrate_users_table.php` | `is_service_account` |

---

### Task 1: Permissions per guard

**Files:**
- Modify: `Modules/Core/app/Console/PermissionsRefreshCommand.php` (L225-228, L240-259, L268-283), `Modules/Core/app/Models/User.php` (`hasPermission` L343), `Modules/Core/app/Models/Role.php` (`hasPermission` L103), `Modules/Core/app/Services/Authorization/AuthorizationService.php` (`passesPermission` L310, `resolveUser` L464)
- Test: `Modules/Core/tests/Integration/Services/Authorization/GuardAwarePermissionsTest.php`, `Modules/Core/tests/Feature/Console/PermissionsRefreshCommandTest.php`

**Interfaces:**
- Produces: `User::hasPermission(string $permission, ?string $guard_name = null): bool` and `Role::hasPermission(string $permission, ?string $guard_name = null): bool`, both guard-aware, `null` meaning `Auth::getDefaultDriver()`; permissions exist as `(name, 'web')` and `(name, 'api')`.

- [x] **Step 1: Write the failing tests.** The command creates each permission twice, `guard_name` `web` and `api`, same name, idempotent on a second run. A role on `web` holding `default.cms_tags.select` passes on `web` and fails on `api`, through a direct grant, a role and an ancestor role; the reverse for an `api` role. Asking for a permission that does not exist on the guard returns `false` (no `PermissionDoesNotExist`). `AuthorizationService::checkPermission()` with `Auth::shouldUse('api')` evaluates the `api` grants. The anonymous user is found by `config('permission.users.guest')` set to another name.
- [x] **Step 2: Run** `php artisan test --compact Modules/Core/tests/Integration/Services/Authorization/GuardAwarePermissionsTest.php Modules/Core/tests/Feature/Console/PermissionsRefreshCommandTest.php`. Expected: FAIL.
- [x] **Step 3: Implement.** The command loops over `['web', 'api']` wherever it calls `firstOrCreate`. `Role::hasPermission` passes the guard to `hasPermissionTo` and to ancestors, considering only roles of that guard; `User::hasPermission` filters its roles by guard. Both catch `PermissionDoesNotExist` and return `false`. `passesPermission` uses `Auth::getDefaultDriver()`. `resolveUser` uses the config name and R2.
- [x] **Step 4: Run** the two files plus `Modules/Core/tests/Integration/Services/Authorization/AuthorizationServiceTest.php` and `Modules/Core/tests/Feature/Api/CrudApiTest.php`. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/Core`: `feat(core): permissions per guard and guard-aware checks`.

### Task 2: One authentication and one switch for `/api`

**Files:**
- Create: `Modules/Core/app/Http/Middleware/AuthenticateApiRequest.php`
- Modify: `Modules/Core/app/Providers/RouteServiceProvider.php` (`registerApiRoutes` L123), `Modules/Core/app/Overrides/RouteServiceProvider.php` (`mapApiRoutes` L49), `Modules/Core/routes/api.php` (R3), `Modules/Core/app/Providers/CoreServiceProvider.php` (L850, alias and rate limiter), `Modules/Core/app/Support/CrudApiExposure.php`, `Modules/Core/database/seeders/CoreDatabaseSeeder.php` (L97), `Modules/Core/config/config.php` (`api.rate_limit_per_minute`, `api_tokens.max_lifetime_days`), `Modules/Core/app/Models/User.php` (`HasApiTokens`), `database/migrations/2025_03_06_214617_create_personal_access_tokens_table.php` (`allowed_cidrs` json nullable)
- Delete: `Modules/Core/app/Http/Middleware/EnsureCrudApiAreEnabled.php` (folded in); rename its test to `Modules/Core/tests/Feature/Middleware/AuthenticateApiRequestTest.php`
- Modify tests that write `crud.expose_api` (list in the Explore report: Core `CrudApiTest`, `ModificationCrudWriteGuardTest`, `GraphExpandRouteTest`, `CrudApiExposureTest`, `DatabaseConfigOverlayTest`, `SettingsCacheCoordinatorTest`; CMS `CommentModerationTest`, `CmsGraphExpandTest`, `CmsGraphRuntimeBenchmarkTest`; ERP `CrudWriteGuardTest`; MES `WorkCenterCrudTest`) to the new name through `CrudApiExposure`.

**Interfaces:**
- Consumes: Task 1.
- Produces: middleware alias `api_access` applied first on every `/api` group; `CrudApiExposure` writes setting `expose_api`; `User` tokenable; rate limiter named `api`.

- [x] **Step 1: Write the failing tests** in `AuthenticateApiRequestTest`: switch off → 403 on a Core CRUD route and on a module route (`POST api/v1/mes/machine-data`, `POST api/v1/webhooks/{connection}`); switch on, no header → the request user is the anonymous user and `Auth::getDefaultDriver()` is `api`; malformed, unknown, expired and revoked tokens → 401 and never the anonymous user; a superadmin's token → 401; `allowed_cidrs` `["10.0.0.0/8"]` from `192.168.1.5` → 403 and the log line does not contain the token; a role permission without the ability → 403; an ability without the role permission → 403; both → 200; an ability revoked from the role after issuing → 403 on the next request; the 601st anonymous request in a minute from one address → 429; `route:list` holds each `core.api.*` CRUD route once; with the switch on, an anonymous `POST api/v1/webhooks/{connection}` without a valid signature is refused by the route (spec 5.3).
- [x] **Step 2: Run** the file. Expected: FAIL.
- [x] **Step 3: Implement** the middleware in the order of spec 5.1, the ability check in `AuthorizationService::passesPermission` when the user has a current access token (`$user->currentAccessToken()?->can($name)`, `*` never accepted), CIDR matching with `Symfony\Component\HttpFoundation\IpUtils::checkIp`, the rename (seeded setting `expose_api`, config `core.expose_api`), the column, R3.
- [x] **Step 4: Run** the file and every renamed test. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/Core` (`feat(core): one authentication and one switch for every api route`), in each touched module (`test: follow the renamed API switch`), and in `laraplate` (migration and pointers).

### Task 3: Relation filter in ACLs

**Files:**
- Create: `Modules/Core/app/Casts/RelationFilter.php`
- Modify: `Modules/Core/app/Casts/FiltersGroup.php` (accept `RelationFilter`), the cast that hydrates ACL `filters` JSON, `AuthorizationService::applyFiltersRecursively()` / `applySingleFilter()`, `CrudService::searchHitsQuery()` (L2558), `Modules/Core/app/Search/Services/ScoutSearchConstraintApplier.php` (skip `RelationFilter`)
- Test: `Modules/Core/tests/Integration/Services/Authorization/AclRelationFilterTest.php`

**Interfaces:**
- Produces: `new RelationFilter(relation: 'model', filters: FiltersGroup, morph_types: [Content::class])`; JSON `{"relation":"model","morph_types":[...],"filters":{...}}`.

- [x] **Step 1: Write the failing tests:** an ACL with a `RelationFilter` round-trips through the database; on a `list` it keeps only rows whose related record matches, for a `BelongsTo` (`ContentReference` → `content`) and a `MorphTo` (`Media` → `model` with `morph_types`); nested `@now` validity is resolved; in `search` the engine query carries no relation condition and rehydration drops non-matching hits; a role with a content ACL and no `vend_media` permission gets contents and a 403 on `select/core/media`.
- [x] **Step 2: Run** the file. Expected: FAIL.
- [x] **Step 3: Implement** R4.
- [x] **Step 4: Run** the file, `Modules/Core/tests/Integration/Services/AclRoleScopedTest.php` and `Modules/Core/tests/Integration/Services/CrudSearchRehydrationAclTest.php`. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/Core`: `feat(core): relation filters in ACLs`.

### Task 4: Related records in reading

**Files:**
- Create: `Modules/Core/app/Services/Crud/RelationAuthorizer.php`, `Modules/Core/app/Contracts/IsPartOfParent.php`
- Modify: `Modules/Core/app/Services/Crud/QueryBuilder.php` (`applyRelations` L832, `createRelationCallback` L781, `cleanRelations` L388), `Modules/Core/app/Services/Crud/CrudService.php` (`list` L124, `detail` L508, `searchWithScout` L2391 and the orchestrated path: relations through `QueryBuilder`, not raw `with()`), `Modules/Core/app/Helpers/ResponseBuilder.php` (`setRelationsMeta`), `Modules/CMS/app/Helpers/HasMultimedia.php` (`cover` returns `null` when unreadable), the models matching R5
- Test: `Modules/Core/tests/Integration/Services/Crud/RelationAuthorizationTest.php`

**Interfaces:**
- Consumes: Tasks 1 and 3.
- Produces: `RelationAuthorizer::authorize(Request $request, Relation $relation): void` (throws `AuthorizationException`, applies the related ACL to the relation query, no-op for `IsPartOfParent`); `RelationAuthorizer::hiddenCount(Request $request, Model $parent, string $relation): int`; `ResponseBuilder::setRelationsMeta(array<string, array{hidden: int}> $relations): self`.

- [x] **Step 1: Write the failing tests:** a user with `cms_contents.select` and no `users.select` asking `relations=contributors.user` → 403, and with `columns[]=user.email` → 403; with `users.select` and an ACL limiting users, the loaded relation holds only the allowed rows, in `list`, `detail` and `search`; a part of the parent (a content translation model) loads without its own permission; search honours the relation black list (`relations=history` is dropped); `detail` returns `meta.relations.media.hidden` equal to the number of filtered media; `cover` is `null` for a caller without `vend_media.select`; an admin of the seeded roles reading a content with media and contributors through `/app` sees them.
- [x] **Step 2: Run** the file. Expected: FAIL.
- [x] **Step 3: Implement** R5, R6, R7; mark the R5 models.
- [x] **Step 4: Run** the file and the Core and CMS `Feature/Api`, `Feature/Controllers` and `Integration/Services` folders. Expected: PASS; a default role that lost a relation gets the related permission in its seeder in this task.
- [x] **Step 5: Commit** in `Modules/Core` and each module whose models implement the contract: `feat(core): related records obey their own permission and ACL`.

### Task 5: Scoped sync in writing

**Files:**
- Modify: `Modules/Core/app/Services/Crud/CrudService.php` (`resolveSyncableRelations` L1718, `syncModelRelations` L1751)
- Test: `Modules/Core/tests/Integration/Services/Crud/ScopedRelationSyncTest.php`

**Interfaces:**
- Consumes: `RelationAuthorizer` (Task 4).

- [x] **Step 1: Write the failing tests:** a record with related ids {1, 2, 3} where the writer's ACL hides 3; updating with {1} detaches 2 and keeps 3; updating with {1, 2, 4} where 4 is hidden → 403 and nothing changes; a `HasMany` relation behaves the same; a part of the parent syncs as today.
- [x] **Step 2: Run** the file. Expected: FAIL.
- [x] **Step 3: Implement:** compute the visible subset of current related ids with the related ACL, diff the submitted ids against it, refuse submitted ids outside the readable set.
- [x] **Step 4: Run** the file and the Core `Feature/Api` folder. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/Core`: `feat(core): relation sync works only within what the writer can read`.

### Task 6: Default roles and public ACLs

**Files:**
- Modify: `Modules/Core/database/seeders/CoreDatabaseSeeder.php` (`defaultRoles` L146-225 keyed by `(name, guard)`, `defaultSettingAcls` L231 on the `api` guest, `defaultUsers` L284 giving the anonymous user both `guest` roles), `Modules/CMS/database/seeders/CMSDatabaseSeeder.php` (`defaultContentAcls` L327 on the `api` guest; new ACLs for `cms_contents_references` and `vend_media` with `RelationFilter` and the content validity group of L327-393; grants of `cms_tags`, `cms_locations`, `cms_contributors`, `cms_comments`, `cms_contents_ratings`)
- Test: `Modules/Core/tests/Feature/Database/GuestRoleGrantsTest.php`, `Modules/CMS/tests/Feature/Seeders/CMSDatabaseSeederTest.php`, `Modules/CMS/tests/Feature/Controllers/ContentGuestVisibilityAclTest.php` (moved to `/api`, R11)

- [x] **Step 1: Write the failing tests:** `guest` (`web`) holds no permission; `guest` (`api`) holds exactly `select` on `core_settings`, `cms_contents`, `cms_contents_references`, `vend_media`, `cms_tags`, `cms_locations`, `cms_contributors`, `cms_comments`, `cms_contents_ratings` (guard test: any difference fails); `admin`, `publisher`, `system` hold no `api` permission; anonymously through `/api` an unpublished, expired and not-yet-valid content, its references and media are invisible in select, detail, search and as relations, a SAO ticket attachment is invisible, a published one is visible; anonymous `GET /app/crud/select/cms/contents` → 403 and `GET /app/about` → 200; the anonymous response cache TTL does not exceed the response cache's configured TTL.
- [x] **Step 2: Run** the files. Expected: FAIL.
- [x] **Step 3: Implement** the seeders. Spec A10 makes media readable through select: if `Media` is excluded from the generic CRUD (`PermissionsRefreshCommand::$MODELS_BLACKLIST` or the entity resolver), include it, so `GET api/v1/select/core/media` answers with the ACL applied.
- [x] **Step 4: Run** the files, `Modules/Core/tests/Feature/Database/CoreDatabaseSeederTest.php`, `Modules/Core/tests/Feature/Media/MediaOwnerVisibilityTest.php`. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/Core` and `Modules/CMS`: `feat: guest is empty on web and holds the public list on api`.

### Task 7: Hidden attributes and the media projection

**Files:**
- Create: `Modules/Core/app/Contracts/IHidesAttributesFromAnonymous.php`, `Modules/Core/app/Models/Concerns/HidesAttributesFromAnonymous.php`
- Modify: `Modules/CMS/app/Models/Contributor.php`, `Comment.php`, the ratings model (`user_id`, and `shared_components` per R10), `Modules/Core/app/Models/Media.php` (`toArray`, R8), `Modules/Core/app/Http/Resources/MediaResource.php` (URL building shared, temporary URLs), `Modules/Core/config/config.php` (`media.signed_url_ttl_minutes`)
- Test: `Modules/Core/tests/Feature/Media/MediaProjectionTest.php`, `Modules/CMS/tests/Feature/Controllers/AnonymousHiddenAttributesTest.php`

- [x] **Step 1: Write the failing tests:** anonymous through `/api`: contributors, comments and ratings come without `user_id` (and `shared_components` if R10 says so), staff through `/app` gets them; a media through `/api` (as entity, as relation and as `cover`) has `url`, `conversions`, `mime_type`, `size`, collection and alternative text and no `custom_properties`, `disk`, `conversions_disk`, `file_name`; an `api` caller with `vend_media.update` gets the full record; `/app` is unchanged; a temporary-URL disk returns a signed URL valid for 60 minutes; a local private disk returns `null` URLs.
- [x] **Step 2: Run** the files. Expected: FAIL.
- [x] **Step 3: Implement** R8, R9, R10.
- [x] **Step 4: Run** the files and the Core `Feature/Media` folder. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/Core` and `Modules/CMS`: `feat: hide attributes from anonymous callers and project media on the api guard`.

### Task 8: Personal tokens and service accounts

**Files:**
- Create: `Modules/Core/app/Http/Controllers/ApiTokenController.php`, `Modules/Core/app/Http/Requests/CreateApiTokenRequest.php`
- Modify: `Modules/Core/routes/auth.php` (R12), `Modules/Core/database/migrations/2024_03_30_150000_integrate_users_table.php` (`is_service_account` boolean default false), `Modules/Core/app/Models/User.php` (`canAccessPanel` false for service accounts), `Modules/Core/app/Auth/Services/AuthenticationService.php` (refuse service accounts), `Modules/Core/app/Filament/Resources/Users/UserResource.php` (service account toggle; "Issue token" action with abilities and optional expiry; tokens table with `last_used_at` and revoke)
- Test: `Modules/Core/tests/Feature/Auth/ApiTokenControllerTest.php`, `Modules/Core/tests/Feature/Auth/ServiceAccountTest.php`

- [x] **Step 1: Write the failing tests:** a user creates a token with abilities among the `api` permissions of its roles and receives the plain token once; an ability outside them, or `*` → 422; an expiry beyond 365 days or none → 422 for a personal token; a superadmin cannot create; list shows name, abilities, `last_used_at`, expiry and never the token; revoke makes the next `/api` call 401; a service account cannot log in through Fortify nor open the panel, and its admin-issued non-expiring token authenticates on `/api`.
- [x] **Step 2: Run** the files. Expected: FAIL.
- [x] **Step 3: Implement** the controller, request, routes, migration column, login and panel refusal, Filament surfaces.
- [x] **Step 4: Run** the files and the Core `Feature/Auth` folder. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/Core`: `feat(core): personal API tokens and service accounts`.

### Task 9: References in the content search document

**Files:**
- Modify: `Modules/CMS/app/Models/Content.php` (`toSearchableArray` L374-445: `references` with labels and URL hosts; the search mapping of the content index if fields are declared there)
- Test: `Modules/CMS/tests/Feature/Search/ContentReferencesSearchTest.php`

- [x] **Step 1: Write the failing test:** a published content with a reference labelled "Nature" at `https://www.nature.com/x` is found by searching `nature.com` and `Nature`; an unpublished one is not, anonymously.
- [x] **Step 2: Run** it. Expected: FAIL.
- [x] **Step 3: Implement** the document fields (labels, hosts without `www.`).
- [x] **Step 4: Run** it and the CMS `Feature/Search` folder. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/CMS`: `feat(cms): index content references in the content document`; the module docs say existing indexes need a rebuild.

### Task 10: Documentation and closing

**Files:**
- Modify: `Modules/Core/README.md` (upgrade note: rename of the switch and rebuild with `migrate:fresh --seed`; env and config keys), `Modules/Core/docs/CRUD_SYSTEM.md` (guards, middleware, relation rules, meta, projection, tokens), Core `docs/rag` access-control entry, `Modules/CMS/README.md` (public list, reindex), ERP, SAO and MES READMEs (API switch required for callbacks, webhooks, machine data), this plan, `docs/superpowers/plans/INDEX.md`, `docs/superpowers/plans/2026-06-30-erp-hardening-spec2-phase3-remaining.md` (Task 11 marked `- [-]` superseded by this plan)

- [x] **Step 1: Write** the documentation.
- [x] **Step 2: Run** `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php` after adding `## Delivery status (<date>)` with `**Documented in:**` to this plan. Expected: PASS.
- [x] **Step 3: Ask the user** to run the full suite with `php artisan test --compact`. (To be asked by the controller in its closing report; not run by the documentation task, the suite result is the user's.)
- [x] **Step 4: Commit** in each repository: `docs: API access control`.

## Delivery status (2026-10-09): shipped

All ten tasks are built. The MES machine-route migration to service accounts (spec section 10) is not built, as the spec says: it belongs to a MES plan that follows this one, and until then the MES ingest route is self-authenticated. Per-entity API exposure (ERP hardening Task 2) and the ERP version header (Task 11) stay out. The ERP hardening plan keeps its own closing decision: only its Task 11 is marked `- [-]` (superseded here). `laraplate-ui` declares no `method` column, so the refusal of client `method` columns breaks no known client. Divergences from the plan and the spec, each with its reason:

- **R9 hidden attributes.** The plan said `makeHidden()` in `toArray()`; the trait filters in `getArrayableItems()` instead, because `makeHidden()` changes the instance for good and a model serialized for the anonymous user and then for staff would stay stripped.
- **Spec 5.3, callers without a token.** The spec assumed providers send no token; the ERP provider callbacks and the MES machine ingest send their own bearer secret. Routes marked `SelfAuthenticatedApiRoute` keep the switch, the `api` guard and the rate limit, do not read the bearer as a Laraplate token, run as the anonymous user, and rely on their own check (the MES one until its service-account migration).
- **R11 and A2 status codes.** The plan said anonymous `/app` reads answer 403, and A2/9.1 ask 403 for denied permissions and abilities; an `AuthorizationException` shipped as 401 for the anonymous user and 403 for any authenticated principal (RFC semantics, and the SPA re-authentication prompt keys on 401). An explicitly requested forbidden relation (R6) follows the same rule.
- **Spec 5.1, failed authentications.** Added: failed bearer authentications (401, CIDR 403) are counted per client address and an address over `core.api.rate_limit_per_minute` gets 429 before the token is looked up; unthrottled 401s would allow token guessing. Successful requests do not consume that budget, so valid tokens behind one NAT are not throttled by it.
- **A3, principal of a bearer request.** With a bearer present the principal comes from the token only: the Sanctum web-session fallthrough is switched off while the token is resolved. The fallthrough stays in place for a request with no bearer where the anonymous account does not exist (production has no session on `/api`; it only keeps `actingAs` tests working).
- **MES ingest shares the per-address budget.** Being under `AuthenticateApiRequest`, the MES ingest also counts against the `api` limiter (600 a minute per address) on top of `throttle:mes-machine-ingest`; gateways behind one address share it. Documented in the MES docs.
- **Spec 7.2 and R8, signed URLs.** The spec said private disks return signed URLs "as `MediaController` does today"; it does not. `Media\MediaUrls::safeUrl()` returns the plain URL for a public disk, a temporary URL (`core.media.signed_url_ttl_minutes`) for a disk that signs, and `null` otherwise; `MediaResource` shares the URL building.
- **Media projection fields.** The projection reads the pixel dimensions from `custom_properties.metadata.width/height` and the alternative text from `custom_properties.alt_text`; neither the raw properties nor the disks nor the file name are returned.
- **R10 `shared_components`.** The contributors' `shared_components` holds the public email, phone and social URLs, so it joins `user_id` and `user` in the hidden list.
- **Spec 6, publisher grants.** The spec kept `publisher` on "current web permissions"; with related records obeying their own permission it gained `core_settings.select` (narrowed to public settings by an ACL) and `core_entities.select` (narrowed to the CMS entity types), and its shared-table ACLs for categories, presets and entities became relation filters.
- **Spec 8.1, scope of the rule.** The rule was written for select, detail and search; it also covers facets, group-by keys, aggregates, tree and history reads (the root entity's ACL applies to the nodes and versions), because A9 is one visibility rule for every read.
- **Entities outside the permission scheme.** Spec silent. An entity with no registered `select` permission is readable only if its class is in `PermissionsRefreshCommand`'s deliberate blacklist; any other fails closed (fail-open by omission exposed Sanctum tokens, which are now never readable). Models without generated permissions (modifications, versions) are unchecked, translations inherit their translated model, and the `retrieved` hook checks the api guard too.
- **Pending modifications.** Spec silent. The `modifications` relation (proposed, unapproved values) needs the parent's `update` or `approve` permission on the request's guard: omitted when appended or default, 403/401 when requested.
- **History.** The spec had history under the entity's `select`; it needs `update` or `approve` as well (versions hold pre-publication drafts), and its `record` is serialized through `toArray()` with no nested `versionable` copy (an API shape change).
- **Relation names and computed methods.** The plan checked relation names only in ACL filters; every relation name of a request (relations, dotted columns, filters, sorts, group-by) goes through `RelationGuard`, which needs a declared `Relation` return type (relation methods without one were typed, module by module, instead of relaxing the guard; `Notification::notifiable` stays untyped, so `relations=notifiable` answers 400). A `method` column may call only a method declared in `crudComputedDependencies()`, because a request could call `delete` on every returned row; no production model declares one, so every client `method` column is refused.
- **ACL cast fails closed.** Spec silent. `FiltersGroupCast` refuses undecodable or scalar JSON, unknown keys per node kind, a relation node whose filters are not a group holding a condition, and a stored group that constrains nothing unless the ACL is `unrestricted` (a `NULL` or empty column still means no filters): an ACL must never widen because of a shape it cannot read. The strict associative check is applied at ACL save, not in `ListRequest`.
- **Scoped sync.** The plan's Task 5 tests listed a `HasMany` relation; the generic sync stays many-to-many only (its existing whitelist rule), so a `HasMany` is still refused as before. Hidden records stay attached, and submitting an unreadable id is 403.
- **Cover.** The `cover` appended attribute was never actually appended (the trait initializer was misspelled, so Laravel never ran it). It now reads a `coverMedia` relation that the CRUD read preloads once per result set under the media permission and ACL, through the new contract `IPreloadsAppendedRelations` (not in the plan), instead of one query per row.
- **Token abilities.** An ability may be any `api` permission the user holds, direct grants included, not only role-granted ones, so the issue-time check matches the use-time check (`hasPermission`). Token expiry is stored in the application timezone, and a service account is never taken for the anonymous user unless it carries the guest name.
- **Anonymous response cache.** Anonymous responses share one cache entry per entity that uses the response cache; the stale window equals the configured TTL (`cache.duration.medium`) and no cache busting on validity changes is built. CMS contents do not use the response cache; the settings do.
- **Upgrade (A8, R11).** No migration: an existing installation is rebuilt with `migrate:fresh --seed`. Re-seeding never revokes the old `guest` (`web`) grants (roles get their permissions at creation only), and the anonymous user is cached for good, so it may stay role-less until the cache is flushed.
- **Task 9.** The reference observer that re-sends a content when one of its references changes is an addition to the plan, and the `references` field is declared in the content mapping.
- **Task 2 tests.** Only tests that wrote the key `crud.expose_api` literally were renamed; those calling `CrudApiExposure::enable()` keep working, since its methods stayed.

Final review fixes (2026-10-09), one wave after the whole-plan review:

- **Readable columns.** Facets, group-by keys, sorts, filter properties and aggregated columns read values through the query, outside the serialization: a facet on `comments.user_id`, `contributors.shared_components` or a media `file_name` answered the anonymous user what its serialization withholds. `Support\ReadableColumnGuard` now refuses (`400`, before any query) a column hidden from the anonymous user, or a media column outside the projection's queryable columns (`id`, `collection_name`, `mime_type`, `size`) for a projected caller, on every model along a dotted path. The ACL filters stay exempt.
- **Outside the permission scheme, narrowed.** Of the classes deliberately without permissions, only a pivot reached through a plain relation (`presettable`) and a translation loaded from the model it translates are read as relations; versions, licenses and dynamic entities are read as relations by a superadmin only, model embeddings are refused like tokens, and `embeddings` joined the relation black list (it returned vectors, model keys and content hashes to the anonymous user).
- **Server errors.** `ResponseBuilder` answers the status text instead of the message of a `5xx` when `app.debug` is off (a failed query returned its SQL and bindings), for every controller built on it; the exception is still reported. A `501` loses its message too.
- **Token abilities on model paths.** The per-row select check, the write checks, the write-time approval credit and the pending-deletion hide scope asked the Gate, which ignores the request's guard and the token: a token without the `approve` ability gave its owner an approval credit. They ask `AuthorizationService::userHolds()` now, the check every CRUD path uses.
- **Response cache key.** The key carries the personal access token, so two tokens of one user with different abilities no longer share a cached response.
- **Unresolvable default eager loads** (a name that is not a typed relation, a path through a morph relation) are dropped instead of loaded unguarded. No production model declares one.
- **Anonymous `/app` calls** set the anonymous user on the guard for the request only, never signing it in to the web session, which let the next request of the same browser write the shared anonymous row through the `auth` group (preferences, first-login completion).
- **Graph nodes** build their label and summary only from attributes the model's serialization returns to the caller (no `$hidden`, no attribute hidden from the anonymous user, within the media projection: a media label was its original file name).
- **Version contents** mask every attribute in the versioned model's `$hidden` (argon hashes, remember tokens, two-factor secrets of a versioned user, not only bcrypt hashes) and, for the anonymous user, its anonymous-hidden attributes. The storage columns of dynamic contents (`components`, `shared_components`, `entity_id`, `presettable_id`), hidden only because the model's serialization flattens them, stay visible to editors (follow-up fix: the first version of this fix hid the history of every dynamic field).
- **Parts on hydration** (follow-up fix of a regression of this wave). The per-record select check of `HasValidations` asks, for a part of its parent, the select permission and token ability of the first owner up its parent chain that is not a part: with the token-aware check, a token carrying only the content ability got 403 on `relations[]=references` (and on invoice lines, ratings, AI messages), and a role without a part's own permission already did. Writes keep the part's own permission.
- **Parts on hydration, own endpoint** (second follow-up fix). Asking only the owner's select broke a part's own detail, update and delete for a caller holding the part's permissions without the owner's (`detail/cms/content_reference` answered 403). A part now passes hydration with its own `select` or its first non-part owner's, both through `userHolds()`; an owner without a registered permission, or a chain that does not resolve, leaves only the part's own permission (fail closed instead of unchecked). The owner is resolved once per class (`Authorization\PartOwnerResolver`), the refusal names both tables, and the docs now cover translations and the dynamic-contents exception of version masking. The ERP invoice-lines hydration test had passed fail-open (the invoice was created before its permissions, so the memoized lookup read it as unregistered); it now creates the permissions first.
- **Parts on hydration, along the chain** (third follow-up fix). A part loaded from an intermediate part was let through by `RelationAuthorizer` but refused on hydration, which accepted only the part's own select or the top owner's: a role holding only `shop_product_variants.select` got 403 on `detail/shop/product_variant?relations[]=items`, and MES `ProductionOrderOperation::quantityAudits()` had the same shape. A part now hydrates with the select of any model along its chain (its own, every intermediate part, the first non-part owner), each only if registered, stopping at the first held; `Authorization\PartOwnerResolver::chainClassesOf()` memoizes the class list per part class, and the refusal names every table checked. An unresolved chain still leaves only the part's own permission; writes and translations are unchanged. The CMS fallback test now flushes the permission-existence memo and asserts the exact message (it had passed with the owner branch still active), and the comments of `PermissionExistenceMemo` now say it is process-scoped: queue workers must be restarted after a deploy that registers permissions.

**Documented in:** `Modules/Core/docs/rag/API_ACCESS_CONTROL.md`, `Modules/Core/docs/CRUD_SYSTEM.md`, `Modules/Core/docs/ACL_SYSTEM.md`, `Modules/Core/docs/rag/MODULE.md`, `Modules/CMS/docs/rag/MODULE.md`, `Modules/MES/docs/MACHINE_CONNECTIVITY.md`, `Modules/MES/docs/rag/MODULE.md`, `Modules/ERP/docs/rag/MODULE.md` and the READMEs of Core (upgrade note, configuration keys), CMS, ERP, SAO and MES.
