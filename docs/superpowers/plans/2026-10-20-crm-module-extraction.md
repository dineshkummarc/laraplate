---
status: closed
created_on: 2026-10-20
---
# CRM module extraction (parity phase). Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax. Tick each step when it is done, in the same work block.

**Goal:** `Modules/CRM` owns `Counterparty`, `Contact`, `Lead`, `Opportunity` and the pipeline stages; the ERP `Party` becomes `Counterparty x Company`; the application behaves as ERP M3.1 does today.

**Architecture:** the CRM is a native module upstream of the ERP (`Core <- CRM <- ERP`). `Party` reads and writes identity attributes through a proxy trait over its `Counterparty` (D15), attaching by fiscal identifier on create (D16). The ERP writes into the CRM (aggregate flags, opportunity lifecycle); the CRM imports no ERP class and reads no ERP table.

**Tech Stack:** PHP 8.5, Laravel 12, Filament 5, Pest, nwidart modules; SQLite for tests, migrations portable to MySQL, PostgreSQL, Oracle.

**Spec:** `docs/superpowers/specs/2026-10-15-crm-module-design.md` (D1 to D26). Executors read it with this plan.

## Global Constraints

- Every PHP file: `declare(strict_types=1);`, explicit types, `final` classes, `#[Override]`, braces on every control structure. Run `vendor/bin/pint --dirty --format agent` before each commit.
- Names: entity `Counterparty` (never `Account`: `Modules\ERP\Models\Account` is the chart of accounts). Enum `Modules\CRM\Enums\CRMTables` with `HasModuleTablesUtils`; case name must be the plural of the model class name (`Contactables` resolves to `Pivot\Contactable`). Tables: `crm_counterparties`, `crm_counterparty_bank_accounts`, `crm_contacts`, `crm_contactables`, `crm_leads`, `crm_opportunities`. Migrations and models reference the enum, never a literal table name.
- No installation exists: rewrite ERP create-table migrations in place; CRM migrations are stamped before `2026_04_08_100000` (Laravel orders all migrations by file name) so the foreign keys from `erp_*` resolve.
- The CRM never imports `Modules\ERP\*` and never uses `DB::` or `ConnectionScoped*` (frozen by `AGENTS.md`); queries and transactions derive from the owning model.
- Tests live in the owning module under `tests/`; no class, trait, enum or interface is declared inside a test file (use `tests/Stubs/` or `tests/Support/`); run only the scope you changed: `php artisan test --compact <path or --filter>`. The full suite is run by the user.
- No new dependency, no new base folder. Module colours are unique: CRM `bright-green`, Shop `bright-magenta`.
- Commit inside each submodule repo (`Modules/CRM`, `Modules/ERP`, `Modules/Shop`, `Modules/MES`), then bump the pointers in `laraplate` in one commit. Never push without asking. Version bumps are proposed to the user at commit time (`08-versioning.mdc`).

## Review Focus

1. A `Party` created with no VAT number and no tax id must create its own `Counterparty`: two such parties must never attach to the same one (null does not match null).
2. SQL-level use of identity columns: after the move `parties` no longer has `name`, `vat_number` and the rest, so every `where`, `orderBy`, `pluck`, search and Filament `relationship('party', 'name')` on them breaks even though `$party->name` works. Searching and ordering parties by name must keep working in every form and table.
3. Creating a `Party` for an existing fiscal identifier attaches to the existing `Counterparty` and never overwrites its stored attributes.
4. Deleting a `Counterparty` that has a `Party` is refused; one without is soft-deleted and its leads and opportunities stay visible.
5. A quotation for a `Party` that is not a customer is accepted; accepting it sets `is_customer` and marks the opportunity won; the `SalesOrder` check on `is_customer` still holds.
6. Listing parties must not issue one query per row (the `Counterparty` is eager-loaded).

---

## Tasks

### Task 1: CRM foundation and Counterparty

**Files:**
- Create: `Modules/CRM/app/Enums/CRMTables.php`, `app/Casts/CounterpartyKind.php`, `app/Models/Counterparty.php`, `database/migrations/2026_04_08_090000_create_counterparties_table.php`, `database/factories/CounterpartyFactory.php`
- Modify: `Modules/CRM/module.json` (keeps `requires: ["Core"]`)
- Test: `Modules/CRM/tests/Feature/CounterpartyTest.php`

**Interfaces:**
- Produces: `CRMTables::Counterparties = 'crm_counterparties'`; `CounterpartyKind` backed enum `Company = 'company'` (default), `Person = 'person'`; `Counterparty` with fillable `name, kind, tax_id, vat_number, fiscal_country, address_line, postal_code, city, province, country, einvoice_recipient_code, einvoice_pec_email, parent_counterparty_id, is_customer, is_supplier` (the two flags are not mass-assignable from CRM forms, written by the ERP); `parent(): BelongsTo`, `children(): HasMany`; `static findByFiscalIdentity(?string $fiscalCountry, ?string $vatNumber, ?string $taxId): ?self` (returns `null` when both `vatNumber` and `taxId` are null or blank; matches `fiscal_country` + `vat_number`, else `tax_id`); soft delete; `CounterpartyFactory` with states `company()`, `person()`.

- [x] **Step 1: Failing tests** in `CounterpartyTest`: `it('defaults kind to company')`; `it('finds by country and vat number')`; `it('finds by tax id when there is no vat number')`; `it('returns null for blank identifiers')` (two blanks never match); `it('refuses to be its own ancestor')` (setting `parent_counterparty_id` to itself or a descendant throws `ValidationException`).
- [x] **Step 2: Run** `php artisan test --compact Modules/CRM/tests/Feature/CounterpartyTest.php`. Expected: FAIL, class not found.
- [x] **Step 3: Implement** enum, cast, model (validation rules in `getRules()` per the Laraplate model standard, ancestor check in a `saving` hook), migration (`MigrateUtils::timestamps` with soft delete, indexes on `vat_number`, `tax_id`, `parent_counterparty_id` named `{table}_{column}_idx`/`_FK`), factory.
- [x] **Step 4: Run** the same test. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/CRM`: `feat(crm): Counterparty with legal identity and hierarchy`.

### Task 2: Party over Counterparty (proxy, attach, schema)

**Files:**
- Create: `Modules/ERP/app/Concerns/ProxiesCounterparty.php`, `app/Listeners/GuardCounterpartyDeletion.php`, `app/Observers/PartyObserver.php`
- Modify: `Modules/ERP/app/Models/Party.php`, `database/migrations/2026_04_08_191717_create_parties_table.php`, `database/factories/PartyFactory.php`, `app/Providers/ERPServiceProvider.php`, `Modules/ERP/module.json` (`requires` gains `CRM`)
- Test: `Modules/ERP/tests/Feature/Models/PartyCounterpartyTest.php`

**Interfaces:**
- Consumes: `Counterparty`, `Counterparty::findByFiscalIdentity`.
- Produces: trait `ProxiesCounterparty` giving `Party` `counterparty(): BelongsTo`, an always-loaded relation (`$with`), an explicit constant list `COUNTERPARTY_ATTRIBUTES` (the identity columns of Task 1 except flags and hierarchy) read and written through the relation, and `scopeSearchIdentity(Builder $query, string $term): Builder` and `scopeOrderByName(Builder $query, string $direction = 'asc'): Builder` (join on `crm_counterparties`, qualified columns). `Party` keeps `company_id`, `is_customer`, `is_supplier` and gains `counterparty_id` NOT NULL, unique (`counterparty_id`, `company_id`), `restrictOnDelete`.

- [x] **Step 1: Failing tests** in `PartyCounterpartyTest`: `it('creates a counterparty on first save')`; `it('reads and writes identity attributes through the party')`; `it('attaches to the existing counterparty with the same vat number and keeps its stored name')` (D16); `it('does not attach two parties with no identifier to one counterparty')` (Review Focus 1); `it('allows one party per company for a counterparty but not two')`; `it('refuses a party for an organisational counterparty')` (D12: a counterparty that has children and neither `vat_number` nor `tax_id`; a childless counterparty without identifiers is fine); `it('refuses to delete a counterparty that has a party')`; `it('recomputes the counterparty flags when a party is saved or deleted')` (D7); `it('loads parties with their counterparty without one query per row')` (query count constant for 10 parties).
- [x] **Step 2: Run** the test file. Expected: FAIL.
- [x] **Step 3: Implement** the trait (reads/writes in `getAttribute`/`setAttribute`, `save()` resolves the counterparty then saves it if dirty, in one transaction on the party connection), the `deleting` listener on `Counterparty` registered in `ERPServiceProvider::boot` (ERP depends on CRM, not the reverse), the `PartyObserver::saved/deleted` that recomputes `is_customer`/`is_supplier` of the counterparty with `exists` queries on `Party`; rewrite the parties migration (identity columns removed, `counterparty_id` added); update `PartyFactory` to build a `Counterparty` and keep the same states `customer()` and `supplier()`.
- [x] **Step 4: Run** the test file. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/ERP`: `feat(erp): Party over Counterparty`.

### Task 3: Party consumers that use identity columns in SQL

**Files:**
- Modify: Filament forms and tables that select, search or order parties by identity columns: `Projects/Schemas/ProjectForm.php`, `PaymentRequests/Schemas/PaymentRequestForm.php`, `Payments/Schemas/PaymentForm.php`, `Quotations/Schemas/QuotationForm.php`, `SalesOrders/Schemas/SalesOrderForm.php`, `PurchaseOrders/Schemas/PurchaseOrderForm.php`, `Contacts/Schemas/ContactForm.php`, `Parties/Schemas/PartyForm.php`, `Parties/Tables/PartiesTable.php`, `PartyResource.php` (global search), `app/Import/PartyImporter.php`
- Test: `Modules/ERP/tests/Feature/Filament/PartyIdentityQueriesTest.php`

- [x] **Step 1: Audit.** Run `grep -rnE "relationship\('party'|Party::query\(\)|query\(Party::class\)|orderBy\('name'\)|pluck\('name'" Modules/ERP/app Modules/MES/app Modules/Shop/app` and list every hit that touches `name`, `vat_number`, `tax_id`, `city` or `fiscal_country` in SQL; the list goes into the task's commit message.
- [x] **Step 2: Failing tests**: `it('searches parties by name in the sales order form')`, `it('orders party options by name')`, `it('searches parties by vat number in the party table')`, `it('imports parties by creating or attaching counterparties')` (importer: same VAT twice gives one counterparty). Expected: FAIL with an unknown-column error.
- [x] **Step 3: Implement** with the `searchIdentity` and `orderByName` scopes of Task 2; `getOptionLabelFromRecordUsing` replaces `relationship('party', 'name')`; the importer builds a `Party` with identity attributes and lets D16 attach.
- [x] **Step 4: Run** `php artisan test --compact Modules/ERP/tests/Feature/Filament Modules/ERP/tests/Feature/Import`. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/ERP`: `refactor(erp): party identity queries through the counterparty`.

### Task 4: Contact and Contactable move to the CRM

**Files:**
- Create: `Modules/CRM/app/Models/Contact.php`, `app/Models/Pivot/Contactable.php`, migrations `2026_04_08_090100_create_contacts_table.php`, `2026_04_08_090200_create_contactables_table.php`, `database/factories/ContactFactory.php`; add cases `Contacts`, `Contactables` to `CRMTables`
- Modify: delete `Contact`, `Contactable`, their ERP migrations and factory, the cases in `ERPTables`; `Party::contacts()` becomes a pass-through to `counterparty->contacts`
- Test: `Modules/CRM/tests/Feature/ContactTest.php`; move `Modules/ERP/tests/Feature/Models/ErpPivotModelsTest.php` cases for contactables

**Interfaces:** `Contact` keeps `name`, `email`, `phone`, the `User` link and `counterparties(): BelongsToMany` through `Contactable` (composite primary key `counterparty_id`, `contact_id`, no extra attributes, D17). `Counterparty::contacts(): BelongsToMany`.

- [x] **Step 1: Failing tests**: `it('links a contact to several counterparties')`; `it('keeps the user link and hides user_id')`; `it('removes the link rows when a counterparty is hard deleted')`.
- [x] **Step 2: Run** `php artisan test --compact Modules/CRM/tests/Feature/ContactTest.php`. Expected: FAIL.
- [x] **Step 3: Implement** the models and migrations by moving the ERP ones (read the ERP `Contact` first for the `User` temp-user logic and keep it), update every ERP reference with `grep -rn "Models\\\\Contact" Modules`.
- [x] **Step 4: Run** the CRM test and `php artisan test --compact Modules/ERP/tests/Feature/Models`. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/CRM` and `Modules/ERP`: `feat(crm): Contact moves from ERP`.

### Task 5: Lead, Opportunity, stages and lifecycle move to the CRM

**Files:**
- Create in CRM: `app/Models/{Lead,Opportunity,OpportunityStage,Entity,Preset}.php`, `app/Models/Pivot/Presettable.php`, `app/Casts/{LeadStatus,OpportunityStatus,EntityType}.php` (`EntityType::OpportunityStages = 'opportunity_stages'`), `app/Services/OpportunityLifecycleService.php`, migrations `2026_04_08_090300_create_leads_table.php`, `2026_04_08_090400_create_opportunities_table.php`, factories; cases `Leads`, `Opportunities`
- Modify in ERP: remove the moved models, casts, migrations, `ERPTables` cases, `OpportunityLifecycleService`; `Models/Quotation.php`, `Observers/QuotationObserver.php`, `Models/Company.php`, `Models/Party.php` (drop `leads()`, `opportunities()`)
- Test: `Modules/CRM/tests/Feature/LeadAndOpportunityTest.php`, `Modules/ERP/tests/Feature/QuotationOpportunityAlignmentTest.php` (existing, adapted)

**Interfaces:**
- Produces: `Lead` (`counterparty_id` nullable, `contact_id` nullable, no `company_id`), `Opportunity` (`counterparty_id` NOT NULL `restrictOnDelete`, `lead_id` nullOnDelete, `stage_taxonomy_id`, no `company_id`), `OpportunityLifecycleService::markWonFromQuotation(int $opportunityId): void` (derives its connection from the loaded `Opportunity`, no `ConnectionScopedModels`).
- Consumes: `Counterparty`. ERP `QuotationObserver` calls the service.

- [x] **Step 1: Failing tests** in the CRM: `it('creates a lead without a counterparty')`; `it('requires a counterparty for an opportunity')`; `it('keeps the opportunity when its counterparty is soft deleted')` (D19). In the ERP: `it('accepts a quotation for a party that is not a customer')`; `it('sets the party as customer and marks the opportunity won when the quotation is accepted')`; `it('rejects an opportunity of another counterparty')` (`opportunity.counterparty_id` equals `quotation->party->counterparty_id`); `it('allows quotations of different companies on one opportunity')`; `it('still requires a customer for a sales order')`.
- [x] **Step 2: Run** both test files. Expected: FAIL.
- [x] **Step 3: Implement** by moving the code; in `Quotation::booted` remove the `is_customer` and `company_id` checks and rewrite the opportunity check per D8; the observer promotes the party on acceptance. Remove the `is_customer` check from `Lead::booted` and the customers-only filters of the lead, opportunity and quotation forms (Task 6 moves the forms).
- [x] **Step 4: Run** the CRM test, `Modules/ERP/tests/Feature/QuotationOpportunityAlignmentTest.php` and `Modules/ERP/tests/Feature/CrmLeadsAndOpportunitiesSchemaTest.php` (moved to the CRM). Expected: PASS.
- [x] **Step 5: Commit** in `Modules/CRM` and `Modules/ERP`: `feat(crm): Lead, Opportunity and stages move from ERP`.

### Task 6: Filament, pipeline report, language

**Files:**
- Create in CRM: `app/Filament/CRMPlugin.php`, `app/Filament/Resources/{Counterparties,Contacts,Leads,Opportunities}/**` (moved from ERP where they exist), pipeline page, `app/Services/SalesPipelineService.php` (moved), `lang/{de,en,es,it,sl}/crm.php`
- Modify: delete the moved ERP resources, page and service; `ERP` lang files lose the moved keys
- Test: `Modules/CRM/tests/Feature/Filament/CRMPluginTest.php`, moved `SalesPipelineServiceTest.php`, `OpportunityStageTaxonomy` support helper

**Interfaces:** `CRMPlugin` implements `Filament\Contracts\Plugin` with `ModuleFilamentPlugin`, id `crm`, module name `CRM`; navigation group keys `crm.navigation.master_data` (`Counterparty`, `Contact`) and `crm.navigation.pipeline` (`Lead`, `Opportunity`, pipeline page). `CounterpartyResource` exposes identity fields and the two flags read-only.

- [x] **Step 1: Failing tests** mirroring `ERPPluginTest`: `it('exposes crm plugin metadata and boots without error')`; `it('registers every navigation group used by the crm resources and pages')`; moved `SalesPipelineServiceTest` cases; `it('lists counterparties with customer and supplier badges')`; `it('attaches a new lead to the counterparty that already has the same vat number')` (D10, in the create-lead page, matching suppliers too); `it('filters pure suppliers out by default and lets the user clear the filter')` (D9).
- [x] **Step 2: Run** `php artisan test --compact Modules/CRM/tests/Feature/Filament`. Expected: FAIL.
- [x] **Step 3: Implement.** Read `Modules/ERP/routes/api.php` and move any route that concerns a moved entity to `Modules/CRM/routes/api.php` (D25: same generic CRUD routes, none new). Before moving `SalesPipelineService`, check whether `expected_value_local` uses ERP exchange rates; if it does, keep the service in the ERP and record the decision in the plan's delivery status.
- [x] **Step 4: Run** the CRM Filament tests and `php artisan test --compact Modules/ERP/tests/Feature/Filament`. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/CRM` and `Modules/ERP`: `feat(crm): Filament plugin and resources`.

### Task 7: Seeders and permissions

**Files:**
- Create: `Modules/CRM/database/seeders/CRMDatabaseSeeder.php`, `DevCRMDatabaseSeeder.php`
- Modify: `Modules/ERP/database/seeders/{ERPDatabaseSeeder,DevERPDatabaseSeeder}.php`, `Modules/ERP/tests/Feature/Seeders/DevERPDatabaseSeederTest.php`
- Test: `Modules/CRM/tests/Feature/Seeders/CRMDatabaseSeederTest.php`

- [x] **Step 1: Failing tests**: `it('seeds the opportunity stage entity and preset once')` (idempotent: run twice, same counts); `it('seeds crud permissions for the crm entities')`; `it('seeds demo prospects without a party and demo leads')` (dev); ERP dev test: `it('creates customer parties whose counterparties exist and quotations on crm opportunities')`.
- [x] **Step 2: Run** the two files. Expected: FAIL.
- [x] **Step 3: Implement** per D24: the CRM seeder takes the `opportunity_stage` entity and preset and the permissions from `ERPDatabaseSeeder`; the ERP dev seeder creates `Party` from identity attributes; `priority` stays 10 for CRM and 20 for ERP. The moved entities use the generic Core policy (the ERP `ERPModelPolicy` list names none of them).
- [x] **Step 4: Run** the same files. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/CRM` and `Modules/ERP`: `feat(crm): seeders and permissions`.

### Task 8: Bank accounts split

**Files:**
- Create: `Modules/CRM/app/Models/CounterpartyBankAccount.php`, migration `2026_04_08_090500_create_counterparty_bank_accounts_table.php`, factory; case `CounterpartyBankAccounts`
- Modify: `Modules/ERP/app/Models/PartyBankAccount.php` and its migration, `app/Services/Payments/PaymentRunBuilderService.php`, SEPA exporters, bank reconciliation, the factories and tests that create `PartyBankAccount`
- Test: `Modules/CRM/tests/Feature/CounterpartyBankAccountTest.php`, `Modules/ERP/tests/Feature/Services/PaymentRunBuilderServiceTest.php` (adapted)

**Interfaces:** `CounterpartyBankAccount` carries `beneficiary_name, iban, bic, currency, is_active`; `PartyBankAccount` keeps `company_id, party_id, is_default` and the `direct_debit_mandate_*` fields (they belong to the creditor company) and gains `counterparty_bank_account_id`. Reads of `iban`, `bic`, `beneficiary_name`, `currency` on `PartyBankAccount` go through the link.

- [x] **Step 1: Check the premise.** Read `PaymentRunBuilderService`, the SEPA exporters and `BankReconciliationService` for every use of the moved columns; if a column other than the five listed is company-specific, move it back to `PartyBankAccount` and note the divergence in the delivery status.
- [x] **Step 2: Failing tests**: `it('shares one bank account between the parties of two companies')`; `it('keeps mandate and default flag per company')`; the payment-run test builds a run from the linked account's iban; `it('hides bank accounts from users without the counterparty bank account ability')` (D20).
- [x] **Step 3: Run** both files. Expected: FAIL.
- [x] **Step 4: Implement** and run `php artisan test --compact Modules/ERP/tests/Feature/Services` for the payment and SEPA tests. Expected: PASS.
- [x] **Step 5: Commit** in `Modules/CRM` and `Modules/ERP`: `feat(crm): counterparty bank accounts`.

### Task 9: Documentation, manifests, closing

**Files:**
- Create/modify: `Modules/CRM/README.md`, `Modules/CRM/docs/` (user and developer notes), `Modules/CRM/docs/rag/MODULE.md`, `GLOSSARY.md`; `Modules/ERP/docs/rag/MODULE.md` and `GLOSSARY.md` (M3.1 and CRM sections replaced by a pointer); `Modules/ERP/README.md`; `CHANGELOG.md` in CRM and ERP; `modules_statuses`-style activation is done by `php artisan module:enable CRM` (database activator, not the JSON file)
- Modify: `docs/superpowers/plans/INDEX.md`, `docs/superpowers/specs/INDEX.md`

- [x] **Step 1: Write the module docs** from the moved sections; state in the finishing message whether RAG docs were skipped and why (`AGENTS.md`).
- [x] **Step 2: Verify the scopes** `php artisan test --compact Modules/CRM`, then `Modules/ERP/tests/Feature/Models`, `.../Filament`, `.../Services`, `.../Seeders`, plus `Modules/MES/tests/Feature/SalesOrderProductionPlannerTest.php` (its `Party::query()->withoutGlobalScopes()->create([...])` now needs identity attributes: adapt it) and the Shop tests that create orders. Expected: PASS. The user runs the full suite.
- [x] **Step 3: Bump pointers** of `Modules/CRM`, `Modules/ERP`, `Modules/MES`, `Modules/Shop` in `laraplate`; propose version bumps to the user (ERP major, CRM first release); do not push.
- [x] **Step 4: Close the plan:** set `status: closed`, add `## Delivery status (2026-10-21)

Delivered. `Modules/CRM` owns `Counterparty`, `CounterpartyBankAccount`, `Contact`, `Lead`, `Opportunity` and the pipeline stages; the ERP `Party` is `Counterparty x Company`. Final gate: `php artisan test --compact Modules/CRM Modules/ERP/tests Modules/MES/tests/Feature/SalesOrderProductionPlannerTest.php` passed (771 passed, 2 skipped); Shop and the MES feature tests were run as well.

Divergences from the plan, all ruled in the ledger:

- **Sales pipeline stays in the ERP.** `SalesPipelineService`, its page and the CSV exporter remain in the ERP (the exporter is ERP-only and the CRM must not import ERP classes). `generate()` lost its `company_id` argument because an opportunity has no company.
- **`PartyBankAccount` stays in the ERP** as the per-company link (company, default flag, direct debit mandate); only iban, bic, beneficiary name, currency and active flag moved to `CounterpartyBankAccount`. `ERPTables` keeps `PartyBankAccounts`.
- **Counterparty loaded in `newCollection`**, not through `$with`, so partial selects (`value('is_customer')`) stay readable; the same for the bank account link.
- **Soft-deleted parties do not block** a soft delete of the counterparty (the foreign key still blocks a hard delete).
- **Seeders**: `CRMDatabaseSeeder` and `DevCRMDatabaseSeeder` arrived with Task 5, not Task 7, because the stage tests needed them; the demo ERP data has no opportunity-linked quotations, so the planned link between ERP demo quotations and CRM opportunities had nothing to attach to. CRUD permissions need no code: `permission:refresh` generates them from the enabled models.
- **Latent ERP bug fixed on the way**: the opportunity form selected the stage through `relationship('stage', 'name')`, a column that does not exist on `core_taxonomies`; it now lists stage options explicitly.
- **Quotation form** drops the customers-only filter at Task 3, the model check at Task 5.
- **Test fixtures** that created several parties with one fixed VAT number for the same company now use unique VAT numbers: with the attach rule and the unique (counterparty, company) key the second party would be the same one.

Fresh whole-branch review (one pass, each fix with a test that fails when reverted):

- Fixed: the party column of eight ERP document lists searched and sorted on `erp_parties.name` (a crash on sort, an empty result on search); it now goes through `party.counterparty.name`.
- Fixed: the Core CRUD API could not filter parties by identity attributes (`Party` now implements `IMountsRelatedAttributes`) nor create one from identity attributes (create rules accept them without `counterparty_id`). Sorting and selecting columns by identity attributes were added afterwards in Core (see below).
- Fixed: a soft-deleted counterparty with the same VAT number was ignored and duplicated; it is now matched and restored. Same for a trashed counterparty bank account with the same IBAN. Restoring a party restores its trashed counterparty.
- Fixed: fiscal identifiers are normalised (upper case, no spaces) on save and on lookup, so `it 0123` and `IT0123` are one subject.
- Fixed: D10 was not implemented; the lead create page now takes a typed company and attaches to the counterparty with the same fiscal identity or name (suppliers included), creating one only when nothing matches.
- Minors, fixed right after (each with a test that failed first): a party that moves to another counterparty refreshes the flags of the one it leaves; the party observer recomputes the aggregates only on create, delete, restore or when a flag or the counterparty changes; D12 is now enforced in both directions (an ERP listener refuses a group parent without identifiers that already has a party). The fourth minor, an empty counterparty select when the subject of a lead is in the trash, could not be reproduced: the select shows the trashed subject and the lead saves; the test that proves it stays.

- Core, afterwards: the CRUD query builder orders the rows of the main model by a column of a to-one relation (`sort=relation.column`, through a correlated subquery, honouring the related-entity read permission; a to-many relation keeps ordering its loaded records) and, for a model that implements `IMountsRelatedAttributes`, turns a mounted attribute (`name`) into that relation column for sorting and for requested columns, so a client of `Party` does not need to know where the identity lives. Only mounted attributes that are real columns of the related table are covered; translated or dynamic fields of a mounted record (a `Product` title) are not.

Version bumps are left to the user (ERP major, CRM first release).

**Documented in:** `Modules/CRM/README.md`, `Modules/CRM/docs/rag/MODULE.md`, `Modules/CRM/docs/GLOSSARY.md`, `Modules/CRM/docs/rag/GLOSSARY.md`; ERP side `Modules/ERP/README.md`, `Modules/ERP/docs/GLOSSARY.md`, `Modules/ERP/docs/rag/GLOSSARY.md`, `Modules/ERP/docs/rag/MODULE.md`, `Modules/ERP/docs/ERP_GUIDA_SEMPLICE.md`.
