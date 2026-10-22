# Shop auctions: design

**Date:** 2026-10-22
**Module:** `Modules/Shop`
**Status:** Design agreed with the owner on 2026-10-22. No implementation started. Release is blocked on the Shop VAT decision, the Shop checkout and the Shop customer identity: see §9.
**Parent design:** `docs/superpowers/specs/2026-09-17-shop-module-design.md`

---

## 1. Purpose

Add an auction sales mode to Shop. A seller puts a lot of one product variant up for auction for a fixed period. Customers bid by hand or let the system raise for them up to a ceiling. The winner buys the whole lot at the winning amount.

Auctions are a contested process: every outcome must be explainable afterwards from data nobody can quietly alter, and no outcome may depend on the order in which code happened to process two customers. Those two requirements shape every rule below.

---

## 2. Decisions

| # | Decision | Rationale |
|---|----------|-----------|
| A1 | Auctions live in `Modules/Shop`, tables prefixed `shop_auction*` through `ShopTables`. | An auction is a Shop sales channel. The economic truth stays in ERP as for every Shop sale (E7). |
| A2 | **One auction sells one indivisible lot**: N pieces of one `ProductVariant`. The winner buys all N. | Multi-unit auctions with a quantity per bidder break ceilings, ties and the final price. |
| A3 | v1 auctions only variants composed of **exactly one `Item` with pivot quantity 1**. | The winning amount becomes the order line price with no division and no split across bundle components. |
| A4 | The lot's stock is **reserved `hard`** when the auction is scheduled, through `StockReservationService::reserve()` with source type `shop.auction`. It is released when the auction fails, is cancelled or ends without bids, and handed to the order at settlement. | A lot in auction must not be sold at a fixed price at the same time. |
| A5 | **All amounts are per piece.** The lot total is amount times N. Amounts are exact decimals in the minor unit of the currency, computed with ERP `Decimal`, never floats. | One unit of meaning for bids, ceilings and order line prices. |
| A6 | **The increment is set by the auction**, as one value or a table of price brackets. A customer never chooses the increment. | A customer-chosen step never helps win, since ceilings decide the winner. It only makes the user pay more, and makes the price depend on the path. |
| A7 | **The price basis, gross or net, follows the Shop VAT decision**, still open: Shop design §12, VAT. An auction cannot be released before it. | The amounts a customer enters have no defined meaning without the rate applied to the sale. |
| A8 | **A commitment is a manual bid or the ceiling of an auto-bid.** When it is made, every commitment must be at least the current price plus the increment, or at least the starting price when there are no bids. A manual bid is worth exactly its amount. A ceiling is inclusive. | One rule for both kinds. Nobody can tie an amount somebody already holds without committing above it, so a stalemate can no longer be created for free. |
| A9 | **Commitments are irrevocable.** Cancelling an auto-bid only stops future raises. Bids already placed stand. | A bid is a binding offer. |
| A10 | **One position per customer.** A customer never outbids themselves. | Leadership is a property of the customer, not of a configuration or a bid row. |
| A11 | **The price is computed directly, not simulated.** §3.4 gives the rule. No rounds. | Bounded work under the lock, a few rows per resolution, no liveness failure on long ladders. |
| A12 | **Equal highest maxima are a stalemate**, whoever arrived first. Nobody leads. | The owner's fairness rule: time of arrival must not pick a winner between equal commitments. |
| A13 | **A stalemate at closing time extends the auction, open to everyone**, for a duration set on the auction. The number of stalemate extensions is configurable, default 1. A stalemate at the end of the last extension **fails the auction**. | Lets the tied bidders or anyone else break the tie, with a bounded end. |
| A14 | **Time is the server clock, taken when the request arrives**, before waiting for the lock: `received_at`. Deadline and anti-sniping are measured on it. The closer acts only after a grace period longer than the maximum lock wait. | A bid received in time must not be rejected because it waited for the lock. |
| A15 | **Auto-bid configurations are an immutable table.** Bids produced by the system reference the configuration that produced them. Cancel and revise are the only customer actions. Revise closes the active configuration and creates a new one. | No dispute about what a customer had asked for at any moment. |
| A16 | **From Filament, support can only stop an auto-bid**, with a mandatory reason and ticket reference, never in the final window. The customer is always notified. The action is versioned with the session user. Support never creates or edits a configuration. | Support acts on customer requests, and its power to change an outcome is bounded and visible. |
| A17 | **Every auction has an append-only ledger with a hash chain**, and every bidder gets a receipt carrying the chain head. | A hash stored in the same row proves nothing to someone who can write the database. A chain whose head left our database does. |
| A18 | **Only eligible customers commit**: an authenticated user linked to an ERP `Party` with complete billing data, who accepted the current auction terms. The accepted terms version is stored with each commitment. Users of the seller company never commit. | The winner must be invoiceable, and every bidder must have agreed to the rules applied to them. |
| A19 | **An auction with bids is never deleted.** It can only be cancelled, with a reason and a notice to every bidder. | Deleting an auction would delete the evidence. |
| A20 | Resolution runs **synchronously, in one transaction, under a row lock on the auction**. No queue, no optimistic retry loop. | The customer gets the outcome immediately, and the order of commitments is the order of the lock. |

---

## 3. Rules

### 3.1 Lot and amounts

- `quantity` N of one variant, A2 and A3. Reserved quantity: N.
- `starting_price`, the increments and every bid and ceiling are per piece, in the auction's currency: the vendor company's default currency, E31.
- The increment table is an ordered list of `{from, increment}`. A single value is one row from zero. `inc(x)` is the increment of the bracket containing `x`.

### 3.2 Commitments

- A commitment is valid only when its amount `x` satisfies `x >= P + inc(P)`, where `P` is the current price, or `x >= starting_price` when there are no bids.
- Exception: a customer who is the **sole leader** may revise their ceiling to any value not below their own standing bid.
- A rejected commitment is still written to the ledger with its reason.
- A manual bid equal to the current price is rejected.

### 3.3 Positions

For each customer `u` with at least one commitment:

- `floor(u)`: the highest accepted bid amount of `u`, manual or automatic.
- `max(u)`: the larger of `floor(u)` and the ceiling of `u`'s active auto-bid, if any.

### 3.4 Price and leader

Sort customers by `max` descending: `max1 >= max2 >= ...`.

- **No commitment:** no price. The storefront shows the starting price.
- **One customer:** they lead. The price is `floor(u1)`.
- **`max1 > max2`:** `u1` leads. The price is the larger of `floor(u1)` and the smaller of `max1` and `max2 + inc(max2)`.
- **`max1 = max2 = ... = maxk > max(k+1)`:** stalemate at `max1`. Every tied customer's standing bid is `max1`.

Consequences:

- The price never decreases, because a leader's floor is their standing bid.
- An auto-bid with no competitor bids the starting price.
- Each resolution has a number, `resolution_no`, increasing per auction. It writes one automatic bid for each customer whose standing amount changed, at the new amount, linked to the configuration that produced it.

Examples with increment 1:

| Situation | Outcome |
|---|---|
| A ceiling 100, B ceiling 60 | A leads at 61 |
| A ceiling 100, B ceiling 100 | Stalemate at 100, whoever arrived first |
| C bids 50 by hand. B registers ceiling 100 and leads at 51. A bids 100 by hand | Stalemate at 100 |
| A bids 100 by hand. G wants to tie | G can only commit from 101 up: G leads and may have to buy |

### 3.5 Stalemate and extensions

- During a stalemate nobody leads. Tied customers are notified when it forms.
- At closing time, if there is a stalemate and `stalemate_extensions_used < max_stalemate_extensions`, `ends_at` moves forward by `stalemate_extension`. All bidders are notified. Normal rules apply during the extension.
- Otherwise a stalemate at closing time sets the auction to `failed_stalemate`. No order is created, the reservation is released and all bidders are notified.

### 3.6 Time

- `received_at` is read when the request reaches the application, before the lock is requested.
- A commitment is in time when `starts_at <= received_at < ends_at`. The stored status is a display convenience: validity is decided on times, so a late scheduler never accepts or rejects wrongly.
- Anti-sniping: an accepted commitment with `received_at >= ends_at - anti_sniping_window` sets `ends_at` to the later of `ends_at` and `received_at + anti_sniping_extension`.
- The closer only processes auctions with `ends_at <= now - grace`, with `grace` longer than the maximum lock wait. A request that cannot get the lock in time fails with a retryable error and writes nothing.

### 3.7 Auto-bid configurations

- At most one active configuration per customer and auction, checked under the lock.
- Create: the ceiling follows §3.2. The resolution runs.
- Revise: the active configuration is closed with reason `revised`, a new one is created with `previous_config_id`, and the old one gets `superseded_by_config_id`. The ceiling follows §3.2. The resolution runs.
- Cancel by the customer: allowed at any time. The standing bid stays.
- Stop by support: §3.10.

### 3.8 Eligibility

- An authenticated user, linked to an ERP `Party` with complete billing data. ERP has no link from a user to a `Party` today: `Party` has no `user_id`. The link is Shop's customer identity, E19, not built yet.
- The accepted version of the auction terms is stored on each commitment.
- A user whose `company_id` equals the auction's `company_id` cannot commit. `current_company_id()` already falls back to the user's `company_id`, so these are the seller's own users.

### 3.9 Ledger, receipts and audit

- One ledger per auction, append-only, with `sequence` unique per auction.
- Each entry stores its event type, the subject it refers to, the actor, `received_at`, a canonical JSON payload, `prev_hash` and `hash`. The hash covers the sequence, the previous hash and the canonical payload. The canonical payload has sorted keys and decimals as strings.
- Events: commitment accepted, commitment rejected, auto-bid created, auto-bid closed, resolution, extension, cancellation, closing.
- Each bidder receives a receipt for each of their commitments with the entry sequence and hash.
- An audit command recomputes the chain and replays the ledger through the same price rule, then compares the result with the stored state.

### 3.10 Support in Filament

- Bids, configurations and the ledger are read-only in Filament. Auctions follow the seller rules at the end of this section.
- One action, "Stop auto-bid", behind a dedicated permission:
  - It requires a reason of at most 255 characters and a ticket reference.
  - It is refused when `now >= ends_at - anti_sniping_window`.
  - It closes the configuration with reason `stopped_by_support`, `closed_via = filament` and `closed_by_user_id` set to the session user.
  - It runs under the auction lock and writes a ledger entry.
  - It notifies the customer with the reason.
- The seller manages their own auctions: create and edit while no bid exists, cancel with a reason afterwards.

### 3.11 States

`draft`, `scheduled`, `running`, `ended`, `failed_stalemate`, `cancelled`.

- `draft` to `scheduled`: the stock is reserved. A failed reservation keeps the auction in `draft`.
- `scheduled` to `running`: at `starts_at`.
- `running` to `ended`: at closing with a leader, `winning_bid_id` set. Settlement follows in slice 3. Or at closing with no bids, and the reservation is released.
- `running` to `failed_stalemate`: §3.5.
- `draft`, `scheduled` or `running` to `cancelled`: with a reason. The reservation is released. If bids exist, every bidder is notified.

The closer is one scheduled command, every minute, `onOneServer()`, registered from the Shop service provider as Core, ERP and SAO register theirs. It is idempotent: each auction is locked and re-checked before any transition.

---

## 4. Data model

All tables use `ShopTables`, `MigrateUtils` and models extending `Core\Overrides\Model`.

`shop_auctions`, mutable, `BelongsToCompany`, `HasVersions`, soft delete refused once a bid exists:

- `company_id`, `product_variant_id`, `quantity`
- `starts_at`, `ends_at`, `original_ends_at`
- `starting_price`, `increments` JSON
- `anti_sniping_window`, `anti_sniping_extension`, `stalemate_extension` in minutes, `max_stalemate_extensions` default 1
- `status`, `terms_version`, `cancel_reason`
- price basis and tax snapshot columns: shape set by the Shop VAT decision
- derived cache, rebuildable from the ledger: `current_price`, `is_stalemate`, `stalemate_extensions_used`, `winning_bid_id`

`shop_auction_autobid_configs`, frozen at birth:

- `auction_id`, `user_id`, `ceiling`, `previous_config_id`, `terms_version`, `received_at`, `created_at`
- closing columns, written once: `closed_at`, `closed_reason` in `cancelled_by_customer`, `revised`, `stopped_by_support`; `closed_by_user_id`; `closed_via` in `storefront`, `filament`, `system`; `close_note` up to 255 characters; `superseded_by_config_id`
- `HasLocks`, locked without owner at creation, with `attributesWritableWhileLocked()` returning only the closing columns
- `RestrictsCrudWrites` through `DeniesGenericCrudWrites`
- `HasVersions` with `VersionStrategy::DIFF`. Until the Core actor resolution lands, the model overrides `getVersionUserId()` to return `closed_by_user_id`: `docs/superpowers/specs/2026-10-22-core-version-actor-resolution-design.md`
- no soft delete; migration with `hasLocks: true` and `hasUpdatedAt: false`

`shop_auction_bids`, frozen at birth, no writable column, no versions, no soft delete:

- `auction_id`, `user_id`, `autobid_config_id` nullable, `kind` in `manual`, `auto`
- `amount`, `resolution_no`, `received_at`, `terms_version`
- `outcome` in `accepted`, `rejected`, with `reject_reason`
- `idempotency_key`, unique per auction and user; a replay returns the original outcome
- `ledger_entry_id`

`shop_auction_ledger`, frozen at birth, no writable column, no versions, no soft delete:

- `auction_id`, `sequence` unique per auction, `event_type`, `subject_type`, `subject_id`, `actor_user_id`, `received_at`, `payload` JSON, `prev_hash`, `hash`, `created_at`

---

## 5. Reuse contracts

- **ERP:** `StockReservationService::reserve()`, `release()`, `consume()`, keyed by an opaque source type and id. `Decimal` for exact amounts. `BelongsToCompany`. Settlement builds a `SalesOrder` through Shop placement, E27 and E28.
- **Core:** `HasLocks` with `attributesWritableWhileLocked()`. `RestrictsCrudWrites`. `HasVersions` and `VersionSetManager`, with reason and actor on support actions. Notifications. Permissions from `Core\Support\PermissionName`.
- **Shop:** `ProductVariant` and `VariantItem` for the lot.

Known limits of `HasLocks`: the guard only sees Eloquent events, the runtime setting `core.locking.prevent_modifications` can switch it off, and `forceUnlock()` exists behind the `unlock` permission. It is a seat belt. The ledger chain and the receipts are the evidence.

---

## 6. Flows

**Commitment:** manual bid, auto-bid create, revise or cancel.

1. Read `received_at`.
2. Open a transaction on the auction's connection and lock the auction row.
3. Check time, eligibility, validity §3.2 and the idempotency key.
4. Write the bid or configuration change and its ledger entry.
5. Run the price rule §3.4 and write the resulting automatic bids, the resolution ledger entry and the cache.
6. Apply anti-sniping.
7. Commit. Send notifications and receipts after commit.

**Closer:** §3.11.

**Support stop:** §3.10, then step 5 of the commitment flow.

Notifications: outbid, once per resolution; stalemate formed; extension; won; lost; failed; cancelled; support stop; receipts. Core's `LockedModelSubscriber` listens to `NotificationSending` and refuses a notification to a locked notifiable: the plan must check what happens when the customer's `User` record is locked.

---

## 7. Phasing

1. **Slice 1: domain.** Tables, models, price rule as a pure class, commitment service, ledger and chain, closer, stock reservation, audit command.
2. **Slice 2: surfaces.** Storefront API following the existing application convention, Filament resources and the stop action, notifications and receipts, rate limiting.
3. **Slice 3: settlement.** Order creation at the winning amount, payment window, non-payment policy, possible second-chance offer, reservation handoff. Blocked on §9.

Slices 1 and 2 can be built before the dependencies close. No auction is released before slice 3 and the legal review.

---

## 8. Testing

- The price rule as a table of cases: the examples of §3.4, increments by bracket, a three-way stalemate, manual against automatic, sole leader revising down, a price that never decreases.
- Validity: a commitment below `P + inc(P)` is rejected and written to the ledger. A manual bid equal to the price is rejected.
- Time: a commitment received before `ends_at` and processed after it is accepted. One received after it is rejected. The closer respects the grace period. Anti-sniping extends from `received_at`.
- Stalemate: extension once, failure on the second, notices sent.
- Immutability: update and delete of bids, ledger and configurations are refused. Only the closing columns of a configuration are writable, and only once.
- Ledger: the chain verifies, a tampered entry breaks it, the audit replay reproduces the stored state.
- Concurrency: two parallel commitments on a real database driver. SQLite has no row lock.
- Filament: no create, edit or delete. The stop action is refused in the final window, is versioned with the session user and notifies the customer.
- Reservation: reserve on scheduling, release on cancel, failure and no-bid end.

---

## 9. Dependencies

- **Shop VAT decision:** Shop design §12, VAT. It sets the price basis and the tax snapshot of an auction.
- **Shop checkout and payment:** E27, E28, E30. Not built. Needed by slice 3.
- **Shop customer identity:** the link between a Core `User` and an ERP `Party`, E19. Not built. Needed by eligibility §3.8.
- **Legal review:** consumer withdrawal for online auctions, the auction terms, the stalemate rule. Before release.
- **Core version actor resolution:** not blocking, the interim override of §4 covers it.

---

## 10. Risks

- **Residual stalemate:** someone who guesses the leader's hidden maximum exactly can create a stalemate. It requires a real commitment that may win. Shill detection is out of scope.
- **Lock contention at the end of a hot auction:** the price rule is bounded, but a slow request still holds the lock. Lock wait, grace and anti-sniping values are set together in the plan.
- **Support power:** stopping an auto-bid can change an outcome. Mitigated by permission, reason, ticket, final-window ban, ledger entry and customer notice.

---

## 11. Non-goals

- Several pieces bought by different bidders from one lot. A bid quantity.
- Customer-chosen increments. Reserve price. Bid retraction.
- Bundles and multi-item variants.
- Buyer's premium. Multi-currency. Cross-border or reverse-charge VAT.
- Shill or multi-account detection.

---

## 12. Open questions

- Default increment table and default durations for anti-sniping and stalemate extensions.
- Lock wait and grace values.
- Payment window, non-payment policy, second-chance offer: slice 3.
- Receipts as in-app notifications, emails or both.
- The exact shape of the price basis and tax snapshot: after the Shop VAT decision.
