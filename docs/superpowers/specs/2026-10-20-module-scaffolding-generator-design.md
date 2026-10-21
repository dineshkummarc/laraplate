# Module Scaffolding Generator: One Source for Every New Module

**Status:** Draft, direction agreed with the user on 2026-10-20 (option B of the conversation: extend
the existing generator, no template repository for now). Nothing is implemented.

**Scope:** backend only (`laraplate`). Touches `Modules/Core` (stubs, command, conformance test),
`config/modules.php`, and the scaffolding of the existing official modules that fail the new
conformance test.

## 1. Goal

A new module, official or custom, starts with the complete Laraplate scaffolding: metadata, licence,
README, changelog, documentation skeleton, test harness and agent directives. The scaffolding has one
source, the stubs consumed by the generator, and one executable definition, a conformance test that
reads the same file list. A module that drifts from it fails a test instead of being found by hand.

## 2. Why now

- **The generator covers only code.** `config/modules.php` points `nwidart/laravel-modules` (v12.0.5)
  at `Modules/Core/stubs`, which holds six stubs (providers, translations). Every other stub falls back
  to the package defaults: Laravel boilerplate (`views/master`, `vite`, `package`, `assets/*`) that no
  official module keeps, and no README, changelog, licence, documentation or agent directives.
- **Bringing a module to compliance is manual and expensive.** SAO needed a dedicated plan
  (`plans/2026-07-31-sao-phase-0-scaffolding.md`, 60 KB) to go from the bare `nwidart` stub to the
  sibling modules' scaffolding.
- **The modules already drift.** Observed on 2026-10-20:
  - `CRM` has no `.cursor/rules/module-context.mdc` and an empty `docs/`;
  - `MES` has no `.gitignore`;
  - `Shop` has neither `docs/GLOSSARY.md` nor `docs/rag/GLOSSARY.md`, and no `tests/Integration` or
    `tests/Support` although its `composer.json` autoloads them;
  - `.superpowers/` exists in some modules only.
- **The conformance check covers one module.** `Modules/SAO/tests/Unit/ScaffoldingComplianceTest.php`
  asserts the right file list, the absence of the application toolchain and the agent rules, but only
  for SAO.
- **The generator stamps the maintainer's identity on every module.** Verified on 2026-10-21:
  `config/modules.php` defaults `composer.vendor` to `getlaraplate` and `composer.author` to
  `Swolley <myentity@inwind.it>` through three environment variables (`MODULE_VENDOR`,
  `MODULE_AUTHOR_NAME`, `MODULE_AUTHOR_EMAIL`, none documented), and the package `composer.stub` writes
  them into `composer.json`. Anyone who runs `module:make` for their own module gets the maintainer's
  vendor, name and email, unless they know to override those variables. Defect to fix first (D9).
- **The vendor moved.** Composer packages became `getlaraplate/lara*` on 2026-10-21 (first
  `getlaraplate/laraplate-*` on 2026-10-20), and
  `is_laraplate_owned_module()` recognises official modules by that prefix. A generator that writes the
  vendor and `laraplate_owned` keeps new modules from getting this wrong.

## 3. Decisions

### D1. Extend the generator, no template repository

The stubs in `Modules/Core/stubs` are the single source. A GitHub template repository would be a
static copy: it cannot substitute the module name, namespace or table prefix, and it would drift from
the generator. It is deferred (section 8) and, if built, is produced from the same stubs.

### D2. Two profiles: custom (default) and internal

The generator serves two audiences, and some content must not reach the wrong one. The flag is
`--internal`: "internal" is the word Core already uses for what Laraplate itself ships (the settings'
`is_internal` flag, stamped from `is_laraplate_owned_module()`), so an internal module is an official
one.

| | Custom (default) | Internal (`--internal`) |
|---|---|---|
| Who | anyone building a module on Laraplate | the maintainer, for `getlaraplate` modules |
| Composer name | `{vendor}/{lower-name}`, vendor from `--author-vendor` or its prompt | `getlaraplate/lara{lower-name}` |
| Licence | none written; `composer.json` `license` from an option, default `proprietary` | `AGPL-3.0-or-later`, `LICENSE` copied from the application's AGPL text |
| `module.json` | `laraplate_owned` absent | `laraplate_owned: true` |
| README | name, description, install, requirements | the same, plus Contributing (CONTRIBUTING and CLA of the organization) and Licence sections |
| Agent directives | yes (D5) | yes (D5) |

The custom profile is the default because `module:make` is also what users of Laraplate run, and the
official content (AGPL, CLA, `getlaraplate` vendor) must never be stamped on their code by accident.

### D3. Entry point: `module:make` itself, overridden by Core

The command stays `module:make`, the one every Laraplate and `nwidart` user already knows. Core
replaces it with a subclass of `Nwidart\Modules\Commands\Make\ModuleMakeCommand` (the class is not
final) that adds `--internal`, `--requires=*`, `--description=` and `--color=`. Its `handle()`:

1. sets the profile for the run;
2. calls `parent::handle()`, so folders, `module.json`, stub files, activation and the final
   `composer dump-autoload` stay the package's behaviour;
3. applies what the stubs cannot express: copying `LICENSE` for the internal profile, writing
   `requires`, `description` and `color` into `module.json`.

The swap uses the package's own registration point. `config/modules.php` builds `commands` from
`ConsoleServiceProvider::defaultCommands()`, and `ConsoleServiceProvider::register()` registers that
list: replacing `ModuleMakeCommand::class` with the Core class in the collection is the whole
override, with no provider ordering involved.

Considered and rejected: a separate `module:make-laraplate` (or `make:laraplate-module`). It is
decoupled from the package internals, but it leaves two commands that differ by a flag, and the plain
`module:make` would still produce the Laraplate stubs without the profile handling.

The override depends on `ModuleMakeCommand::handle()` and `getOptions()` staying compatible across
`nwidart/laravel-modules` upgrades. The feature tests of section 6 run the real command, so an
incompatible upgrade fails them.

Custom placeholders use the package's own extension point: `stubs.replacements` accepts a
`Nwidart\Modules\Support\ReplacementKeyCommand` subclass as a value, resolved with the running
generator. New keys (for example `PACKAGE_NAME`, `LICENSE_SPDX`, `OWNED_FLAG`, `README_LICENSE_SECTION`)
are such classes in Core reading the profile, so no generator class is overridden.

### D4. The file list: what every module carries, and what it never carries

**Generated for every profile** (added to `stubs.files`, stubs in `Modules/Core/stubs`):

- `README.md`, `CHANGELOG.md` (git-cliff header and an `[unreleased]` section holding one "Initial scaffold" entry), `.gitignore`;
- `composer.json` (Laraplate stub: `version`, `type: laravel-module`, PSR-4 for `app/`, factories,
  seeders, and `autoload-dev` for `tests/Unit`, `tests/Feature`, `tests/Integration`, `tests/Support`);
- `docs/GLOSSARY.md`, `docs/rag/MODULE.md`, `docs/rag/GLOSSARY.md`;
- `tests/Pest.php`, `tests/TestCase.php`, and `.gitkeep` in `tests/Unit`, `tests/Feature`,
  `tests/Integration`, `tests/Support`;
- the agent directives of D5.

**Removed from the default list:** `views/index`, `views/master`, `vite`, `package`, `assets/js/app`,
`assets/sass/app`. UI lives in `laraplate-ui`, and no official module but `Core` and `ERP` carries a
`package.json`. A module that needs Filament assets adds them when it needs them.

**Never generated**, and asserted absent by the conformance test (the list SAO already enforces): the
application toolchain, `phpunit.xml`, `phpstan.neon`, `pint.json`, `peck.json`, `rector.php`,
`cliff.toml`, `scripts/version.sh`, `scripts/setup-hooks.sh`, `scripts/hooks/*`. The module carries
functionality; the application carries the toolchain (module testing strategy, revised 2026-09-15).

`.superpowers/` is not scaffolding and is neither generated nor required.

### D5. Agent directives: one canonical file per module, thin pointers for each tool

- `.cursor/rules/module-context.mdc` is the canonical module context, as today. The stub writes the
  caveman header used by the siblings (path, "root rules = base law", dependencies from `--requires`,
  table prefix `{lower-name}_`, the one-way dependency rule) and leaves the module-specific law to the
  author.
- `AGENTS.md` (Codex) and `CLAUDE.md` (Claude Code) at the module root are pointers of a few lines:
  read the application rules, then `.cursor/rules/module-context.mdc`. They copy no rule. Claude Code
  loads a nested `CLAUDE.md` when it works in that folder, which today it does not get for modules.
- Common rules stay in `laraplate/AGENTS.md` and `.cursor/rules/`. Copying them into each module would
  create nine copies that drift, the defect this spec removes.
- `.cursor/mcp.json` is not generated: only `AI` has one, and module MCP servers are specific.

### D6. One conformance test for every official module

- The required and forbidden file lists move into one Core class (for example
  `Modules\Core\Support\ModuleScaffold`), read by the generator's stub list and by the test, so the
  two cannot diverge.
- A Core architecture test (`Modules/Core/tests/Unit/Architecture/ModuleScaffoldingTest.php`) runs the
  checks for every module whose `module.json` declares `laraplate_owned: true`: required files present,
  toolchain absent, AGPL `LICENSE`, `composer.json` named `getlaraplate/lara*` with a `version`,
  README naming the module, `module-context.mdc` present.
- `Modules/SAO/tests/Unit/ScaffoldingComplianceTest.php` keeps only its SAO-specific assertions (the
  README mentions "Simply Another Orchestrator"); the generic ones move to Core.
- Custom modules are not checked: their scaffolding is their authors' business.
- **Forgetting `--internal` is caught.** Every `Modules/*` path registered in the application's
  `.gitmodules` is an official module by construction: the AGPL repository's only submodules are its
  own modules. The test reads `.gitmodules` and fails when such a module lacks
  `laraplate_owned: true` or its scaffolding, so a module created without the flag is caught as soon
  as it becomes a submodule, before it is released.
- **The command asks first.** Run without `--internal` while the vendor given or typed is
  `getlaraplate`, the command asks whether the module is an internal one and, under
  `--no-interaction`, stops with that message instead of guessing.

### D7. Existing modules are brought into conformance in the same work

The first run of D6 fails on the drift listed in section 2. The plan backfills each module (missing
`.gitignore`, glossaries, `module-context.mdc`, test folders, `AGENTS.md` and `CLAUDE.md` pointers) in
the module's own repository, one commit per module, without touching domain code.

### D9. Identity is never defaulted to the maintainer's

- **No environment variables.** `config/modules.php` no longer reads `MODULE_VENDOR`,
  `MODULE_AUTHOR_NAME` or `MODULE_AUTHOR_EMAIL` and carries no identity at all: `composer.vendor` and
  `composer.author` are left empty. Every value comes from an option or from the prompt, which the
  override command hands to `parent::handle()` through the same options (`--author-vendor`,
  `--author-name`, `--author-email`). Nothing to document, nothing to set up on a new machine.
- **Custom profile:** vendor, author name and email are prompted when not passed (`--author-vendor`,
  `--author-name`, `--author-email`, already options of `module:make`). Name and email are prefilled
  from `git config user.name` and `git config user.email` and editable (section 4.1); the vendor has
  no default. Under `--no-interaction` the command stops if the vendor is missing or if no author
  can be resolved from the options or from `git config`. The composer stub omits `authors` when no author is given,
  instead of writing empty values.
- **Internal profile:** vendor `getlaraplate`; the author is prefilled from `git config` like any
  other profile, so the maintainer's identity comes from the maintainer's machine, not from a shipped
  default.
- A conformance check for the generator output: a module generated for the custom profile contains
  neither `getlaraplate` nor the maintainer's name or email.

### D8. Repository creation stays outside the generator

The command does not create the GitHub repository, the submodule or the `modules_statuses.json`
entry: those are git and GitHub operations with their own permissions. The Core documentation gives
the sequence for an official module (create `getlaraplate/lara{name}`, `git submodule add` with
`url` and `pushurl` in `.gitmodules`, activation). A script can come later (section 8).

## 4. Command behaviour

```
php artisan module:make Billing --internal --requires=Core --requires=ERP \
    --description="Billing module" --color=bright-blue
```

### 4.1 Missing input is prompted, prefilled and revisable

Every value not passed on the command line is asked with Laravel Prompts, in this order, as one
**form** (`Laravel\Prompts\form()`, available in the installed `laravel/prompts` v0.3.26): the user
can press Ctrl+U to go back to the previous question and change it, and a **summary with a
confirmation** closes the sequence before anything is written. Under `--no-interaction` nothing is
asked and the fallback column applies.

| # | Value | Prompt, with its prefilled default | Asked when | Fallback under `--no-interaction` |
|---|---|---|---|---|
| 1 | name | text, validated StudlyCase and not an existing module | no name argument | error |
| 2 | internal | confirm, default no | `--internal` not passed and the vendor is `getlaraplate` (D6) | error: pass `--internal`, or `--author-vendor` for a custom module |
| 3 | vendor | text, validated as a composer vendor | custom profile, `--author-vendor` not passed | error |
| 4 | author name | text, default `git config user.name` | `--author-name` not passed | `git config user.name` when set, otherwise error |
| 5 | author email | text, default `git config user.email`, validated as an email | `--author-email` not passed | `git config user.email` when set, otherwise error |
| 6 | licence | select (Q1 of section 9), `AGPL-3.0-or-later` preselected | custom profile, `--license` not passed | no `license` key, with a notice |
| 7 | description | text | `--description` not passed | empty |
| 8 | requires | multiselect of the existing modules, `Core` preselected | `--requires` not passed | `[]` |
| 9 | color | select among the unused colours (4.2) | internal profile and `--color` not passed | error listing the unused colours |
| 10 | confirmation | summary of every value, confirm | always in interactive mode | none |

**Defaults, never silent.** The author defaults come from the `git config` of the person running the
command: their own identity, not the maintainer's (D9). In interactive mode they are only the
prefilled text of the prompt, editable before confirming. Under `--no-interaction` they are used
only when set, and the command prints the identity it used. When `git config` has no value, the
prompt starts empty. The vendor has no default: the application's own `composer.json` vendor is not
a safe guess (a copy of Laraplate still says `getlaraplate`).

With `--internal` the command takes one module name, so that prompts and colour uniqueness apply to
one module at a time; nwidart's multi-name form stays available for the custom profile.

### 4.2 Colour: internal modules only, never one already taken

- The colour is the module's identity in the CLI (`<fg=...>`) and in the backoffice, through
  `Modules\Core\Support\ModuleColor`. Distinct colours are what makes it an identity, so the choice
  is an internal-module concern: for the custom profile `--color` is ignored with a notice and never
  prompted. An author can still declare a colour in `module.json` by hand.
- The options are the terminal names that `ModuleColor` maps to a Filament palette, minus `black` and
  `white` (both map to `Gray`, no identity), minus the colours already declared by an existing module.
  The list comes from `ModuleColor`, which gains a public accessor for it, so the generator cannot
  offer a colour the backoffice cannot render.
- A `--color` that is unknown or already used is refused with the list of the free ones.
- Colours in use on 2026-10-20: `green` (Core), `cyan` (CMS), `magenta` (AI), `yellow` (ERP), `blue`
  (MES), `bright-red` (SAO), `bright-magenta` (Shop), `bright-green` (CRM). Free: `red`,
  `bright-blue`, `bright-cyan`, `bright-yellow`. Four modules from now the palette is exhausted
  (question 2 of section 9).

### 4.3 Steps

1. Collects and validates the input (4.1): each `--requires` is an existing module.
2. Runs the nwidart generator with the profile set. `--plain` keeps its package meaning (no stub
   files) for the custom profile and is refused together with `--internal`, which needs the stubs.
3. Internal profile: copies the application `LICENSE`, sets `laraplate_owned: true` and `color`.
4. Writes `requires` and `description` into `module.json`.
5. Prints the next steps of D8.

`--force` keeps nwidart's meaning (overwrite an existing module) and is passed through.

## 5. Placeholders

| Key | Value | Source |
|---|---|---|
| `STUDLY_NAME`, `LOWER_NAME`, `KEBAB_NAME`, `MODULE_NAMESPACE`, `PROVIDER_NAMESPACE`, `APP_FOLDER_NAME`, `VENDOR`, `AUTHOR_NAME`, `AUTHOR_EMAIL` | as today | nwidart generator |
| `PACKAGE_NAME` | `getlaraplate/lara{lower}` or `{vendor}/{lower}` | Core `ReplacementKeyCommand` |
| `LICENSE_SPDX` | `AGPL-3.0-or-later` or the custom option | Core `ReplacementKeyCommand` |
| `TABLE_PREFIX` | `{lower}_` | Core `ReplacementKeyCommand` |
| `REQUIRES_LIST` | bullet list for `module-context.mdc` and README | Core `ReplacementKeyCommand` |
| `README_LICENSE_SECTION` | internal licence and contributing text, empty for custom | Core `ReplacementKeyCommand` |

Vendor and author never default to the maintainer's identity (D9). For the custom profile the command
uses `--author-vendor`, `--author-name` and `--author-email` when given and prompts for them otherwise;
it also asks before going on when the vendor is `getlaraplate` (D6).

## 6. Testing

- Feature test of the command for each profile in a temporary modules path: the generated tree
  matches `ModuleScaffold`, placeholders are all substituted (no `$...$` left), `module.json` and
  `composer.json` carry the expected values, the custom profile has no `LICENSE`, no
  `laraplate_owned` and no `getlaraplate` vendor.
- The architecture test of D6 over the real modules.
- `--plain` with `--internal` is refused; an unknown `--requires` is refused.
- Prompts: each missing value is asked in interactive mode and falls back as in 4.1 under
  `--no-interaction`; the colour prompt offers only unused colours and is never shown for the custom
  profile; a used or unknown `--color` is refused; `--color` without `--internal` is ignored.
- Author prefill: with `git config user.name` and `user.email` set, the author prompts start with them
  and accept an edit; with neither set they start empty; under `--no-interaction` the configured values
  are used and printed, and missing ones stop the command. The generated custom module carries the
  identity that was confirmed, never the maintainer's.

## 7. Documentation

- `Modules/Core/docs/rag/MODULE.md` and `Modules/Core/README.md`: the command, the two profiles, the
  conformance test, the D8 sequence for an official module.
- The removal of `MODULE_VENDOR`, `MODULE_AUTHOR_NAME` and `MODULE_AUTHOR_EMAIL` is noted in the Core
  README, for anyone who had set them.

## 8. Out of scope, possible later

- **Template repository** `getlaraplate/laraplate-module-template`, generated from the same stubs, as
  an entry point for third-party authors who do not clone Laraplate.
- **Repository bootstrap script**: create the GitHub repository, add the submodule, push the first
  commit.
- **Community files per module** (CONTRIBUTING, CLA, security policy): they come from the
  organization's `.github` repository, not from the module.

## 9. Open questions

1. Custom profile licence default: `proprietary`, or no `license` key at all.
2. What happens when no colour is left: extend the palette (`ModuleColor` would need terminal
   styles beyond the sixteen ANSI names, for example `#rrggbb` foregrounds), or allow reuse with an
   explicit confirmation.

Resolved: `CHANGELOG.md` starts with an "Initial scaffold" entry under `[unreleased]` (2026-10-21).

Resolved on 2026-10-20: the entry point is `module:make` overridden with `--internal` (D3), not a
separate command; missing input is prompted and the colour is internal-only and unique (section 4).
