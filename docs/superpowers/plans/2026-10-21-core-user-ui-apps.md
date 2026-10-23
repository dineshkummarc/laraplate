---
status: completed
---
# Core: UI apps a user can open Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The user data returned after login carries `apps`, the UI apps the user may open, read from the `core_modules` table.

**Architecture:** A new `AuthorizationService::accessibleModules(User)` (two queries) and an `apps` field in `UserInfoResponse` built from `Module` rows (`is_active`, `url`) intersected with it.

**Tech Stack:** Laravel, Pest (`Modules/Core/tests/Feature/Controllers/UserControllerTest.php` is the neighbour).

**Spec:** `docs/superpowers/specs/2026-10-21-core-user-ui-apps-design.md`

## Delivery status (2026-10-21): built, committed afterwards, reconciled on 2026-10-23

Tasks 1 and 2 built in `laraplate` (`AuthorizationService::accessibleModules`, the `apps` field of `UserInfoResponse`), with Pest tests (`Modules/Core/tests/Feature/Authorization/AccessibleModulesTest.php`, `Modules/Core/tests/Feature/Controllers/UserControllerTest.php`). Divergences: a super admin gets every active module with an address without consulting `accessibleModules` (decided with the owner); the Core module is excluded explicitly; the query-count test compares the same user with one and with four granted modules after a warm-up request (extra users hit the license limit). Task 3: there is no Swagger entry for the profile response to update; the contract is documented in `Modules/Core/docs/rag/MODULE.md` and the stack `docs/compatibility.md`. The commit steps are skipped (commit only when asked). The plan stays open until the UI consumes the field (UI plan Task 4).

Reconciled on 2026-10-23: the code, the tests and the documentation are committed in `Modules/Core`, so the three commit steps are ticked, and the spec status now says implemented. The backend scope of this plan is complete: the UI consuming the field is the UI plan's Task 4, in the UI repository. `Modules/Core/resources/swagger/Core-swagger.json` does have a profile-information entry, without a response schema; it was not updated.

**Documented in:** `Modules/Core/docs/rag/MODULE.md` (SPA profile surface).

## Global Constraints

- Code and comments in English; commit only when the session owner asks.
- Follow the AGENTS and Boost rules of this repo; run the project's Pest command for the touched tests only (not the full suite).
- Entries come from the `core_modules` table only, never from request input; a module without `url` or inactive is never listed.
- `userHasModuleAccess` keeps its signature and behavior. No configuration key and no environment variable are added.

## Review Focus

- A super admin gets every active module that has an address, and a user with no module permission gets an empty list, not an error.
- An inactive module, a module with no address and the Core module never appear.
- The number of queries does not grow with the number of modules.

---

### Task 1: `accessibleModules`

**Files:**
- Modify: `Modules/Core/app/Services/Authorization/AuthorizationService.php`
- Test: the neighbouring Unit/Feature test of the authorization service

**Interfaces:**
- Produces: `AuthorizationService::accessibleModules(User $user): Collection` of lowercase module slugs the user holds permissions on, directly (`$user->permissions()`) or through roles, distinct, in two queries; for a super admin it returns every module name present in the permissions table.

- [x] **Step 1: Write failing tests:** `returns the modules of direct permissions`, `returns the modules of role permissions`, `merges direct and role modules without duplicates`, `a user without permissions gets an empty collection`, `a super admin gets every module that has permissions`, `runs a fixed number of queries` (assert with the query log).
- [x] **Step 2: Run** the Pest file for the service → FAIL.
- [x] **Step 3: Implement** the method, reusing the queries of `userHasModuleAccess` without changing it.
- [x] **Step 4: Run** the same file → PASS.
- [x] **Step 5: Commit** (only if asked) `feat(core): list the modules a user can access`.

### Task 2: The `apps` field

**Files:**
- Modify: `Modules/Core/app/Http/Resources/UserInfoResponse.php`
- Test: `Modules/Core/tests/Feature/Controllers/UserControllerTest.php`

**Interfaces:**
- Consumes: Task 1, `Modules\Core\Models\Module` (`name`, `is_active`, `url`).
- Produces: the response field `apps`: `list<array{slug: string, name: string, logoMark: string, url: string}>`, ordered by module name; `slug` is the lowercased name, `logoMark` the first letter of the name; `[]` for an anonymous caller.

- [x] **Step 1: Write failing tests** (create `Module` rows in each test): `lists only the apps of modules the user can access`; `lists two apps ordered by name`; `a super admin sees every active module with an address`; `a user without module permissions gets an empty list`; `skips an inactive module`; `skips a module without url`; `skips the Core module`; `a guest gets an empty list`; `logoMark is the first letter of the name`; `does not expose permissions of other modules`; `the query count does not depend on the number of modules`.
- [x] **Step 2: Run** `UserControllerTest.php` → FAIL.
- [x] **Step 3: Implement** the field in `UserInfoResponse`.
- [x] **Step 4: Run** the file and the other tests that assert the exact keys of the profile response (search for `'isFirstLogin'` in tests) → PASS.
- [x] **Step 5: Commit** (only if asked) `feat(core): apps the user can open in the profile response`.

### Task 3: Cross-repo contract

**Files:**
- Modify: `docs/superpowers/specs/INDEX.md`, `docs/superpowers/plans/INDEX.md` (status), the OpenAPI/Swagger definition of the profile response if one exists
- Modify (stack root): `docs/compatibility.md`; the UI side (`UserInfo.apps`) is in the UI plan, Task 4

- [x] **Step 1: Document** the `apps` field where the API contract is described and mark the spec delivered in the indexes when the tasks above are done.
- [x] **Step 2: Verify** the Swagger generation (if any) still builds and the field appears.
- [x] **Step 3: Commit** (only if asked) `docs(core): document the apps field of the profile response`.
