# Core: who a version is attributed to

**Date:** 2026-10-22  
**Status:** Draft. Implementation is blocked until `docs/superpowers/plans/2026-10-20-core-versioning-rebuild.md` is complete.  
**Module:** `Modules/Core`  
**First consumer:** Shop auctions, where support stops a customer's auto-bid from Filament and the version must name the person who did it.

## Goal

A version row names the user who made the change: the one an operation declares, else the one in the current session, else the system service account when nobody is signed in. No model needs a column that exists only to carry the author.

## What the code does today

Verified on 2026-10-22 against `Modules/Core`, before the rebuild changes these files.

- `HasVersions::getVersionUserId()` returns the value of the model attribute named by `getUserForeignKeyName()`, `user_id` by default. A model without that attribute gets `null`. The method refuses to fall back to `auth()->id()` on purpose: a version written by a queue worker or a seeder has no user behind it.
- `VersionChange::forModel()` takes `userId ?? resolveUserId($model)`. `HasVersionedRelations` calls `getVersionUserId()` for the actor of membership rows.
- `VersionWriter` writes the version row's user from the change even when a version set is open. The set's own `actor` (`VersionSetOptions::$actor`) is stored on the set only. Version rows do not inherit it.
- `getUserForeignKeyName()` names both the model attribute that is read and the column of the versions table.
- No versioned model overrides `getUserForeignKeyName()`. 18 files in Core and the modules reference `HasVersions`.
- A system service account exists: `config('permission.users.system')`, username `system`, flagged `is_service_account` by `CoreDatabaseSeeder::ensureSystemServiceAccount()`.

Consequences:

1. A model whose own `user_id` means something else (owner, customer, assignee) has its versions attributed to that person, whoever made the change.
2. A system action running inside a user's request cannot be attributed to the system. The only available author is the model attribute.
3. A change made with no session is attributed to nobody, not to the system.

## Decision

One resolver decides the author of a version, in this order:

1. The explicit actor of the active version set (`VersionSetOptions::$actor`). Nested sets of other roots take the actor of the outer set, as the rebuild's version-set manager already decides.
2. The authenticated user of the current request.
3. The system service account, when there is no session: queue, console, scheduler.
4. `null`, when the service account is not seeded, as on a bare test database.

The explicit actor comes before the session so that a system action inside a request is attributed to the system and not to whoever triggered it. Example: in an auction, a customer's bid makes other customers' auto-bids fire inside that customer's request. The service opens the set with the system as actor for those, and with the signed-in customer for the manual bid.

The version row's user and the set's `actor` carry the same resolved value.

## Open points for the plan

The rebuild rewrites `HasVersions`, `VersionWriter`, `VersionSetManager` and `ActiveVersionSet`, so the plan settles these after it lands:

- Whether the model attribute stays as an opt-in, a model declaring which attribute holds the editor, or goes. This needs the audit of the 18 files: which have a `user_id` attribute with another meaning.
- Splitting the two jobs of `getUserForeignKeyName()`: the model attribute and the table column.
- How the system account id is resolved and cached for the request, and what a test sees when the account is absent.
- Impersonation: whether the session user is the impersonator or the impersonated. Not verified.

## Non-goals

- No change to what a version stores besides its author.
- No backfill. The rebuild discards the existing history (its decision D12), so older rows are not rewritten.

## Tests required

- An explicit actor beats the session. The session beats the system account. With nobody signed in the system account is used. Without the account the author is `null`.
- A system actor declared on the set is kept inside a request that has a signed-in user.
- Membership rows from `HasVersionedRelations` follow the same resolver.
- The set's `actor` and its version rows' user agree.
- A model whose `user_id` has another meaning is not attributed to that user, once the audit has settled the first open point.

## Dependencies

- **Blocked by:** `docs/superpowers/plans/2026-10-20-core-versioning-rebuild.md`, every slice.
- **Plan:** `docs/superpowers/plans/2026-10-22-core-version-actor-resolution.md`.
- **Consumer:** the Shop auction design, not written yet. It does not wait for this change: its configuration row carries `closed_by_user_id` itself and the model overrides `getVersionUserId()` until this lands, then drops the override.
