# Shop Auctions Slice 1: Domain Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Goal:** The auction domain in `Modules/Shop`: schema, immutable records, the price rule, the ledger chain, commitments, the lifecycle and the audit. No HTTP route, no Filament page, no notification.

**Architecture:** A pure `AuctionPriceRule` decides price, leaders and stalemate from customer positions. `AuctionCommitmentService` serializes every commitment on one auction behind a cache lock and a row lock on `shop_auction_states`, writes the immutable bid or configuration, the hash-chained ledger entry, and runs `AuctionResolver`, which applies the rule and writes the resulting automatic bids. `AuctionLifecycleService` schedules with an ERP `hard` reservation, starts, closes, extends, fails and cancels. `AuctionAuditService` re-verifies the chain and replays the ledger.

**Tech Stack:** PHP 8.5, Laravel 12, Pest. `Modules/Shop`, consuming ERP `StockReservationService` and `Decimal`, Core `HasLocks`, `HasVersions`, `RestrictsCrudWrites`, `VersionSetManagerInterface`.

**Spec:** `docs/superpowers/specs/2026-10-22-shop-auctions-design.md`, slice 1 of §7.

**Status:** Open. The release of auctions is blocked by spec §9. This slice is not.

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`. Explicit types, braces on every control structure, PHPDoc over inline comments, `#[Override]` on real overrides, `final` classes like their siblings.
- Models extend `Modules\Core\Overrides\Model`. Tables through `ShopTables`. Migrations with `MigrateUtils` and FK names like `2026_10_06_000200_create_shop_product_variants_table.php`. Validation in `getRules()`. Permission names through `Modules\Core\Support\PermissionName`. Factories extend `Modules\Core\Overrides\Factory` with `definitionsArray()`, like `VariantItemFactory`.
- Amounts are decimal strings. Arithmetic through `Modules\ERP\Support\Decimal`, scale 4. Comparisons through `AuctionMoney`, Task 1. Never floats. Amount columns are `decimal(18, 4)`.
- Customer-entered amounts accept at most `config('shop.auctions.amount_scale')` decimals.
- Queries and transactions derive from the owning model: `$state->getConnection()->transaction(...)`. Never `DB::`.
- Services never read the clock. Commitments receive `CarbonImmutable $receivedAt`, the tick receives `CarbonImmutable $now`.
- Serialization: `Cache::lock("shop:auction:{$auctionId}", config('shop.auctions.lock_seconds'))->block(config('shop.auctions.lock_wait_seconds'), ...)` as first gate, then `lockForUpdate()` on the `shop_auction_states` row inside the transaction, as `Modules\ERP\Services\Inventory\StockReservationService::reserve()` does. A `LockTimeoutException` becomes `AuctionBusyException` and nothing is written.
- `Modules/Shop/config/config.php` gains `'auctions' => ['amount_scale' => 2, 'lock_seconds' => 10, 'lock_wait_seconds' => 5, 'close_grace_seconds' => 15]`. No environment variable.
- Frozen records follow `Modules\Core\Models\License` for soft deletes: an `is_deleted` boolean column defaulting to false, `hasSoftDelete: false`, and `private bool $softDeletesEnabled = false;` on the model. The Core soft-delete scope filters on `is_deleted`, so the column is required even without soft deletes.
- Models that must never be versioned override `getVersionStrategy(): VersionStrategy|false` to return `false`.
- Tests: Pest, in `Modules/Shop/tests/Feature/Auctions/` with `RefreshDatabase` and in `Modules/Shop/tests/Unit/Auctions/`. Run from `laraplate/`: `php artisan test --compact <path>`. No class declared in a test file. Helpers are functions in `Modules/Shop/tests/Pest.php`. `Modules\ERP\Tests\Stubs\LockRecordingSqliteGrammar` is autoloaded application-wide through the composer merge plugin.
- Pint from the `laraplate/` root on an explicit file list (`.ai/rules/modules.md`): `vendor/bin/pint --format agent <files>`.
- Commits inside `Modules/Shop` with `/usr/bin/git`. Ticks and docs of this plan in `laraplate/`.
- No new dependency.

## Review Focus

1. **Secret ceilings leaking through the generic CRUD.** A non-superadmin reading `/api/v1/select/shop/auction_autobid_configs`, or `/api/v1/select/shop/auctions` with relation `autobidConfigs`, must get no ceiling. Test in Task 3.
2. **Malformed amounts.** `"0"`, `"-5"`, `"abc"`, `"100.001"`, `"1e3"`, `" 100"`, a 20-digit integer are rejected with `InvalidAmount` and written to the ledger. No exception. Test in Task 6.
3. **Idempotency key replayed with a different amount.** Rejected with `IdempotencyConflict`, the original bid untouched. Replayed with the same amount: the original result, no new row. Test in Task 6.
4. **Time boundaries.** `receivedAt == starts_at` is accepted, `receivedAt == ends_at` is rejected, `receivedAt == ends_at - anti_sniping_window` extends. Two commitments with the same `receivedAt` are ordered by ledger sequence. Test in Task 6.
5. **The tick run twice, or a close racing a commitment inside the grace.** No double extension, no double release. Test in Task 8.

## Divergences from the spec, decided while planning

- **`shop_auction_bids` has no `ledger_entry_id`.** The ledger entry points at its subject, `subject_type` and `subject_id`, and is written after the bid. Both rows are immutable, so they cannot point at each other.
- **Configurations gain `close_ticket_reference`**, string 64, for the ticket that §3.10 requires.
- **A sole leader's exception of §3.2 applies to creating an auto-bid too**, not only to revising one: a manual leader adding an auto-bid may set the ceiling at their own standing bid.
- **The price is never below `starting_price`.** A customer with an auto-bid and no accepted bid has no floor yet: the rule uses the starting price.
- **A stalemate extension adds `stalemate_extension` to the current `ends_at`**, the literal reading of §3.5.

---

## File Structure

`Modules/Shop/`:

- `app/Enums/ShopTables.php`: modify. Cases `Auctions = 'shop_auctions'`, `AuctionStates = 'shop_auction_states'`, `AuctionAutobidConfigs = 'shop_auction_autobid_configs'`, `AuctionBids = 'shop_auction_bids'`, `AuctionLedger = 'shop_auction_ledger'`.
- `app/Enums/`: `AuctionStatus`, `AuctionBidKind`, `AuctionBidOutcome`, `AutobidCloseReason`, `AutobidClosedVia`, `AuctionLedgerEvent`, `CommitmentRejection`. Each has `values()` and `validationRule()` like `ProductKind`.
- `app/Models/`: `Auction`, `AuctionState`, `AuctionAutobidConfig`, `AuctionBid`, `AuctionLedgerEntry`.
- `database/migrations/`: `2026_10_22_000100` to `000500`, one per table.
- `database/factories/AuctionFactory.php`.
- `app/Services/Auctions/`: `AuctionMoney`, `IncrementTable`, `AuctionPriceRule`, `AuctionLedger`, `AuctionResolver`, `AuctionCommitmentService`, `AuctionLifecycleService`, `AuctionAuditService`, `StaffExclusionEligibility`.
- `app/Services/Auctions/Data/`: `AuctionPosition`, `PriceOutcome`, `CommitmentResult`, `AuctionAuditReport`.
- `app/Contracts/BidderEligibility.php`.
- `app/Exceptions/`: `AuctionBusyException`, `AuctionNotSchedulableException`, `AuctionTransitionException`.
- `app/Console/`: `AuctionsTickCommand`, `AuctionAuditCommand`. `ModuleServiceProvider` registers `app/Console/*.php` itself.
- `app/Providers/ShopServiceProvider.php`: modify. Permission blacklist, eligibility binding, `registerCommandSchedules()`.
- `config/config.php`: modify.
- `tests/Pest.php`: modify, auction helpers.
- `docs/rag/MODULE.md`, `README.md`: modify, Task 10.

---

### Task 1: Price rule

**Files:**
- Create: `app/Services/Auctions/AuctionMoney.php`, `IncrementTable.php`, `AuctionPriceRule.php`, `Data/AuctionPosition.php`, `Data/PriceOutcome.php`
- Test: `tests/Unit/Auctions/AuctionPriceRuleTest.php`, `tests/Unit/Auctions/IncrementTableTest.php`, `tests/Unit/Auctions/AuctionMoneyTest.php`

**Interfaces:**
- Produces:
  - `AuctionMoney::compare(string $a, string $b): int`, `max(string $a, string $b): string`, `min(string $a, string $b): string`, `parseCustomerAmount(string $raw, int $scale): ?string`. The last returns the amount at scale 4 or `null` when it is not a plain positive decimal with at most `$scale` decimals and at most 14 integer digits.
  - `IncrementTable::fromArray(array $brackets): self`, with `list<array{from: string, increment: string}>`. `at(string $price): string`. `toArray(): array`. It throws `InvalidArgumentException` when the list is empty, the first `from` is not zero, `from` is not strictly increasing, or an increment is not positive.
  - `AuctionPosition(int $userId, ?string $floor, string $max)`, readonly.
  - `PriceOutcome(?string $price, array $leaderUserIds, bool $isStalemate, array $standingByUser)`, readonly. `leaderUserIds` is `list<int>` sorted ascending, `standingByUser` is `array<int, string>`.
  - `AuctionPriceRule::resolve(array $positions, IncrementTable $increments, string $startingPrice): PriceOutcome`.
  - `AuctionPriceRule::minimumCommitment(?string $currentPrice, string $startingPrice, IncrementTable $increments): string`. It is the starting price when `$currentPrice` is null, else `$currentPrice + at($currentPrice)`.

- [ ] **Step 1: Write the failing tests.** `AuctionPriceRuleTest` is a dataset over `resolve()`. Increment 1 unless stated, starting price `10`. Positions are written `user:floor/max`.

```php
it('applies the price rule', function (array $positions, ?string $price, array $leaders, bool $stalemate, array $standing): void {
    $outcome = new AuctionPriceRule()->resolve($positions, IncrementTable::fromArray([['from' => '0', 'increment' => '1']]), '10.0000');

    expect($outcome->price)->toBe($price)
        ->and($outcome->leaderUserIds)->toBe($leaders)
        ->and($outcome->isStalemate)->toBe($stalemate)
        ->and($outcome->standingByUser)->toBe($standing);
})->with([
    'nobody' => [[], null, [], false, []],
    'alone with an auto-bid' => [[new AuctionPosition(1, null, '100.0000')], '10.0000', [1], false, [1 => '10.0000']],
    'alone by hand' => [[new AuctionPosition(1, '50.0000', '50.0000')], '50.0000', [1], false, [1 => '50.0000']],
    'A 100 B 60' => [[new AuctionPosition(1, '10.0000', '100.0000'), new AuctionPosition(2, '60.0000', '60.0000')], '61.0000', [1], false, [1 => '61.0000', 2 => '60.0000']],
    'equal ceilings' => [[new AuctionPosition(1, '10.0000', '100.0000'), new AuctionPosition(2, '11.0000', '100.0000')], '100.0000', [1, 2], true, [1 => '100.0000', 2 => '100.0000']],
    'three, two tied' => [[new AuctionPosition(1, null, '100.0000'), new AuctionPosition(2, null, '100.0000'), new AuctionPosition(3, '90.0000', '90.0000')], '100.0000', [1, 2], true, [1 => '100.0000', 2 => '100.0000', 3 => '90.0000']],
    'manual ties auto' => [[new AuctionPosition(3, '50.0000', '50.0000'), new AuctionPosition(2, '51.0000', '100.0000'), new AuctionPosition(1, '100.0000', '100.0000')], '100.0000', [1, 2], true, [1 => '100.0000', 2 => '100.0000', 3 => '50.0000']],
    'capped by the leader max' => [[new AuctionPosition(1, null, '60.5000'), new AuctionPosition(2, '60.0000', '60.0000')], '60.5000', [1], false, [1 => '60.5000', 2 => '60.0000']],
    'leader floor above' => [[new AuctionPosition(1, '80.0000', '100.0000'), new AuctionPosition(2, '60.0000', '60.0000')], '80.0000', [1], false, [1 => '80.0000', 2 => '60.0000']],
]);

it('reads the increment of the bracket of the second maximum', function (): void {
    $increments = IncrementTable::fromArray([['from' => '0', 'increment' => '1'], ['from' => '100', 'increment' => '5']]);
    $outcome = new AuctionPriceRule()->resolve([new AuctionPosition(1, null, '200.0000'), new AuctionPosition(2, '120.0000', '120.0000')], $increments, '10.0000');

    expect($outcome->price)->toBe('125.0000');
});

it('computes the minimum commitment', function (?string $price, string $minimum): void {
    $increments = IncrementTable::fromArray([['from' => '0', 'increment' => '1'], ['from' => '100', 'increment' => '5']]);

    expect(new AuctionPriceRule()->minimumCommitment($price, '10.0000', $increments))->toBe($minimum);
})->with([[null, '10.0000'], ['61.0000', '62.0000'], ['100.0000', '105.0000']]);
```

  `IncrementTableTest` covers the four invalid shapes and `at()` on a bracket boundary. `AuctionMoneyTest` covers `parseCustomerAmount()` with scale 2: `"100"` gives `"100.0000"`, `"100.5"` gives `"100.5000"`, and the values of Review Focus 2 give `null`.

- [ ] **Step 2: Run them and see them fail.** Run: `php artisan test --compact Modules/Shop/tests/Unit/Auctions`. Expected: FAIL, classes not found.
- [ ] **Step 3: Implement the classes.** `resolve()` sorts positions by `max` descending, then `userId` ascending. It uses `floor ?? $startingPrice` as the effective floor. It follows spec §3.4: a non-leader stands at its `max`. It is never below `$startingPrice`.
- [ ] **Step 4: Run them.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(shop): auction price rule`.

### Task 2: Auction configuration and runtime state

**Files:**
- Modify: `app/Enums/ShopTables.php`, `config/config.php`, `app/Providers/ShopServiceProvider.php`
- Create: `app/Enums/AuctionStatus.php`, `database/migrations/2026_10_22_000100_create_shop_auctions_table.php`, `database/migrations/2026_10_22_000200_create_shop_auction_states_table.php`, `app/Models/Auction.php`, `app/Models/AuctionState.php`, `database/factories/AuctionFactory.php`
- Test: `tests/Feature/Auctions/AuctionModelTest.php`

**Interfaces:**
- Produces:
  - `AuctionStatus`: `Draft = 'draft'`, `Scheduled = 'scheduled'`, `Running = 'running'`, `Ended = 'ended'`, `FailedStalemate = 'failed_stalemate'`, `Cancelled = 'cancelled'`.
  - `shop_auctions`: `id`, `company_id`, `product_variant_id` FK restrict, `quantity` unsigned integer, `starts_at`, `ends_at`, `starting_price`, `increments` JSON, `anti_sniping_window`, `anti_sniping_extension` and `stalemate_extension` as unsigned small integers in minutes, `max_stalemate_extensions` unsigned tiny integer default 1, `status` string 20 default `draft`, `terms_version` string 64, `cancel_reason` string 255 nullable, `timestamps(hasCreateUpdate: true, hasSoftDelete: true)`.
  - `Auction`: `BelongsToCompany`. Casts: `starts_at` and `ends_at` `immutable_datetime`, `increments` array, `status` `AuctionStatus`, `starting_price` `decimal:4`. Relations `variant(): BelongsTo` and `state(): HasOne`. `incrementTable(): IncrementTable`.
  - `shop_auction_states`: `auction_id` primary key and FK restrict, `ends_at`, `current_price` nullable, `is_stalemate` default false, `stalemate_extensions_used` default 0, `winning_bid_id` unsigned big integer nullable with no FK, `resolution_no` and `last_sequence` unsigned integers default 0, `head_hash` char 64, `is_deleted` default false, `created_at` and `updated_at`.
  - `AuctionState`: `$primaryKey = 'auction_id'`, `$incrementing = false`, not versioned, no soft deletes. Cast `ends_at` `immutable_datetime`.
  - `AuctionFactory`: a draft auction on `ProductVariant::factory()` with quantity 1, starting price `10.0000`, increments `[['from' => '0', 'increment' => '1']]`, windows 2, 2 and 10 minutes, `terms_version` `'2026-10-22'`, starting tomorrow and ending in two days.
  - `ShopServiceProvider::register()` appends `AuctionState::class` to `config('permission.models_blacklist')`.

- [ ] **Step 1: Write the failing tests** in `AuctionModelTest`, with `setupShopEntities()` in `beforeEach`:
  - `it('stores an auction with its configuration')`: a factory auction reloads with `status` `AuctionStatus::Draft`, `increments` as an array and `incrementTable()->at('5')` equal to `'1.0000'`.
  - `it('refuses an auction that ends before it starts')`: `ends_at` equal to `starts_at` throws `Illuminate\Validation\ValidationException`.
  - `it('keeps the auction state out of the permission scheme')`: `PermissionsRefreshCommand::isOutsidePermissionScheme(AuctionState::class)` is `true`. `new AuctionState()->getVersionStrategy()` is `false`. `new AuctionState()->softDeletesEnabledInCode()` is `false`.
- [ ] **Step 2: Run it and see it fail.** Run: `php artisan test --compact Modules/Shop/tests/Feature/Auctions/AuctionModelTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** the enum, migrations, models, factory, config keys and blacklist entry. `getRules()` for create:
  - `company_id`, `product_variant_id`: required, exists.
  - `quantity`: integer, min 1.
  - `starts_at`: required date. `ends_at`: required date, `after:starts_at`.
  - `starting_price`: numeric, gt 0.
  - `increments`: array, min 1. `increments.*.from`: numeric, min 0. `increments.*.increment`: numeric, gt 0.
  - The three minute fields: integer, min 1. `max_stalemate_extensions`: integer, min 0.
  - `terms_version`: required string, max 64. `status`: `AuctionStatus::validationRule()`.
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(shop): auction configuration and runtime state`.

### Task 3: Frozen records: configurations, bids, ledger

**Files:**
- Create: `app/Enums/AuctionBidKind.php`, `AuctionBidOutcome.php`, `AutobidCloseReason.php`, `AutobidClosedVia.php`, `AuctionLedgerEvent.php`, `CommitmentRejection.php`
- Create: `database/migrations/2026_10_22_000300_create_shop_auction_autobid_configs_table.php`, `..._000400_create_shop_auction_bids_table.php`, `..._000500_create_shop_auction_ledger_table.php`
- Create: `app/Models/AuctionAutobidConfig.php`, `AuctionBid.php`, `AuctionLedgerEntry.php`
- Modify: `app/Models/Auction.php`, `app/Providers/ShopServiceProvider.php`
- Test: `tests/Feature/Auctions/AuctionRecordImmutabilityTest.php`, `tests/Feature/Auctions/AuctionCrudExposureTest.php`

**Interfaces:**
- Produces:
  - Enums:
    - `AuctionBidKind`: `Manual = 'manual'`, `Auto = 'auto'`.
    - `AuctionBidOutcome`: `Accepted = 'accepted'`, `Rejected = 'rejected'`.
    - `AutobidCloseReason`: `CancelledByCustomer = 'cancelled_by_customer'`, `Revised = 'revised'`, `StoppedBySupport = 'stopped_by_support'`.
    - `AutobidClosedVia`: `Storefront`, `Filament`, `System`, lowercase values.
    - `AuctionLedgerEvent`: `Scheduled`, `Started`, `CommitmentAccepted`, `CommitmentRejected`, `AutobidCreated`, `AutobidClosed`, `Resolution`, `Extended`, `Ended`, `Failed`, `Cancelled`, snake_case values.
    - `CommitmentRejection`: `NotOpen`, `NotEligible`, `TermsOutdated`, `InvalidAmount`, `BelowMinimum`, `IdempotencyConflict`, `AutobidAlreadyActive`, `NoActiveAutobid`, `BelowOwnStandingBid`, `UnchangedCeiling`, `FinalWindow`, snake_case values.
  - `shop_auction_autobid_configs`:
    - `id`, `auction_id` FK restrict, `user_id` FK restrict to the Core users table, `ceiling`, `previous_config_id` nullable self FK, `terms_version`, `received_at` `timestamp(6)`.
    - Closing columns: `closed_at` nullable, `closed_reason` string 32 nullable, `closed_by_user_id` nullable FK, `closed_via` string 16 nullable, `close_note` string 255 nullable, `close_ticket_reference` string 64 nullable, `superseded_by_config_id` nullable self FK.
    - `is_deleted`. `timestamps(hasCreateUpdate: true, hasUpdatedAt: false, hasLocks: true)`.
  - `shop_auction_bids`:
    - `id`, `auction_id`, `user_id`, `autobid_config_id` nullable FK, `kind` string 8, `amount`, `resolution_no` unsigned integer nullable, `received_at` `timestamp(6)`, `terms_version` nullable, `outcome` string 10, `reject_reason` string 32 nullable, `idempotency_key` string 64 nullable.
    - Unique on `auction_id`, `user_id` and `idempotency_key`.
    - `is_deleted`. Same timestamps call.
  - `shop_auction_ledger`:
    - `id`, `auction_id`, `sequence` unsigned integer, unique with `auction_id`. `event_type` string 32, `subject_type` string 64 nullable, `subject_id` nullable, `actor_user_id` nullable with no FK, `received_at` `timestamp(6)`, `payload` JSON, `prev_hash` char 64, `hash` char 64.
    - `is_deleted`. Same timestamps call.
  - All three models:
    - Implement `Modules\Core\Contracts\ILockableModel` and `Modules\Core\Contracts\RestrictsCrudWrites`. Use `HasLocks` and `DeniesGenericCrudWrites`.
    - `public const UPDATED_AT = null;`. No soft deletes.
    - A `creating` hook calls `$this->lock()`, so every row is frozen without owner at birth.
  - Model-specific:
    - `AuctionAutobidConfig::CLOSING_COLUMNS`, a list of the seven closing columns, returned by `attributesWritableWhileLocked()`.
    - An `updating` hook on `AuctionAutobidConfig` throws `Modules\Core\Locking\Exceptions\LockedModelException` when the original `closed_at` is not null.
    - `AuctionAutobidConfig` has `protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;` and overrides `getVersionUserId()` to return `closed_by_user_id`. This is interim until `docs/superpowers/plans/2026-10-22-core-version-actor-resolution.md` Task 5.
    - `AuctionBid` and `AuctionLedgerEntry` are not versioned.
  - `Auction` gains `autobidConfigs(): HasMany`, `bids(): HasMany`, `ledgerEntries(): HasMany` and `hasBids(): bool`. Once `hasBids()` is true, an `updating` hook refuses a change to any attribute other than `status` and `cancel_reason`, and a `deleting` hook refuses the delete. Both throw `AuctionTransitionException`.
  - The blacklist gains the three new classes.
  - `getRules()` for create on the three models: foreign keys required and existing, amounts and ceilings numeric and greater than 0, enum columns through `validationRule()`, `hash` and `prev_hash` of size 64.

- [ ] **Step 1: Write the failing immutability tests** in `AuctionRecordImmutabilityTest`. The rows are created directly through the models.
  - `it('freezes bids and ledger entries at birth')`: changing `amount` or `payload` and saving throws `LockedModelException`. `delete()` throws `LockedModelException`.
  - `it('accepts the closing columns of a configuration once')`: filling the closing columns saves. A second save that changes `close_note` throws `LockedModelException`. Changing `ceiling` on a fresh configuration throws `LockedModelException`.
  - `it('attributes the closing version to the closing user')`: acting as user Y, close with `closed_by_user_id` set to X. `$config->latestVersion->user_id` is X's id.
  - `it('refuses to edit or delete an auction with bids')`: after one bid row, changing `starting_price` throws `AuctionTransitionException`. Changing `status` alone saves. `delete()` throws.
  - `it('keeps the auction records out of the generic CRUD')`: for the four classes, `isOutsidePermissionScheme()` is true. For the three frozen classes, `deniedCrudWrites()` equals the full list of `DeniesGenericCrudWrites`.
- [ ] **Step 2: Write the failing exposure test, Review Focus 1**, in `AuctionCrudExposureTest`, granting permissions as `Modules/Core/tests/Integration/Services/Crud/RelationAuthorizationTest.php` does: `select` on `Auction` on the `web` and `api` guards, through an `api` role, to a non-superadmin.

```php
it('never serves a ceiling through the generic CRUD', function (): void {
    // one auction with one configuration of ceiling 987.6500
    $direct = $this->actingAs($reader)->getJson('/api/v1/select/shop/auction_autobid_configs');
    expect($direct->status())->toBeIn([401, 403, 404]);

    $related = $this->actingAs($reader)->postJson('/api/v1/select/shop/auctions', ['relations' => ['autobidConfigs']]);
    expect($related->getContent())->not->toContain('987.65')->not->toContain('ceiling');
});
```

- [ ] **Step 3: Run them and see them fail.** Run: `php artisan test --compact Modules/Shop/tests/Feature/Auctions/AuctionRecordImmutabilityTest.php Modules/Shop/tests/Feature/Auctions/AuctionCrudExposureTest.php`. Expected: FAIL.
- [ ] **Step 4: Implement** the enums, migrations, models, `Auction` relations and guards, and the blacklist. If the exposure test still finds a ceiling, stop and report to the owner. Do not hide the relation to make it pass.
- [ ] **Step 5: Run them.** Expected: PASS.
- [ ] **Step 6: Commit** `feat(shop): immutable auction configurations, bids and ledger`.

### Task 4: Ledger chain

**Files:**
- Create: `app/Services/Auctions/AuctionLedger.php`
- Test: `tests/Feature/Auctions/AuctionLedgerTest.php`

**Interfaces:**
- Consumes: `AuctionState`, `AuctionLedgerEntry`, `AuctionLedgerEvent`.
- Produces:
  - `AuctionLedger::GENESIS_HASH`: 64 `'0'` characters.
  - `AuctionLedger::canonicalJson(array $payload): string`. Keys are sorted recursively, lists keep their order, flags are `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`.
  - `AuctionLedger::hashFor(int $sequence, string $prevHash, string $canonicalPayload): string`, which is `hash('sha256', $sequence . "\n" . $prevHash . "\n" . $canonicalPayload)`.
  - `append(AuctionState $lockedState, AuctionLedgerEvent $event, array $payload, ?Model $subject, ?int $actorUserId, CarbonImmutable $receivedAt): AuctionLedgerEntry`. It throws `LogicException` when no transaction is open on the state's connection. It writes `sequence = last_sequence + 1` and `prev_hash = head_hash`, then saves `last_sequence` and `head_hash` on the state.
  - `firstBrokenSequence(int $auctionId): ?int`.
  - Payload contracts. Later tasks write exactly these keys. Amounts are scale-4 strings. Times are UTC ISO-8601 with microseconds. User ids are integers.
    - `scheduled`: `quantity`, `starting_price`, `increments`, `starts_at`, `ends_at`, `anti_sniping_window`, `anti_sniping_extension`, `stalemate_extension`, `max_stalemate_extensions`, `terms_version`, `item_id`.
    - `started`: no keys.
    - `commitment_accepted`: `bid_id`, `user_id`, `amount`, `idempotency_key`, `terms_version`.
    - `commitment_rejected`: `action` in `bid`, `autobid_create`, `autobid_revise`, `autobid_cancel`, `support_stop`; `user_id`; `raw_amount` up to 64 characters or null; `reason`; `idempotency_key` or null.
    - `autobid_created`: `config_id`, `user_id`, `ceiling`, `previous_config_id`, `terms_version`.
    - `autobid_closed`: `config_id`, `user_id`, `reason`, `closed_via`, `closed_by_user_id`, `superseded_by_config_id`, `note`, `ticket_reference`.
    - `resolution`: `resolution_no`, `price`, `leader_user_ids`, `is_stalemate`, `standing` as an object keyed by user id, `auto_bid_ids` as an object keyed by user id.
    - `extended`: `reason` in `anti_sniping` or `stalemate`, `ends_at`.
    - `ended`: `winning_bid_id`, `price`.
    - `failed`: `price`.
    - `cancelled`: `reason`.

- [ ] **Step 1: Write the failing tests:**
  - `it('serializes payloads canonically')`: `['b' => ['d' => 2, 'c' => 1], 'a' => '1.0000']` gives `'{"a":"1.0000","b":{"c":1,"d":2}}'`.
  - `it('chains entries')`: three appends inside a transaction on a scheduled auction's state give sequences 1, 2 and 3. Each `prev_hash` is the previous `hash`. Entry 1 has `prev_hash` equal to `GENESIS_HASH` and `hash` equal to `AuctionLedger::hashFor(1, GENESIS_HASH, canonicalJson($payload))`. The state's `head_hash` is the hash of entry 3.
  - `it('finds a tampered entry')`: `AuctionLedgerEntry::query()->whereKey($second->id)->toBase()->update(['payload' => '{"x":1}'])` makes `firstBrokenSequence()` return 2. An untouched chain returns `null`.
  - `it('refuses to append outside a transaction')`: `LogicException`.
- [ ] **Step 2: Run them and see them fail.** Run: `php artisan test --compact Modules/Shop/tests/Feature/Auctions/AuctionLedgerTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement `AuctionLedger`.** `firstBrokenSequence()` walks the entries by sequence, recomputing each hash from the stored payload, re-encoded canonically, and the previous stored hash.
- [ ] **Step 4: Run them.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(shop): hash-chained auction ledger`.

### Task 5: Scheduling with a stock reservation

**Files:**
- Create: `app/Services/Auctions/AuctionLifecycleService.php` with `schedule()` only, `app/Exceptions/AuctionNotSchedulableException.php`
- Modify: `tests/Pest.php`
- Test: `tests/Feature/Auctions/AuctionSchedulingTest.php`

**Interfaces:**
- Consumes: `StockReservationService::reserve(int $companyId, int $itemId, string $quantity, StockReservationState $mode, string $sourceType, int $sourceId)`, `AuctionLedger::append()`, `IncrementTable::fromArray()`.
- Produces:
  - `AuctionLifecycleService::RESERVATION_SOURCE = 'shop.auction'`.
  - `schedule(Auction $auction, CarbonImmutable $now): void`. It accepts only `draft`. It requires `ends_at > $now`, a `physical` product, and exactly one `VariantItem` with quantity `1.0000`. It validates `IncrementTable::fromArray($auction->increments)`. Otherwise it throws `AuctionNotSchedulableException` with a reason.
  - In one transaction on the auction's connection, `schedule()`:
    - reserves `quantity` of the item `hard`;
    - creates the state with `ends_at = $auction->ends_at` and `head_hash = GENESIS_HASH`;
    - sets `status` to `scheduled`;
    - appends `scheduled` with `actorUserId` null and `receivedAt = $now`.
  - `InsufficientStockException` propagates and nothing is written.
  - Test helpers in `Pest.php`:
    - `shop_auction_lot(Company $company, string $onHand = '10.0000'): ProductVariant` stores a product through `CreateProductAction` with one default variant of one item with quantity `'1'`, and puts `$onHand` on hand in a fresh warehouse as `reservation_test_on_hand()` does in ERP.
    - `shop_scheduled_auction(array $overrides = []): Auction` creates a lot, a factory auction on it and schedules it.
    - `shop_bidder(): User` creates a user with no permission.

- [ ] **Step 1: Write the failing tests:**
  - `it('reserves the lot and opens the ledger')`: quantity 3 gives `StockReservationService::reservedQuantity('shop.auction', $auction->id)` equal to `'3.0000'`, a state row, `status` `scheduled`, and one ledger entry `scheduled` whose payload `item_id` is the item.
  - `it('refuses a lot that is not one item of quantity one')`: a variant with item quantity `'2'`, a variant with two items, and a digital product each throw `AuctionNotSchedulableException`. No reservation, no state.
  - `it('leaves the auction in draft when the stock is short')`: on hand `'1.0000'` and quantity 2 give `InsufficientStockException`. The status stays `draft`, no state row exists, no reservation exists.
  - `it('schedules only a draft')`: a second `schedule()` throws `AuctionNotSchedulableException`.
- [ ] **Step 2: Run them and see them fail.** Run: `php artisan test --compact Modules/Shop/tests/Feature/Auctions/AuctionSchedulingTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement `schedule()` and the helpers.**
- [ ] **Step 4: Run them.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(shop): schedule an auction with a hard stock reservation`.

### Task 6: Manual bids and resolution

**Files:**
- Create: `app/Services/Auctions/AuctionCommitmentService.php` with `placeBid()`, `AuctionResolver.php`, `StaffExclusionEligibility.php`, `Data/CommitmentResult.php`, `app/Contracts/BidderEligibility.php`, `app/Exceptions/AuctionBusyException.php`
- Modify: `app/Providers/ShopServiceProvider.php`, binding `BidderEligibility` to `StaffExclusionEligibility`
- Test: `tests/Feature/Auctions/ManualBidTest.php`

**Interfaces:**
- Consumes: Tasks 1 to 5.
- Produces:
  - `BidderEligibility::rejectionFor(User $user, Auction $auction): ?CommitmentRejection`.
  - `StaffExclusionEligibility` returns `NotEligible` when the user is a superadmin, or holds `PermissionName::forClass(Auction::class, 'insert')` or `'update'` through `hasPermission()` on the `web` or the `api` guard. The `Party` check of spec §3.8 waits for the Shop customer identity, spec §9.
  - `CommitmentResult(bool $accepted, ?CommitmentRejection $rejection, ?AuctionBid $bid, ?AuctionAutobidConfig $config, ?PriceOutcome $outcome, AuctionLedgerEntry $entry)`, readonly.
  - `AuctionResolver::resolve(Auction $auction, AuctionState $lockedState, CarbonImmutable $receivedAt): PriceOutcome`.
    - It builds positions: the floor is the highest accepted bid of each user, and the max is the larger of the floor and the ceiling of the user's active configuration.
    - It calls the rule with the auction's starting price.
    - For each user whose standing differs from the floor, it writes an `auto` bid at the standing, with the user's active configuration, `resolution_no = resolution_no + 1` and the configuration's `terms_version`. Such a user without an active configuration is a `LogicException`.
    - It appends `resolution` and saves `current_price`, `is_stalemate` and `resolution_no`.
  - `AuctionCommitmentService::placeBid(Auction $auction, User $bidder, string $amount, string $termsVersion, string $idempotencyKey, CarbonImmutable $receivedAt): CommitmentResult`.

- [ ] **Step 1: Write the failing tests:**
  - `it('accepts a first bid at the starting price')`: the bidder leads. `current_price` is `'10.0000'`. The ledger events are `scheduled`, `commitment_accepted`, `resolution`.
  - `it('rejects a bid below price plus increment')`: with the price at `61.0000`, a bid of `61.5` is rejected with `BelowMinimum`. A rejected bid row and a `commitment_rejected` entry exist. The price is unchanged.
  - `it('rejects a manual bid equal to the current price')`: `BelowMinimum`.
  - `it('rejects malformed amounts')`, Review Focus 2: a dataset of the seven values gives `InvalidAmount`, a ledger entry with `raw_amount`, and no bid row.
  - `it('honours the idempotency key')`, Review Focus 3: the same key and amount return the original bid id and write no row. The same key with another amount gives `IdempotencyConflict`, writes a ledger entry, and leaves the original bid untouched.
  - `it('measures time on receivedAt')`, Review Focus 4: `starts_at` is accepted; `ends_at` gives `NotOpen`; one microsecond before `ends_at` is accepted; two bids with the same `receivedAt` get consecutive ledger sequences in call order.
  - `it('extends on a bid in the final window')`: with window 2 and extension 2, a bid received at `ends_at - 2 minutes` moves `ends_at` to `receivedAt + 2 minutes` and appends `extended` with reason `anti_sniping`. A bid at `ends_at - 3 minutes` does not extend.
  - `it('refuses seller staff and outdated terms')`: a user holding `update` on `Auction` on the `api` guard gives `NotEligible`. A wrong `termsVersion` gives `TermsOutdated`.
  - `it('refuses a bid on a cancelled auction')`: `NotOpen`.
  - `it('serializes on the state row')`: with `LockRecordingSqliteGrammar` installed as in `Modules/ERP/tests/Feature/Services/Inventory/StockReservationServiceTest.php`, the select on `shop_auction_states` is `locked` at transaction depth at least 1.
  - `it('reports a busy auction without writing')`: holding `Cache::lock("shop:auction:{$id}")` with `lock_wait_seconds` set to 0 makes `placeBid()` throw `AuctionBusyException`. The ledger is unchanged.
- [ ] **Step 2: Run them and see them fail.** Run: `php artisan test --compact Modules/Shop/tests/Feature/Auctions/ManualBidTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement `placeBid()`** in this order, under the gate and the row lock:
  1. Idempotency.
  2. Status `scheduled` or `running`, and `starts_at <= receivedAt < state.ends_at`: else `NotOpen`.
  3. Eligibility.
  4. Terms.
  5. `AuctionMoney::parseCustomerAmount()`: else `InvalidAmount`.
  6. Amount at least `minimumCommitment()`: else `BelowMinimum`.
  7. Write the accepted manual bid and `commitment_accepted`.
  8. `AuctionResolver::resolve()`.
  9. Anti-sniping.

  A rejection with a parsed amount writes a rejected bid row and its ledger entry. A rejection without one writes only the ledger entry.
- [ ] **Step 4: Run them.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(shop): manual auction bids and resolution`.

### Task 7: Auto-bids

**Files:**
- Modify: `app/Services/Auctions/AuctionCommitmentService.php`
- Test: `tests/Feature/Auctions/AutobidTest.php`

**Interfaces:**
- Consumes: Task 6. `Modules\Core\Versioning\Contracts\VersionSetManagerInterface::run(VersionSetRoot $root, Closure $operation, ?VersionSetOptions $options)`, `VersionSetRoot::forModel()`, `VersionSetOptions(reason:, actor:)`.
- Produces:
  - `createAutobid(Auction $auction, User $bidder, string $ceiling, string $termsVersion, CarbonImmutable $receivedAt): CommitmentResult`
  - `reviseAutobid(Auction $auction, User $bidder, string $ceiling, string $termsVersion, CarbonImmutable $receivedAt): CommitmentResult`
  - `cancelAutobid(Auction $auction, User $bidder, CarbonImmutable $receivedAt): CommitmentResult`
  - `stopAutobidBySupport(AuctionAutobidConfig $config, User $operator, string $reason, string $ticketReference, CarbonImmutable $receivedAt): CommitmentResult`. It throws `InvalidArgumentException` for an empty reason, a reason over 255 characters, or an empty ticket reference, or one over 64 characters. The operator's permission is Filament's concern, slice 2.
  - Ceiling rule for create and revise: at least `minimumCommitment()`. A sole leader may instead set it at or above their own floor. Otherwise `BelowMinimum`, or `BelowOwnStandingBid` for a sole leader below their floor.

- [ ] **Step 1: Write the failing tests:**
  - `it('raises to the second ceiling plus the increment')`: A's ceiling 100 and B's manual 60 make A's auto bid `61.0000`, linked to A's configuration. A leads.
  - `it('turns equal ceilings into a stalemate')`: two ceilings of 100 give both standings `100.0000`, `is_stalemate` true and no single leader.
  - `it('refuses a second active auto-bid')`: `AutobidAlreadyActive`.
  - `it('lets a sole manual leader add an auto-bid at their own bid')`: accepted.
  - `it('revises by closing and creating')`: the old configuration is closed with `revised` and `superseded_by_config_id` pointing at the new one, whose `previous_config_id` points back. The ledger has `autobid_closed`, `autobid_created`, `resolution`. A revision with the same ceiling gives `UnchangedCeiling`.
  - `it('refuses a sole leader revising below their standing bid')`: `BelowOwnStandingBid`.
  - `it('keeps standing bids after a cancel')`: after cancel, the user's bids are untouched and the price does not decrease.
  - `it('lets support stop an auto-bid outside the final window')`, acting as the operator:
    - the closing columns are `stopped_by_support`, `filament`, the operator's id, the reason and the ticket;
    - the latest version's `user_id` is the operator's;
    - its version set's `reason` is the reason.
  - `it('refuses a support stop in the final window')`: at `ends_at - anti_sniping_window` the result is `FinalWindow` with a `commitment_rejected` entry whose `action` is `support_stop`. The configuration stays open.
- [ ] **Step 2: Run them and see them fail.** Run: `php artisan test --compact Modules/Shop/tests/Feature/Auctions/AutobidTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement the four methods.** Each reuses the gate, row lock, time, eligibility and terms checks of `placeBid()` through one private method. Support stop skips eligibility and terms.
- [ ] **Step 4: Run them, and Task 6's tests.** Run: `php artisan test --compact Modules/Shop/tests/Feature/Auctions/AutobidTest.php Modules/Shop/tests/Feature/Auctions/ManualBidTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(shop): auction auto-bids and support stop`.

### Task 8: Tick, closing and cancellation

**Files:**
- Modify: `app/Services/Auctions/AuctionLifecycleService.php`, `app/Providers/ShopServiceProvider.php`
- Create: `app/Console/AuctionsTickCommand.php`, `app/Exceptions/AuctionTransitionException.php`
- Test: `tests/Feature/Auctions/AuctionLifecycleTest.php`

**Interfaces:**
- Consumes: Tasks 4 to 7. `StockReservationService::release(string $sourceType, int $sourceId)`.
- Produces:
  - `tick(CarbonImmutable $now): void`. It starts every `scheduled` auction with `starts_at <= $now`: `running`, entry `started`. It closes every `running` auction whose state has `ends_at <= $now - close_grace_seconds`.
  - `close(Auction $auction, CarbonImmutable $now): void`. It runs under the gate and the row lock and re-checks that the auction is due. It is a no-op otherwise. Then:
    - Stalemate with extensions left: `ends_at += stalemate_extension`, `stalemate_extensions_used + 1`, entry `extended` with reason `stalemate`.
    - Stalemate with no extension left: `failed_stalemate`, reservation released, entry `failed`.
    - A leader: `ended`, `winning_bid_id` set to the leader's highest accepted bid, entry `ended`. The reservation stays for slice 3.
    - No bid: `ended`, reservation released, entry `ended` with null winner.
  - `cancel(Auction $auction, User $actor, string $reason, CarbonImmutable $now): void`. It accepts `draft`, `scheduled` and `running`, else `AuctionTransitionException`. The reason is required. It releases the reservation when there is one, sets `cancelled` and `cancel_reason`, and appends `cancelled` when a state exists.
  - Command `shop:auctions-tick`. `registerCommandSchedules()` schedules it `everyMinute()->onOneServer()` inside `$this->app->booted()`, as `CoreServiceProvider::registerCommandSchedules()` does.

- [ ] **Step 1: Write the failing tests:**
  - `it('starts and closes on the tick with a grace')`: a tick at `starts_at` makes the auction `running`. A tick at `ends_at + 10 seconds` leaves it running. A tick at `ends_at + 15 seconds` closes it.
  - `it('ends with the leader and keeps the reservation')`.
  - `it('ends without bids and releases the reservation')`.
  - `it('extends once on a stalemate then fails')`: with `max_stalemate_extensions` 1, the first close moves `ends_at` by 10 minutes. The second close sets `failed_stalemate` and releases the reservation.
  - `it('is idempotent')`, Review Focus 5: two `tick()` calls with the same `$now` give one `extended` entry and one release.
  - `it('lets a commitment inside the grace win the race')`, Review Focus 5: with the anti-sniping window set to 0, a bid with `receivedAt` one second before `ends_at` is placed after a tick at `ends_at + 10 seconds`. It is accepted, and the tick at `ends_at + 15 seconds` ends the auction with that bid winning.
  - `it('cancels with a reason and refuses a closed auction')`.
  - `it('schedules the tick every minute')`: `Schedule::events()` contains `shop:auctions-tick` with expression `* * * * *`.
- [ ] **Step 2: Run them and see them fail.** Run: `php artisan test --compact Modules/Shop/tests/Feature/Auctions/AuctionLifecycleTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement `tick()`, `close()`, `cancel()`, the command and the schedule.**
- [ ] **Step 4: Run them, and every auction test.** Run: `php artisan test --compact Modules/Shop/tests/Feature/Auctions Modules/Shop/tests/Unit/Auctions`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(shop): auction tick, closing and cancellation`.

### Task 9: Audit

**Files:**
- Create: `app/Services/Auctions/AuctionAuditService.php`, `Data/AuctionAuditReport.php`, `app/Console/AuctionAuditCommand.php`
- Test: `tests/Feature/Auctions/AuctionAuditTest.php`

**Interfaces:**
- Consumes: the payload contracts of Task 4, `AuctionPriceRule`, `AuctionLedger::firstBrokenSequence()`.
- Produces:
  - `AuctionAuditReport(?int $chainBrokenAt, array $mismatches)`, readonly, with `list<string>` mismatches and `isClean(): bool`.
  - `AuctionAuditService::verify(Auction $auction): AuctionAuditReport`. It checks the chain. Then it replays the ledger in sequence, keeping floors per user and active ceilings per configuration. At each `resolution` it recomputes the outcome from the replayed positions before applying them, and compares `price`, `leader_user_ids`, `is_stalemate` and `standing`. At the end it compares `current_price`, `is_stalemate` and `ends_at` with the state.
  - Command `shop:auction-audit {auction}` prints the report and exits 0 when clean, 1 otherwise.

- [ ] **Step 1: Write the failing tests:**
  - `it('finds a clean auction clean')`: a scenario with a manual bid, two auto-bids, a revision, a cancel and a stalemate extension.
  - `it('finds a broken chain')`: a payload tampered through `toBase()` gives `chainBrokenAt`.
  - `it('finds a state that disagrees with the ledger')`: `current_price` tampered through `toBase()` gives a mismatch naming `current_price`.
  - `it('exits non-zero on a dirty auction')`: `$this->artisan('shop:auction-audit', ['auction' => $id])->assertExitCode(1)`.
- [ ] **Step 2: Run them and see them fail.** Run: `php artisan test --compact Modules/Shop/tests/Feature/Auctions/AuctionAuditTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement the service, the report and the command.**
- [ ] **Step 4: Run them.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(shop): auction ledger audit`.

### Task 10: Documentation and closing

**Files:**
- Modify: `Modules/Shop/docs/rag/MODULE.md`, `Modules/Shop/README.md`
- Modify: this plan, `docs/superpowers/plans/INDEX.md`

- [ ] **Step 1: `MODULE.md`.** Add an "Auctions" section describing the shipped domain:
  - the four rules: commitment, price, stalemate, time;
  - the five tables and which are frozen;
  - the services and their entry points;
  - the two commands;
  - the config keys;
  - what is not built yet: API, Filament, notifications, settlement, the `Party` check.
- [ ] **Step 2: README.** Add "Auction domain, slice 1" to Current Status, in the existing style. The config keys are not environment variables, so the Configuration section stays as it is.
- [ ] **Step 3: Pint and the whole module.** Run `vendor/bin/pint --format agent <every PHP file of this plan>` from `laraplate/`, then `php artisan test --compact Modules/Shop`. Expected: PASS.
- [ ] **Step 4: Close the plan.** Add `## Delivery status (date): ...` with the divergences, and a `**Documented in:** Modules/Shop/docs/rag/MODULE.md` line. Tick the steps. Update the entry in `docs/superpowers/plans/INDEX.md`.
- [ ] **Step 5: Commit** `docs(shop): auction domain` in `Modules/Shop`, and the plan in `laraplate/`.
