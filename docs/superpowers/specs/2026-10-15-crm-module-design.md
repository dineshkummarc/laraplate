# CRM Module: Extraction from ERP and the Counterparty / Party Split

**Status:** Approved 2026-10-20, implemented 2026-10-21 (see the plan `plans/2026-10-20-crm-module-extraction.md` for the divergences). Decisions taken in conversation with the user on 2026-10-15. Nothing is implemented beyond an empty module skeleton: `Modules/CRM` is a submodule of `laraplate` (repository `swolley/laraplate-crm`, initial commit `64f1ca8`), not yet listed in `modules_statuses.json`.

**Scope:** backend only (`laraplate`). Touches the new `CRM` module, `ERP` (M3.1 extraction, `Party` split), `Core` (nothing new in phase 1) and, for the dependency check, `Shop`, `MES`, `SAO`.

## 1. Goal

Create a `CRM` module that owns the commercial relationship with a counterparty: who it is (`Counterparty`), who works there (`Contact`), and the sales pipeline (`Lead`, `Opportunity`). The ERP depends on the CRM, never the reverse. The CRM must be installable without the ERP; the ERP requires the CRM.

Phase 1 is a **parity move**: reach the same functional state as ERP M3.1 today, with the entities living in the CRM. What a CRM should contain beyond that is a separate analysis (section 11).

## 2. Dependency direction

```
Core  <-  CRM  <-  ERP  <-  Shop, MES
```

- CRM `requires` only `Core`. `module.json` priority 10 (ERP is 20, Core 0).
- ERP `module.json` `requires` gains `CRM`. Shop and MES receive it transitively.
- ERP writes into CRM (flags, opportunity lifecycle). CRM never imports an ERP class.
- This follows the existing idiom: Shop `Product` extends a CMS `Content`, MES listens to ERP events, `StockReservation` uses opaque source references.

## 3. Data model

### 3.1 Counterparty (CRM, new)

The counterparty as a real-world subject, shared by every company of the installation.

- Legal identity: `name`, `tax_id`, `vat_number`, `fiscal_country`, legal address, `einvoice_recipient_code`, `einvoice_pec_email`. These move from `Party`.
- `kind`: `company` (default) or `person`. `Party` has no such field today; the default keeps importers and factories unchanged (D18).
- `parent_counterparty_id` (nullable): optional hierarchy. A node with no legal identity is purely organisational and can never have a `Party`.
- Bank accounts of the counterparty (provisional name `CounterpartyBankAccount`, table `crm_counterparty_bank_accounts`), moved from `PartyBankAccount`. An IBAN is a property of the subject and must not repeat once per company.
- Aggregate customer and supplier columns, written by the ERP (D7), read-only for CRM users.
- No `company_id`, no tenant column.

### 3.2 Party (ERP, reduced)

The relationship between a `Counterparty` and one `Company`.

- Keeps `company_id`, `is_customer`, `is_supplier` and its relations (`PartyPriceRule`, documents). Only data whose value differs by nature from company to company stays here: flags, price rules, and in future payment terms and ledger account.
- Gains `default_bank_account_id` (the counterparty bank account this company uses by default) and, if they exist, SEPA mandates, which are per creditor company. Both to be verified against `PartyBankAccount` (section 12).
- Gains `counterparty_id` NOT NULL. Unique on (`counterparty_id`, `company_id`).
- `Party` is created the first time a fiscal document needs it (a quotation, not a confirmed order). A prospect with an open quotation already has a `Party`.
- Existing `party_id` foreign keys on `Quotation`, `SalesOrder`, `Invoice`, `Payment`, and the rest stay valid.

### 3.3 Other entities

| Entity | From | To | Table | Notes |
|---|---|---|---|---|
| `Contact` | ERP | CRM | `crm_contacts` | Keeps the link to `User`. |
| `Contactable` (pivot) | ERP | CRM | `crm_contactables` | Moves from `Party` to `Counterparty`; composite primary key (`counterparty_id`, `contact_id`), no extra attributes, unchanged otherwise (D17). |
| `Lead` | ERP | CRM | `crm_leads` | `party_id` becomes `counterparty_id` (nullable). Loses `company_id`. |
| `Opportunity` | ERP | CRM | `crm_opportunities` | `party_id` becomes `counterparty_id` (NOT NULL). Loses `company_id`. |
| `OpportunityStage` | ERP | CRM | Core taxonomy | A `Taxonomy` subclass bound to the module `Entity`, `Presettable` and `EntityType`. The CRM gets its own `Entity`, `Pivot\Presettable` and `Casts\EntityType` (case `OpportunityStages`), the per-module pattern Shop already follows. |
| `SalesPipelineService`, `SalesPipelinePage`, CSV export | ERP | CRM | service, page | First left in the ERP because the export looked ERP-only; it is a thin wrapper over Core `TabularCsvExporter`, so everything moved to the CRM and the report lost its company filter (an opportunity has no company). |
| `LeadStatus`, `OpportunityStatus` | ERP | CRM | casts | Namespace change only. |
| `OpportunityLifecycleService` | ERP | CRM | service | Called by the ERP `QuotationObserver`. |
| Filament `Leads`, `Opportunities` resources | ERP | CRM | | `Parties` and `Contacts` resources are split or reviewed. |
| `ERPTables` Leads/Opportunities/Contacts/Contactables | ERP | CRM | `CRMTables` | New enum, prefix `crm_`. |

Moved to the `Counterparty` side: the bank accounts of the counterparty (`PartyBankAccount` becomes `CounterpartyBankAccount`, see 3.1).

Not moved: `Quotation`, `QuotationItem`, `SalesOrder`, `Invoice`, `PartyPriceRule`, `Company`, `QuotationObserver`.

### 3.4 Naming: tables, enum cases, classes

Every table name is prefixed by the lowercase module name (`crm_`) and declared in the module enum `CRMTables` (`Modules\CRM\Enums\CRMTables`, using `HasModuleTablesUtils`). That trait derives the model class from the case name: `Str::singular(case name)` under `Modules\CRM\Models`, with a `Pivot\` sub-namespace when the case name ends in `les` or starts with `Has`. Case and class names must therefore match.

| Enum case | Table | Model class |
|---|---|---|
| `Counterparties` | `crm_counterparties` | `Modules\CRM\Models\Counterparty` |
| `CounterpartyBankAccounts` | `crm_counterparty_bank_accounts` | `Modules\CRM\Models\CounterpartyBankAccount` |
| `Contacts` | `crm_contacts` | `Modules\CRM\Models\Contact` |
| `Contactables` | `crm_contactables` | `Modules\CRM\Models\Pivot\Contactable` |
| `Leads` | `crm_leads` | `Modules\CRM\Models\Lead` |
| `Opportunities` | `crm_opportunities` | `Modules\CRM\Models\Opportunity` |

Rules that follow:

- Migrations, models, factories and tests reference `CRMTables`, never a literal table name.
- The cases `Leads`, `Opportunities`, `Contacts` and `Contactables` are removed from `ERPTables`, since those tables leave the ERP. `Parties` stays (`erp_parties`), and so does `PartyBankAccounts` (`erp_party_bank_accounts`): it is the per-company link to the counterparty bank account, because it carries the company, the default flag and the direct debit mandate.
- The stage nodes stay in Core `taxonomies`. `Entity`, `Presettable` and the preset tables are the shared `core_entities`, `core_presets`, `core_presettables`, discriminated by the module `EntityType`, as in Shop; the CRM adds no table for them and its `EntityType` has the case `OpportunityStages`.
- `crm_counterparty_bank_accounts` is 30 characters and derived foreign key and index names are longer. The repository supports Oracle, so check the identifier length limit in force before fixing the names (not verified; `MigrateUtils` does not enforce a length).
- Foreign key and index names follow the existing style (`{table}_{column}_FK`).

### 3.5 Filament presence

Each module with a backoffice owns a plugin (`CMSPlugin`, `CorePlugin`, `ERPPlugin`, `MESPlugin`, `SAOPlugin`); the panel auto-registers module plugins (`config/filament-modules.php`, `auto-register-plugins`). The CRM follows the same shape:

- `Modules\CRM\Filament\CRMPlugin` with `ModuleFilamentPlugin`, id `crm`, which in `afterRegister` registers the module colour (from the `color` key of `module.json`) and the CRM navigation groups.
- Navigation group keys are translation keys prefixed by the module, as in the ERP (`erp.navigation.sales`). Proposal: `crm.navigation.master_data` (`Counterparty`, `Contact`) and `crm.navigation.pipeline` (`Lead`, `Opportunity` and the sales pipeline report page). Labels live in `Modules/CRM/lang/{de,en,es,it,sl}/crm.php`, the locales the ERP ships.
- Resources move with their entities: `Leads` and `Opportunities` leave the ERP group `erp.navigation.sales`; a new `Counterparties` resource and the `Contacts` resource live in the CRM. The ERP `Parties` resource stays in `erp.navigation.master_data` and keeps editing the identity fields through the transparent proxy (D15), so its form does not change.
- A `CRMPluginTest` mirrors `ERPPluginTest`: the plugin exposes its id and module name, and every `navigationGroup` used by a CRM resource or page is registered.

## 4. Decisions

- **D1.** The CRM is mandatory for the ERP. `Party.counterparty_id` is NOT NULL and the `Counterparty` is created transparently on first `Party` save. The idiom is that of Shop `Product` over a CMS `Content` (transparent merge: always-loaded relation, attribute proxy, save bridge), but the code of `ExtendsContentTrait` is not reusable: it is tied to `Content` (`extended_type`, `HidesExtendedContent`, search indexing, cascade) and assumes a 1:1 link, while here many `Party` rows point to one `Counterparty`. The ERP gets its own small trait; `ContentExtenderRegistry` is not needed because the `Counterparty` does not need to know its extenders.
- **D2.** `Party` does not move to Core. It carries `BelongsToCompany`, and Core must not know `Company`.
- **D3.** The CRM has no `Company` and no tenant. SaaS multi-tenancy is one database per tenant, which gives the same result as an on-premise group installation and keeps the application code single-tenant.
- **D4.** The CRM is a native module: it shares the schema and the connection of Core, CMS, AI, ERP, MES and SAO, and cross-module foreign keys are allowed (`Party.counterparty_id` is a real foreign key). Per `AGENTS.md`, `core.model_connections` and `erp.model_connections` are frozen and `ConnectionScoped*` / `ErpConnectionContext` must not be extended to new code, so the CRM does not use them; it derives queries and transactions from the owning model. `OpportunityLifecycleService` and any other code moved from the ERP that uses `ConnectionScopedModels` (for example `markWonFromQuotation`) is rewritten without it when it moves.
- **D5.** `Counterparty` is shared by all companies of the group; `Party` is per company. One `Counterparty` can be customer of company A, supplier of company B and both for company C.
- **D6.** Confirmed 2026-10-15: all identity data lives on `Counterparty` so that nothing repeats once per company: legal identity, e-invoice routing (`einvoice_recipient_code`, `einvoice_pec_email`) and the counterparty bank accounts. `Party` keeps only what differs by nature per company (flags, price rules, default bank account choice, SEPA mandates if any).
- **D7.** `is_customer` and `is_supplier` stay on `Party`, per company, as manual role flags exactly as today. The `Counterparty` carries two stored aggregate columns (`is_customer`, `is_supplier`: at least one of its parties has the flag), read-only for CRM users and recomputed by an ERP observer whenever a `Party` is saved or deleted. They are stored, not computed, because the CRM must not read any ERP table (section 2). The ERP scopes `customers()` / `suppliers()` are unchanged.
- **D8.** Revised 2026-10-15 after reading the code. The flags are preconditions, not consequences: `SalesOrder`, `Project`, `ReturnOrder`, sale invoices (and today also `Quotation` and `Lead`) require `is_customer`; `PurchaseOrder`, `PaymentRunBuilderService` and `SupplierReturnService` require `is_supplier`. A flag set only on order confirmation would be circular. Therefore: the flags stay manual (form toggle, importer, factory) as in the current code; the `is_customer` check is removed from `Quotation`, `Lead` and the `Opportunity` form, so a `Party` that is not yet a customer can be quoted and linked to a lead; the checks on `SalesOrder`, `Project`, `ReturnOrder` and sale invoices stay; and one automation is added: when a quotation becomes accepted (the point where `QuotationObserver` already marks the opportunity won) its `Party` gets `is_customer = true`. Direct orders without a quotation still require the manual toggle. Supplier handling does not change.
- **D9.** No visibility filter in the CRM (no global scope hiding suppliers). Data is filterable, the list shows multiple badges, and a saved default filter can exclude pure suppliers. A `Counterparty` with no flag is just a contact; classification such as "prospect" or "potential supplier" is done with tags (a convenience automation may add "prospect" when a lead is opened).
- **D10.** Lead creation runs a duplicate check that includes every existing `Counterparty` (by `vat_number`, `tax_id` and name) and attaches to an existing one instead of creating a second.
- **D11.** The counterparty hierarchy is `parent_counterparty_id` on `counterparties`, not on `taxonomies`. A taxonomy tag remains available for groups that are only a label.
- **D12.** A `Counterparty` with no legal identity cannot have a `Party`. The check lives in the ERP, where the `Party` is created.
- **D13.** Shop customers are `User` -> `Contact` -> `Counterparty`. A B2C buyer is a natural-person `Counterparty` with one `Contact` (the same person) linked to the `User`; a guest checkout creates only `Counterparty` and `Party`.
- **D14.** Closed 2026-10-21: `Quotation` and `QuotationItem` stay in the ERP. They are a sales document issued by a `Company` (company, party, price list, document numbering, revisions, lock triggers) and the chain `SalesOrder`, `Project` and `TimeEntry` points at them, so the CRM cannot own them without knowing the ERP. The CRM side is the `opportunity_id` link, the same-counterparty check and `OpportunityLifecycleService::markWon`. A CRM `Quote` is a future step (section 11), not part of the ERP major release.
- **D15.** Decided 2026-10-15: `Party` reads and writes identity attributes transparently (`$party->name`, `$party->vat_number` keep working) through the trait above, so the 19 ERP models, Filament forms, importers, factories and most tests stay unchanged. The proxy uses an explicit list of attributes. Writes through the proxy update the shared `Counterparty`, which is intended: there is one identity. See D16 for the attach policy.
- **D16.** Decided 2026-10-15: creating a `Party` with identity attributes and no `counterparty_id` attaches automatically to the existing `Counterparty` with the same (`fiscal_country`, `vat_number`), or the same `tax_id` when there is no VAT number, and creates one otherwise. A create never overwrites the attributes of an existing `Counterparty` (the stored ones prevail). Forms can still offer an explicit choice on top.
- **D17.** Decided 2026-10-15: the `Contact` pivot stays many-to-many and attribute-free for parity. A `Contact` may serve several counterparties. Role per link and primary contact are deliberately not added now; they are revisited in the mature-CRM analysis (section 11).
- **D18.** Decided 2026-10-15: `Counterparty` gets a `kind` (`company` default, `person`) and keeps a single `name`. `kind` tells who has a VAT number from who has only a tax code, and enables the B2C shop customer (D13). Separate first and last name is deliberately not added now (ERP forms, importer and e-invoicing read `name`); it is revisited in the mature-CRM analysis.
- **D19.** Decided 2026-10-15: deleting a `Counterparty` is forbidden while any `Party` points to it (fiscal documents hang off the `Party`). Without a `Party`, it is soft-deleted and its leads and opportunities stay visible with the subject in the trash; there is no cascade and no `nullOnDelete` on `Opportunity.counterparty_id`, which stays NOT NULL with `restrictOnDelete` as `party_id` is today (`Lead.party_id` is `nullOnDelete` today and `Lead.counterparty_id` stays nullable). Hard delete is possible only when no `Party`, lead or opportunity references it. `erp_parties` already has soft delete. The CRM cannot query `Party`, so the ERP enforces the rule: a `deleting` listener on `Counterparty` in the ERP aborts when a `Party` exists, and `erp_parties.counterparty_id` is a `restrictOnDelete` foreign key.
- **D20.** Decided 2026-10-15: the moved entities keep the standard CRUD abilities, now defined in the CRM module with the permissions already granted to the moved roles. Bank accounts are guarded by the standard CRUD abilities of the `CounterpartyBankAccount` entity (separate read and write), with no custom ability. To verify: how `ERPPermissions` and the seeders assign abilities, and that nothing needs moving beyond the seeder.
- **D21.** Decided 2026-10-15: the existing ERP create-table migrations for `parties`, `contacts`, `contactables`, `leads`, `opportunities` and the party bank accounts are rewritten in place, and the CRM tables are created with new CRM migrations, because no installation exists. Table names, prefixes and the module enum follow section 3.4. Version bumps are proposed at commit time per `08-versioning.mdc`: an ERP `major` and a first CRM version.
- **D22.** Decided 2026-10-15: the CRM gets its own Filament plugin and its own navigation group(s), with resources moved alongside their entities, following the other modules (section 3.5).
- **D23.** Decided 2026-10-15: module colours must be unique. The CRM uses `bright-green` (Filament Lime). Shop, which shared `magenta` with AI, moves to `bright-magenta` (Purple); AI keeps `magenta`. In use otherwise: Core `green`, CMS `cyan`, ERP `yellow`, MES `blue`, SAO `bright-red`.
- **D24.** Decided 2026-10-15: seeders. `CRMDatabaseSeeder` (production) owns the `opportunity_stage` entity and preset and the CRM permissions, all removed from `ERPDatabaseSeeder`. `DevCRMDatabaseSeeder` seeds the pipeline stages and demo prospects (`Counterparty` without `Party`), leads and opportunities. `DevERPDatabaseSeeder` keeps creating `Party` rows from identity attributes, so the `Counterparty` is created by the transparent bridge (D1, D16), and its demo quotations point at the CRM demo opportunities, so ERP dev seeding runs after CRM dev seeding. `ERP/module.json` `requires` gains `CRM`. Ordering uses the legacy `module.json` `priority` (lower runs first per the seeder spec, where MES at 2 ran before ERP at 99; CRM 10 before ERP 20) without touching Task 11 of the seeder orchestration spec; to verify that a new module needs nothing more in the dependency graph.
- **D25.** Decided 2026-10-15: API. The moved entities keep parity with today: the same generic Core CRUD routes on `/app` and, when enabled, `/api/v1` (`EXPOSE_CRUD_API`), registered by the CRM; no new custom route. To verify how the ERP registers its routes and whether any domain action (for example `create_revision`) concerns a moved entity.
- **D26.** Decided 2026-10-15: documentation. The CRM ships a `README.md` in the style of the other modules, `docs/` for users and developers, and `docs/rag/MODULE.md` and `GLOSSARY.md` receiving the M3.1 and CRM sections moved from the ERP, which keeps a short pointer to the CRM. Added env vars, if any, go in the CRM README (`AGENTS.md`).
- **D27.** Decided 2026-10-21: contact role. The role of a contact lives on the `contactables` link, not on the contact: one person can be "purchasing" at one counterparty and "consultant" at another. The role is a node of a CRM taxonomy (`EntityType::ContactRoles`, entity `contact_role`, model `ContactRole`), configured without code like the pipeline stages, stored as a nullable `role_taxonomy_id` (restrict on delete). The link also gets `is_primary`; at most one primary contact per counterparty, enforced in the pivot model by demoting the previous primary (an index cannot express it portably).
- **D28.** Decided 2026-10-21: natural persons. `Counterparty` gets nullable `first_name` and `last_name`, allowed only for `kind = person`. `name` stays the single display and search column and is derived on save as "last first" whenever either part is filled, so forms, importers, e-invoicing and the Party proxy keep reading `name`. A person created with only `name` keeps working. `Contact` keeps one `name`: it is not the legal subject.
- **D29.** Decided 2026-10-21: intercompany. `Company` gets a nullable `counterparty_id`: its own identity as a subject. A `Party` is intercompany when its counterparty is the counterparty of a `Company`; this is derived by query (`Party::intercompany()` scope, `Party::isIntercompany()`), never stored as a flag. A sale from company A to company B is an ordinary sales order of A to the party of B's counterparty, and the mirror purchase order is a separate document of B; generating the twin automatically and consolidation eliminations are not part of this decision. The link is explicit, never a side effect of saving a company: `Company::ensureCounterparty()` attaches to the counterparty with the same fiscal identity (the company `tax_id` read as VAT number, prefixed with the fiscal country when it has no prefix) or creates one from the legal identity, and the company form can pick one by hand. A counterparty that is the identity of a company cannot be deleted.

## 5. Use cases covered

1. **Single company.** A salesperson finds a prospect (`Counterparty`, `Contact`, `Lead`), the prospect answers (`Opportunity`), an offer is made (ERP `Quotation`, `Party` created if missing) and accepted (the opportunity is marked won), an order is confirmed and invoiced. The accepted quotation also makes the `Party` a customer (D8).
2. **Group, company chosen at formalisation.** Counterparties, leads and opportunities are shared; the company is chosen when the quotation or order is created.
3. **Same subject buys from several group companies.** One `Counterparty`, several `Party`, flags per company.
4. **SaaS.** One database per tenant; the CRM needs no tenant column.
5. **External group.** An organisational parent `Counterparty` groups legal child counterparties.

## 6. Invariants

- A `Party` always has exactly one `Counterparty` and exactly one `Company`; (`counterparty_id`, `company_id`) is unique.
- A `Counterparty` cannot be deleted while it has a `Party`.
- An organisational `Counterparty` (no legal identity) never has a `Party`.
- No account can be its own ancestor.
- The CRM never imports an ERP class.

## 7. Existing ERP checks affected

`Quotation::booted` (`Modules/ERP/app/Models/Quotation.php`) enforces three rules at save:

- The `Party` must be a customer. Removed (D8): a quotation may target a `Party` that is not yet a customer. The same check in `Lead::booted` and the customers-only filter in `OpportunityForm`, `LeadForm` and `QuotationForm` are removed.
- `opportunity.party_id` must equal `quotation.party_id`. It becomes `opportunity.counterparty_id` equal to `quotation.party->counterparty_id`.
- `opportunity.company_id` must equal `quotation.company_id`. The opportunity has no company any more, so the rule is removed; one opportunity may produce quotations for different companies.

Unchanged: `SalesOrder`, `Project`, `ReturnOrder` and sale invoices still require `is_customer`; `PurchaseOrder`, `PaymentRunBuilderService` and `SupplierReturnService` still require `is_supplier`; `PurchaseOrder` still compares `party.company_id` with the order company.

New: the `QuotationObserver` sets `is_customer = true` on the quotation `Party` when the quotation becomes accepted.

## 8. Impact on other modules

- **Payments:** `PaymentRunBuilderService` resolves `defaultPartyBankAccount`; SEPA exporters and bank reconciliation read the party bank account. They must read the `Counterparty` bank account chosen by `Party.default_bank_account_id`.
- **MES:** no direct references to `Party`, `Contact`, `Lead` or `Opportunity` in `app`. `SalesOrderProductionPlannerTest` creates a `Party` by hand and needs a `Counterparty`. `DevMESDatabaseSeeder` to be checked.
- **Shop:** no direct references. The checkout does not exist yet; see D13.
- **SAO:** no direct references found in `app` and `database`.
- **Seeders and factories:** `ERPDatabaseSeeder`, `DevERPDatabaseSeeder` (CRM taxonomies), `PartyFactory` and every factory creating a `Party` need a `Counterparty`.
- **Docs:** the M3.1 section of `ERP/docs/rag/MODULE.md` and the CRM block of `GLOSSARY.md` move to the CRM module.

## 9. Authorisation (later, non-blocking)

Field-level access: the bank accounts now live in the CRM, whose users are salespeople, so IBANs need their own permission, otherwise a salesperson sees supplier bank details. Row-level access on `Counterparty` (a salesperson sees only some customers) and the set of companies a user may sell for. `BelongsToCompanyScope` today reads a single `company_id` from the authenticated user; a user selling for two companies of a group needs an allowed-companies list or a context switch. Whether the Core row-level ACL supports CRM models is not verified.

## 10. Migration plan (proposal)

**No installation exists** (confirmed 2026-10-15): there is no production data to migrate, so no data migration, no table-rename migration and no merge of existing parties by tax identifier. The work is a restructuring of the schema and the code. Consequences to settle in the plan:

- Existing ERP create-table migrations are rewritten in place instead of adding alter/rename migrations (D21); developers re-run `migrate:fresh`. The versioning rule (`.cursor/rules/08-versioning.mdc`) does not forbid it and leaves the bump to be proposed at commit time.
- Migration order: `erp_parties.counterparty_id` is a foreign key to a CRM table, so the CRM migrations must run before the ERP ones. Check how module migrations are ordered (timestamp or module priority) and stamp accordingly.

Order of work, riskiest first, each step green before the next:

1. Skeleton of `Modules/CRM` (done locally).
2. `Counterparty` (identity and bank accounts) and `Party.counterparty_id`. `Party` accessors delegate identity reads to the `Counterparty`, which is eager-loaded to avoid N+1 across the 19 ERP models that touch `Party`. Payments and SEPA exporters switch to the account bank accounts.
3. `Contact` and the pivot.
4. `Lead`, `Opportunity`, `OpportunityStage`, statuses, lifecycle service, Filament resources, seeders.
5. Enum `CRMTables`, documentation and RAG docs move; `ERP/module.json` requires `CRM`.

Each step runs the tests of its own scope only, per the test-scope policy.

## 11. Deferred analysis (after parity)

- **Quotation and product catalogue (future step).** A CRM used without the ERP has no offers. Decided 2026-10-21 not to build it now. Direction when a CRM-only need appears: a `Quote` in the CRM, owned by a counterparty with an optional opportunity and contact, no company, no price list, no fiscal numbering; the ERP `Quotation` is issued from an accepted `Quote` through an explicit action, never created automatically from the ERP side, and carries an optional `quote_id` on its header. Lines are the open point: a `Quote` with lines needs a CRM product catalogue (`Item` and `PriceListItem` are ERP) and a rule to reconcile its lines with the price list, so the catalogue is designed together with it. A header-only `Quote` adds little over the `Opportunity` (expected value, status); until then `valid_until` and an attachment on the `Opportunity` cover the gap. Adding it later is additive and does not break the ERP schema.
- **Lead versus Opportunity.** Whether `Lead` stays a separate entity or merges into the pipeline.
- **Intercompany.** A `Counterparty` that is itself a `Company` of the group (sales between group companies). The current model does not cover it. Decided 2026-10-21, see D29.
- **Hierarchy aggregation.** Group totals (revenue, open opportunities) need a recursive query; only if requested.
- **Contact relationship.** Role of a contact at a counterparty (for example purchasing manager) and a primary-contact flag on the pivot; revisit when defining the mature CRM (D17). Decided 2026-10-21, see D27.
- **Natural persons.** Separate first and last name, and any person-specific data, for an advanced system (D18). Decided 2026-10-21, see D28.
- **Document printing**, see `2026-10-15-document-template-binding-design.md`.

## 12. Open items to verify before implementation

- **Name collision (resolved 2026-10-15).** `Modules\ERP\Models\Account` (table `erp_accounts`) is the accounting chart of accounts. The CRM entity is therefore named `Counterparty` and not `Account`, to avoid two `Account` entities in one application.

- `SalesPipelineService`: whether `expected_value_local` depends on ERP exchange rates (if it does, the conversion needs a seam or the service stays in the ERP).
- `Project` points to a `Party`; its foreign key stays valid. `Activity` is an ERP taxonomy for project work (not a CRM activity log) and `Task` belongs to projects: both stay in the ERP.
- How `current_company_id()` resolves the active company in the ERP code that now reads CRM entities (it is not needed in the CRM itself).
- `Contact` to `User` column name and uniqueness.
- Whether a unique index on `tax_id` / `vat_number` exists per company today (carry the rule over to `Counterparty`).
- `PartyBankAccount` columns and SEPA mandates: which fields are per company (stay on `Party`) and which belong to the counterparty (move to `Counterparty`).
