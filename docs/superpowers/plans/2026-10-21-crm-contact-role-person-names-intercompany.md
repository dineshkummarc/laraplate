---
status: closed
created_on: 2026-10-21
---
# CRM follow-ups: contact role, person names, intercompany, lead conversion. Implementation Plan

**Goal:** implement spec decisions D27 (contact role and primary contact), D28 (first and last name of natural persons) and D29 (intercompany recognition) on top of the delivered CRM extraction.

**Architecture:** D27 adds two columns to the `crm_contactables` pivot and a `contact_role` taxonomy modelled on the opportunity stages. D28 adds two nullable columns to `crm_counterparties` and derives `name` on save. D29 adds `erp_companies.counterparty_id` and derives intercompany from it.

**Spec:** `docs/superpowers/specs/2026-10-15-crm-module-design.md` (D27 to D29). Same Global Constraints as `2026-10-20-crm-module-extraction.md`: no `company` in the CRM schema, no ERP import in the CRM, migrations rewritten in place (no installation exists), pint on explicit files from the laraplate root, commit inside each submodule then bump pointers.

## Task 1: Contact role and primary contact (CRM, D27)

- [x] **Step 1: Failing tests** in `Modules/CRM/tests/Feature/ContactRoleTest.php`: a link stores a role taxonomy and `is_primary`; making a second contact primary demotes the first of the same counterparty and leaves other counterparties alone; the same contact may have different roles at two counterparties; deleting a used role is refused; the seeder creates the `contact_role` entity once.
- [x] **Step 2: Implement** `EntityType::ContactRoles`, `ContactRole` taxonomy model, columns `role_taxonomy_id` and `is_primary` in the `contactables` migration, `Contactable` saving hook, `withPivot` on both relations, seeder entity and preset, test helper.
- [x] **Step 3: Filament**: the contact form sets role and primary per linked counterparty; the counterparty gets a contacts relation manager showing role and primary.
- [x] **Step 4: Run** `Modules/CRM/tests`. Expected: PASS.

## Task 2: Person first and last name (CRM and ERP proxy, D28)

- [x] **Step 1: Failing tests** in `CounterpartyTest.php`: `name` is derived as "last first" for a person with parts; a person with only `name` is untouched; parts on a company are refused; a `Party` reads and writes the parts through the proxy.
- [x] **Step 2: Implement** migration columns, fillable, rules, `saving` derivation and guard, factory state, `ProxiesCounterparty::counterpartyAttributes`, Filament form (parts shown for persons).
- [x] **Step 3: Run** `Modules/CRM/tests` and the ERP party tests. Expected: PASS.

## Task 3: Intercompany (ERP, D29)

- [x] **Step 1: Failing tests** in `Modules/ERP/tests/Feature/Models/IntercompanyTest.php`: a company attaches to the counterparty with its fiscal identity or creates one; `Party::intercompany()` and `isIntercompany()` recognise a party whose counterparty belongs to a company, including a party of another company; an ordinary party is not intercompany.
- [x] **Step 2: Implement** `companies.counterparty_id` (nullable, restrict on delete, index), `Company::counterparty()`, attach-or-create on saving, the party scope and method, party list filter.
- [x] **Step 3: Run** `Modules/ERP/tests`. Expected: PASS.

## Task 4: Lead conversion (CRM, D30)

- [x] **Step 1: Failing tests**: `Modules/CRM/tests/Feature/LeadConversionTest.php` (opportunity on the same counterparty with `lead_id`, lead `converted`, caller-given counterparty, refusal of converted, disqualified and counterparty-less leads, lead untouched when the opportunity fails) and `Filament/LeadConversionActionTest.php`.
- [x] **Step 2: Implement** `LeadConversionService`, the `convert` action on the leads list, lang keys in the five locales.
- [x] **Step 3: Run** `Modules/CRM/tests`. Expected: PASS.

## Task 5: Documentation and delivery

- [x] **Step 1: Docs**: CRM README, `docs/GLOSSARY.md`, `docs/rag/MODULE.md` and `GLOSSARY.md`; ERP README and glossaries; lang keys in the five locales; section 11 of the spec and the indexes.
- [x] **Step 2: Run** CRM and ERP suites, pint on the touched files, commit per submodule, bump the pointers. Push only after the user confirms.

## Delivery status (2026-10-21): delivered

CRM and ERP suites: 814 passed, 2 skipped.

- **Contact role (D27)**: delivered as planned. `Contactable` hooks the demotion on `saving`, which fires for `attach` and `updateExistingPivot` because the relations use the custom pivot class. The counterparty edit page gets a `ContactsRelationManager` (attach and edit with role and primary flag); the contact form keeps its plain counterparty select and `sync` leaves the pivot attributes alone. `CRMDatabaseSeeder` creates the `contact_role` entity and preset; no default roles are seeded, they are configuration.
- **Person names (D28)**: delivered as planned, plus the `ProxiesCounterparty` attribute list so a `Party` reads and writes the parts. Not done, on purpose: `Contact` keeps one `name`, the ERP `PartyImporter` still takes a single name, and no ERP form shows the parts (they read and write through the proxy).
- **Lead conversion (D30)**, added after the plan was closed at the user's request: `LeadConversionService` and the list action; the opportunity starts with `EUR` currencies and rate 1 because the model validates them as required and the value is unknown at conversion. No domain action for the API yet.
- **Intercompany (D29)**: delivered with one change from the plan: the company link is explicit (`Company::ensureCounterparty()`, a select on the company form and a header action), not a side effect of saving a company, because every `Company::factory()` carries a tax id and an implicit link would create CRM rows in thousands of unrelated tests and surprise the operator. Deleting a counterparty that is a company's identity is refused (`GuardCounterpartyDeletion`).
- Version bumps stay with the user.

**Documented in:** `Modules/CRM/README.md`, `Modules/CRM/docs/GLOSSARY.md`, `Modules/CRM/docs/rag/MODULE.md`, `Modules/CRM/docs/rag/GLOSSARY.md`, `Modules/ERP/README.md`, `Modules/ERP/docs/GLOSSARY.md`, `Modules/ERP/docs/rag/GLOSSARY.md`.
