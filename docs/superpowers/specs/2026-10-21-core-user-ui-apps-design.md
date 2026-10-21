# Core: the UI apps a user can open

**Date:** 2026-10-21  
**Status:** Draft  
**Consumer:** `laraplate-ui` workspace overview (`docs/superpowers/specs/2026-10-21-workspace-overview-design.md` at the stack root)

## Goal

After login, the SPA needs the list of UI apps the user may open, to show them in the overview and let the user
switch app. The backend decides, from the `core_modules` table (which already says which modules are active and where
each frontend app lives) and from the same rule that gates a scoped login (`AuthorizationService::userHasModuleAccess`).

## Contract

The user data returned after login (`GET /app/auth/user/profile-information`, `UserInfoResponse`) gets one new field:

```json
"apps": [
  { "slug": "cms", "name": "CMS", "logoMark": "C", "url": "https://cms.example.com" }
]
```

- `apps` lists the modules that are **active** (`core_modules.is_active`), have a **frontend address** (`core_modules.url`
  not empty) and for which the user has module access, ordered by module name. A super admin has access to every
  active module that has an address (as `userHasModuleAccess` already says). An anonymous caller gets `[]`.
- `slug` is the module name lowercased (the same value the SPA sends as login `scope`), `name` is `modules.name`,
  `logoMark` its first letter, `url` the stored address.
- Nothing else about the user's permissions is exposed; the entries come from the table, never from request input.

## Source of truth

The `Modules\Core\Models\Module` model (table `core_modules`: `name`, `is_active`, `url`). The address is set per
deployment through the panel or the seeding that already fills the table; no configuration key and no environment
variable are added. The model's cached status reads (`ModuleDatabaseActivator`) are not used here: the field needs
`url`, so it reads the table directly, once per request.

## Implementation notes

- `AuthorizationService::accessibleModules(User $user): Collection` returns the distinct `module_name` values the user
  holds, directly or through roles, in two queries (not one per app); `userHasModuleAccess` keeps its signature and
  behavior.
- `UserInfoResponse` reads `Module::query()->where('is_active', true)->whereNotNull('url')`, drops empty
  addresses, keeps the modules whose lowercased name is in `accessibleModules` (every one for a super admin), and
  maps to `slug`, `name`, `logoMark`, `url`. The query count is fixed whatever the number of modules.
- `GetUserInfoAction` is untouched.

## Tests (Pest, feature)

- a user with a permission on the CMS module sees only `cms`;
- a user with permissions on two modules sees both, ordered by name;
- a super admin sees every active module that has an address;
- a user with no module permission gets `[]`;
- an inactive module, a module with no `url` and the Core module (no address) are not listed;
- a guest gets `[]`;
- `logoMark` is the first letter of the name;
- the number of queries does not depend on the number of modules.

## Cross-repo

A backend API change also updates `laraplate-ui` core (`UserInfo.apps` type, `AppEntry`) and `docs/compatibility.md`
at the stack root. The apps share the domain, so the session is shared.
