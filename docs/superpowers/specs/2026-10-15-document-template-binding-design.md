# Document Template Binding: Printing Any Document Through Core Templates

**Status:** Draft idea, discussed with the user on 2026-10-15. Not scheduled. To be analysed after the CRM parity move (`2026-10-15-crm-module-design.md`).

**Scope:** Core (`Template`, a binding table, a rendering service), then each module that owns a printable document (ERP invoices and delivery notes, CRM quotes, and others).

## 1. Starting point

`Modules\Core\Models\Template` (`core_templates`: `name`, `content`) is a record whose `content` is a Blade template used to give a representation of something. Today the only consumer is `Preset.template_id`. The Filament `TemplateResource` living in the CMS module is a historical leftover: templates used to belong to the CMS.

There is **no rendering code yet**: nothing takes a `Template` and applies it to a model. That has to be developed.

## 2. Goal

Let any document entity, in any module, be rendered (printed, emailed, previewed) through a `Template`, without Core knowing the documents of other modules.

## 3. Proposed binding table (Core)

One table linking a template to a model type, optionally to one specific record.

| Column | Meaning |
|---|---|
| `id` | primary key |
| `template_id` | FK to `core_templates` |
| `model_type` | **morph alias**, not a class name |
| `model_id` | nullable: null is the default for the type, set is an override for one record |
| `purpose` | default `print`; allows several representations of one document (print, email, preview) |
| `locale` | nullable; only if documents are needed in several languages |

Decisions:

- `model_type` stores the morph alias, so moving a model between modules (for example `Lead` from `Modules\ERP\Models` to `Modules\CRM\Models`) does not break stored bindings. The project already uses aliases (`Product::CONTENT_ALIAS` in Shop).
- No per-company scope. A document already carries its `company_id`; the Blade receives the company data (letterhead, logo) as variables, so one template serves every company.
- Uniqueness of (`model_type`, `model_id`, `purpose`) is enforced by the resolving service, not by a database index, because a unique index over a nullable `model_id` behaves differently across MySQL, PostgreSQL, Oracle and SQLite (the project requires portable migrations).
- `Preset.template_id` can coexist with the new table. Migrating `Preset` onto the binding is optional and not decided.

## 4. Resolution order

Most specific wins: record override, then type default for the `purpose` and `locale`, then the type default for the `purpose`, then a Blade file shipped by the module in its `resources`. A fresh installation prints without configuration.

## 5. Contract on the document

An interface in Core (provisional name `IRenderableDocument`) in which the model declares its morph alias, the purposes it supports and the data it exposes to the Blade, in the same spirit as `ExtendsContent`.

## 6. Open problems

- **Security.** Blade stored in the database and editable by users can execute PHP (`@php`, expressions). The rendering engine needs a sandbox (restricted directives, an allowlist of variables) or the right to edit templates must be restricted. This is the first decision to take, before writing the engine.
- **Reproducibility of fiscal documents.** A posted invoice must be reprintable identically after the template changes. Options: version the template (as presets are frozen with `Presettable`), or store the generated PDF at posting time. The second is the safer one; it has not been checked against regulatory requirements.
- **Not every document is a print.** Electronic invoices are XML; only the human-readable copy goes through a template.
- **Several representations of one document** (short, full, with or without prices): whether `purpose` is enough or a finer key is needed.
- **Who may edit templates**, and the misplaced `TemplateResource` in the CMS module.
