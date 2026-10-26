---
status: completed
---
# Filament Environment Indicator Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace `pxlrbt/filament-environment-indicator` with Core plugin + module version dropdown.

**Architecture:** `ModuleVersionCatalog` + Filament plugin + Blade/Alpine; wire in `AdminPanelProvider`.

**Tech stack:** Laravel 12, Filament 5, Pest, Core module views (`core::`).

**Spec:** `docs/superpowers/specs/2026-07-29-filament-environment-indicator-design.md`

---

### Task 1: Catalog DTO + service (TDD)

**Files:**
- Create: `Modules/Core/app/Filament/Data/ModuleVersionEntry.php`
- Create: `Modules/Core/app/Filament/Services/ModuleVersionCatalog.php`
- Create: `Modules/Core/tests/Unit/Filament/ModuleVersionCatalogTest.php`

### Task 2: Plugin + Blade views

**Files:**
- Create: `Modules/Core/app/Filament/Plugins/EnvironmentIndicatorPlugin.php`
- Create: `Modules/Core/resources/views/filament/environment-indicator/badge.blade.php`
- Create: `Modules/Core/resources/views/filament/environment-indicator/debug-mode-warning.blade.php`
- Create: `Modules/Core/tests/Unit/Filament/EnvironmentIndicatorPluginTest.php`

### Task 3: Wire + remove package

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Modify: `composer.json`, `Modules/Core/composer.json`
- Delete: `public/css/filament-environment-indicator/styles.css` (and dir if empty)
- Modify: `Modules/Core/README.md`, RAG MODULE note, specs INDEX

### Task 4: Verify

- Run catalog + plugin Pest tests
- `vendor/bin/pint --dirty`

## Delivery status (2026-07-29): delivered, reconciled against the code on 2026-10-23

Shipped in Core 1.65.0: `ModuleVersionCatalog` with `ModuleVersionEntry`, `EnvironmentIndicatorPlugin` with its badge and debug warning views, registered in `app/Providers/Filament/AdminPanelProvider.php`, and `pxlrbt/filament-environment-indicator` removed. The tasks are written as headings, so the plan has no checkboxes. Divergences:

- The badge renders at `PanelsRenderHook::GLOBAL_SEARCH_BEFORE`, for super-admins only.
- Task 4: the four Pest tests exist; nothing records their run.

**Documented in:** `Modules/Core/docs/rag/MODULE.md` (*How to use Core capabilities correctly*, for product/admin teams), `Modules/Core/README.md` (*Installed Packages*, UI / Admin).
