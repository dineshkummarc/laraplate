# Module scaffolding generator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `module:make` creates a module with the complete Laraplate scaffolding, as an internal (official) or a custom module, from one revisable prompt form, and one Core test keeps every official module on the same scaffolding.

**Architecture:** Core overrides nwidart's `module:make` with a subclass (same mechanism as `ModuleSeedCommand`: Core registers the commands of its `Console` folder). The command collects a `ModuleProfile`, binds it in the container and calls `parent::handle()`; stubs in `Modules/Core/stubs` read the profile through `ReplacementKeyCommand` classes. A `ModuleScaffold` class holds the file lists read by both the stub list and the conformance test.

**Tech Stack:** PHP 8.5, Laravel 12, `nwidart/laravel-modules` 12.0.5, `laravel/prompts` 0.3.26 (`form()`), Pest.

**Spec:** `docs/superpowers/specs/2026-10-20-module-scaffolding-generator-design.md` (decisions D1-D10).

## Global Constraints

- Every PHP file `declare(strict_types=1);`; braces; explicit types; `#[Override]` on overrides; `final` classes; constructor property promotion. No em-dashes in code or docs.
- The command stays `module:make` (D3). New options: `--internal`, `--requires=*`, `--description=`, `--color=`, `--license=`. The existing `--author-vendor`, `--author-name`, `--author-email`, `--force` and `--plain` keep their package meaning, except that `--plain` together with `--internal` is refused.
- **No environment variables, no identity default** (D9): `config/modules.php` stops reading `MODULE_VENDOR`, `MODULE_AUTHOR_NAME` and `MODULE_AUTHOR_EMAIL`; `composer.vendor` and `composer.author.*` become `''`.
- Internal profile: package `getlaraplate/lara{lower-name}`, `laraplate_owned: true`, licence of the application, one module name per run. Custom profile: vendor and author prompted, `--color` ignored with a notice.
- Licence default is the `license` of the application `composer.json`, read at run time (D10). `LICENSE` is copied only when the module licence equals it.
- Colours (4.2): `orange #f97316`, `emerald #10b981`, `indigo #6366f1`, `violet #8b5cf6`, `pink #ec4899`, each with the Filament palette of the same name; `black` and `white` are never offered.
- The application toolchain is never generated: `phpunit.xml`, `phpstan.neon`, `pint.json`, `peck.json`, `rector.php`, `cliff.toml`, `scripts/version.sh`, `scripts/setup-hooks.sh`, `scripts/hooks/post-commit`.
- Tests are Pest, inside `Modules/Core/tests/`; no classes declared in test files (use `Modules/Core/tests/Stubs/`); never run with a cached config (`bootstrap/cache/config.php` must not exist). Fake `Illuminate\Support\Facades\Process`: the package runs `composer dump-autoload` at the end of `handle()`, and `git config` is read through the same facade.
- `vendor/bin/pint --format agent <files>` from the laraplate root with an explicit list. **Work on branches with pull requests, never on master**: Core, SAO, CRM, MES and Shop are submodules with their own repositories, each gets its own branch and PR. The merge order is in Task 8.

## Review Focus

- **A custom module never contains the maintainer's identity** (`getlaraplate`, `swolley`, `myentity@inwind.it`) in any generated file, whatever the `git config` of the machine (Task 4 test `a custom module carries only the identity that was confirmed`).
- **`--internal` with two names, with `--plain`, or with a used or unknown `--color` is refused before anything is written** (Task 4 tests `refuses ...`).
- **No `git config` identity under `--no-interaction`**: the command stops, it does not write an empty `authors` or a blank vendor (Task 4 test `stops without an author under no-interaction`).
- **A licence that differs from the application's gets no `LICENSE` file**, and the README says where the text goes (Task 4 test `copies LICENSE only for the application licence`).
- **An existing module name is refused before the first prompt**, and `--force` still overwrites (Task 4 test `refuses an existing name before asking anything`).

---

### Task 1: Colour palette and availability

**Files:**
- Modify: `Modules/Core/app/Support/ModuleColor.php`
- Test: `Modules/Core/tests/Integration/Support/ModuleColorTest.php`

**Interfaces:**
- Produces: `ModuleColor::selectable(): list<string>` (every offered name: the mapped terminal names minus `black` and `white`, plus `orange`, `emerald`, `indigo`, `violet`, `pink`); `ModuleColor::available(): list<string>` (`selectable()` minus the colours declared by installed modules); `ModuleColor::terminal(string $module): ?string` now returns the string to put in a `<fg=...>` tag, the hexadecimal for the five new names; `ModuleColor::filament(string $module): ?array` maps the five new names to `Color::Orange`, `Emerald`, `Indigo`, `Violet`, `Pink`.

- [ ] **Step 1: Write the failing tests** in `ModuleColorTest.php`: `selectable excludes black and white and includes the five new names`; `available excludes every colour a module declares` (assert `green` and `bright-red` absent, `red` and `orange` present, against the real modules); `terminal returns the hexadecimal for a new colour` and `filament maps orange to Color::Orange` (a fake module declaring `orange`, built the way the existing tests of this file build theirs).
- [ ] **Step 2: Run** `php artisan test --compact Modules/Core/tests/Integration/Support/ModuleColorTest.php`; expected: the new tests fail.
- [ ] **Step 3: Implement** the five entries as a second constant table (`name => [terminal, filament palette]`) next to `PALETTES`, and the two new methods; `terminal()` and `filament()` look in both tables. Before changing `terminal()`, grep its callers (`rtk proxy grep -rn "ModuleColor::terminal"`) and keep the result for every existing name unchanged.
- [ ] **Step 4: Run** the same command; expected: PASS. Run pint on the two files.
- [ ] **Step 5: Commit** on the branch `feat/module-colour-palette` of `Modules/Core`: `feat(core): five more module colours and a list of the free ones`.

### Task 2: Module profile and its sources

**Files:**
- Create: `Modules/Core/app/Support/ModuleScaffold/ModuleProfile.php`, `GitIdentity.php`, `ApplicationLicense.php`
- Test: `Modules/Core/tests/Unit/Support/ModuleScaffold/ModuleProfileTest.php`, `GitIdentityTest.php`, `ApplicationLicenseTest.php`

**Interfaces:**
- Produces: `final readonly class ModuleProfile(string $name, bool $internal, string $vendor, ?string $authorName, ?string $authorEmail, ?string $license, string $description, array $requires, ?string $color)` with `packageName(): string` (`getlaraplate/lara{lower-name}` when internal, otherwise `{vendor}/{lower-name}`), `lowerName(): string` and `tablePrefix(): string` (`{lower-name}_`). `GitIdentity::name(): ?string` and `GitIdentity::email(): ?string` (trimmed `git config user.name` and `user.email` through the `Process` facade; `null` when empty or when the command fails). `ApplicationLicense::spdx(): ?string` (the `license` string of `base_path('composer.json')`, the first entry when it is an array, `null` when absent).

- [ ] **Step 1: Write the failing tests**: `packageName` for an internal `Billing` is `getlaraplate/larabilling` and for a custom one with vendor `acme` is `acme/billing`; `GitIdentity` returns `null` for empty output and for a failed process (`Process::fake`); `ApplicationLicense` reads `AGPL-3.0-or-later` from a temporary `composer.json` (point the application base path at a temp directory and restore it in `afterEach`).
- [ ] **Step 2: Run** `php artisan test --compact Modules/Core/tests/Unit/Support/ModuleScaffold`; expected: FAIL, classes missing.
- [ ] **Step 3: Implement** the three classes as specified above.
- [ ] **Step 4: Run** the same command; expected: PASS. Pint.
- [ ] **Step 5: Commit** on `feat/module-scaffold-profile`: `feat(core): module profile with git and application licence sources`.

### Task 3: Scaffold lists, stubs and replacements

**Files:**
- Create: `Modules/Core/app/Support/ModuleScaffold/ModuleScaffold.php`
- Create: `Modules/Core/app/Support/ModuleScaffold/Replacements/{PackageName,LicenseSpdx,TablePrefix,RequiresList,ReadmeLicenseSection,AuthorsBlock,Description}.php`
- Create: stubs in `Modules/Core/stubs/`: `readme.stub`, `changelog.stub`, `gitignore.stub`, `composer.stub`, `glossary.stub`, `rag-module.stub`, `rag-glossary.stub`, `tests/pest.stub`, `tests/testcase.stub`, `agent-rules.stub`, `agents.stub`, `claude.stub`
- Modify: `config/modules.php` (laraplate repository)
- Test: `Modules/Core/tests/Unit/Support/ModuleScaffold/ModuleScaffoldTest.php`, `ReplacementsTest.php`

**Interfaces:**
- Consumes: `ModuleProfile` (Task 2), bound in the container, read with `app(ModuleProfile::class)`.
- Produces: `ModuleScaffold::stubFiles(): array<string, string>` (stub key to target path: `readme` to `README.md`, `changelog` to `CHANGELOG.md`, `gitignore` to `.gitignore`, `composer` to `composer.json`, `glossary` to `docs/GLOSSARY.md`, `rag-module` to `docs/rag/MODULE.md`, `rag-glossary` to `docs/rag/GLOSSARY.md`, `tests/pest` to `tests/Pest.php`, `tests/testcase` to `tests/TestCase.php`, `agent-rules` to `.cursor/rules/module-context.mdc`, `agents` to `AGENTS.md`, `claude` to `CLAUDE.md`, plus the kept package keys `routes/web`, `routes/api`, `scaffold/config`); `ModuleScaffold::required(): list<string>` (every target above plus `LICENSE`, `module.json` and `tests/{Unit,Feature,Integration,Support}/.gitkeep`); `ModuleScaffold::forbidden(): list<string>` (the toolchain list of the Global Constraints). Each replacement class extends `Nwidart\Modules\Support\ReplacementKeyCommand` and its `handle(): string` reads the bound profile.

- [ ] **Step 1: Write the failing tests**: every key of `stubFiles()` has a file in `Modules/Core/stubs` (or is one of the three package keys); `required()` shares no path with `forbidden()`; `AuthorsBlock` returns `''` for a profile without an author and an `"authors": [...]` fragment otherwise; `LicenseSpdx` returns the profile licence; `ReadmeLicenseSection` names the CONTRIBUTING of `getlaraplate/.github` for an internal profile and, for a custom one, states the default licence and its meaning in two lines.
- [ ] **Step 2: Run** `php artisan test --compact Modules/Core/tests/Unit/Support/ModuleScaffold`; expected: FAIL.
- [ ] **Step 3: Write the stubs** by copying the matching file of `Modules/CRM` (the newest and smallest module: `composer.json`, `tests/Pest.php`, `tests/TestCase.php`, `.gitignore`; the README structure from `Modules/SAO/README.md`) and replacing names with the placeholders `$STUDLY_NAME$`, `$LOWER_NAME$`, `$PACKAGE_NAME$`, `$LICENSE_SPDX$`, `$TABLE_PREFIX$`, `$REQUIRES_LIST$`, `$README_LICENSE_SECTION$`, `$AUTHORS_BLOCK$`, `$DESCRIPTION$`. `changelog.stub` is the git-cliff header plus an `[unreleased]` section with one entry, "Initial scaffold". `agent-rules.stub` is the caveman header of the sibling `module-context.mdc` files (path, root rules are base law, dependencies, table prefix, one-way dependency rule). `agents.stub` and `claude.stub` are a few lines pointing to the application rules and to `.cursor/rules/module-context.mdc`, copying no rule.
- [ ] **Step 4: Implement** `ModuleScaffold` and the seven replacement classes.
- [ ] **Step 5: In `config/modules.php`** (branch `feat/module-scaffold-config` of `laraplate`): `stubs.files` is `ModuleScaffold::stubFiles()` and nothing else (this drops `views/*`, `vite`, `package`, `assets/*`); `stubs.replacements` maps every new key to its replacement classes and the package keys; `composer.vendor`, `composer.author.name` and `composer.author.email` are `''` with no `env()`.
- [ ] **Step 6: Run** the Core tests of Step 2; expected: PASS. Pint.
- [ ] **Step 7: Commit** Core on `feat/module-scaffold-stubs`: `feat(core): module scaffold lists, stubs and replacements`; commit `config/modules.php` on its own branch of `laraplate`: `feat(modules): scaffold stubs and no identity defaults`.

### Task 4: The `module:make` override, non-interactive path

**Files:**
- Create: `Modules/Core/app/Console/ModuleMakeCommand.php` (`final class ModuleMakeCommand extends Nwidart\Modules\Commands\Make\ModuleMakeCommand`, registered by Core's scan of its `Console` folder, like `ModuleSeedCommand`)
- Test: `Modules/Core/tests/Feature/Console/ModuleMakeCommandTest.php`

**Interfaces:**
- Consumes: `ModuleProfile`, `GitIdentity`, `ApplicationLicense` (Task 2), `ModuleScaffold` and the replacements (Task 3), `ModuleColor::available()` (Task 1).
- Produces: `ModuleMakeCommand::handle(): int`; protected `profile(): ModuleProfile` (collects the values from options, `git config` and the application licence; the form of Task 5 plugs in here); post-generation steps: copy the application `LICENSE` when `profile->license` equals `ApplicationLicense::spdx()`, create the four `tests/*/.gitkeep`, write `laraplate_owned`, `requires`, `description` and `color` into `module.json`.

- [ ] **Step 1: Write the failing tests** (temporary modules path, `Process::fake`, `--no-interaction`; assert on the generated tree): `creates an internal module with the application licence` (`composer.json` name `getlaraplate/larabilling`, `module.json` `laraplate_owned` true and the colour, `LICENSE` equal to the application's, every path of `ModuleScaffold::required()` exists, none of `forbidden()`); `a custom module carries only the identity that was confirmed` (options `--author-vendor=acme --author-name=Ada --author-email=ada@example.test`; no generated file contains `getlaraplate`, `swolley` or `myentity@inwind.it`); `stops without an author under no-interaction` (git config faked empty); `uses git config for the author under no-interaction and prints it`; `refuses --internal with two names`, `refuses --internal with --plain`, `refuses a used colour and an unknown colour`, `ignores --color for a custom module with a notice`; `copies LICENSE only for the application licence` (custom with `--license=MIT`: no `LICENSE`, the README says where the text goes); `refuses an existing name before asking anything`, and `--force` overwrites.
- [ ] **Step 2: Run** `php artisan test --compact Modules/Core/tests/Feature/Console/ModuleMakeCommandTest.php`; expected: FAIL.
- [ ] **Step 3: Implement** `getOptions()` (the parent options plus the five new ones), the validations, `profile()` and `handle()`: validate, bind `ModuleProfile`, hand `--author-*` to the parent through `$this->input->setOption(...)`, call `parent::handle()`, run the post-generation steps, print the next steps of spec D8.
- [ ] **Step 4: Run** the same command; expected: PASS. Pint.
- [ ] **Step 5: Commit** on `feat/module-make-command`: `feat(core): module:make with the internal profile and no identity defaults`.

### Task 5: The interactive form

**Files:**
- Modify: `Modules/Core/app/Console/ModuleMakeCommand.php`
- Test: `Modules/Core/tests/Feature/Console/ModuleMakeCommandPromptFlowTest.php`

**Interfaces:**
- Consumes: `profile()` of Task 4.
- Produces: `profile()` builds one `Laravel\Prompts\form()` in the order of spec 4.1 (name, internal, vendor, author name, author email, licence, description, requires, colour, confirmation summary). A step is skipped when its option is given; author name and email start from `GitIdentity` and are editable; the licence starts from `ApplicationLicense::spdx()`; `requires` is a multiselect of the installed modules with `Core` preselected; the colour select lists `ModuleColor::available()` and appears only for the internal profile; the vendor `getlaraplate` without `--internal` asks whether the module is an internal one.

- [ ] **Step 1: Write the failing tests**, driving the prompts the way `CreateUserCommandPromptFlowTest` does (`Prompt::fake([...])`): `prefills the author from git config`; `leaves the author empty without git config`; `asks the internal question for the vendor getlaraplate`; `offers only free colours and only for an internal module`; `skips every question answered by an option`; `a declined confirmation writes nothing`.
- [ ] **Step 2: Run** the file; expected: FAIL.
- [ ] **Step 3: Implement** the form in `profile()`, for interactive mode only (`$this->input->isInteractive()`); the non-interactive path of Task 4 stays unchanged.
- [ ] **Step 4: Run** this file and the one of Task 4; expected: PASS. Going back with Ctrl+U cannot be exercised by the fake: try it by hand in a terminal on a throwaway module and note the result in the PR.
- [ ] **Step 5: Commit**: `feat(core): module:make interactive form with prefilled author`.

### Task 6: Conformance test for the official modules

**Files:**
- Create: `Modules/Core/tests/Unit/Architecture/ModuleScaffoldingTest.php`
- Modify: `Modules/SAO/tests/Unit/ScaffoldingComplianceTest.php` (keep only the SAO README assertion about "Simply Another Orchestrator")

**Interfaces:**
- Consumes: `ModuleScaffold::required()` and `ModuleScaffold::forbidden()` (Task 3).

- [ ] **Step 1: Write the test**: read `base_path('.gitmodules')` and take every `Modules/*` path; for each module assert that `module.json` has `laraplate_owned: true`, every required file exists, no forbidden file exists, `LICENSE` contains `GNU AFFERO GENERAL PUBLIC LICENSE`, `composer.json` has a `version` and a `name` starting `getlaraplate/lara`, the README names the module, and `module-context.mdc` exists. Use a dataset keyed by module name so that a failure names the module and the file.
- [ ] **Step 2: Run** `php artisan test --compact Modules/Core/tests/Unit/Architecture/ModuleScaffoldingTest.php`; expected: FAIL for the modules with gaps (spec section 2 lists CRM, MES and Shop; the run is the authority). Save the failure list: it is the work of Task 7.
- [ ] **Step 3: Trim the SAO test** to its module-specific assertion; run `php artisan test --compact Modules/SAO/tests/Unit/ScaffoldingComplianceTest.php`; expected: PASS.
- [ ] **Step 4: Commit** Core on `feat/module-scaffolding-test` and SAO on its own branch.

### Task 7: Bring the existing modules into conformance

**Files:** per module, exactly the files reported by Task 6 Step 2 (expected: `Modules/CRM/.cursor/rules/module-context.mdc`, `Modules/CRM/docs/**`, `Modules/MES/.gitignore`, `Modules/Shop/docs/GLOSSARY.md`, `Modules/Shop/docs/rag/GLOSSARY.md`, `Modules/Shop/tests/Integration/.gitkeep`, `Modules/Shop/tests/Support/.gitkeep`).

- [ ] **Step 1: For each failing module**, create the missing files from the stubs of Task 3 with the module's own values, with no domain code and no change to existing files; the module-specific law of `module-context.mdc` is written from the module's README, not invented.
- [ ] **Step 2: Run** `php artisan test --compact Modules/Core/tests/Unit/Architecture/ModuleScaffoldingTest.php`; expected: PASS for every module.
- [ ] **Step 3: One branch and one PR per module repository**, commit message `chore: complete the module scaffolding`.

### Task 8: Documentation and closing

**Files:**
- Modify: `Modules/Core/README.md`, `Modules/Core/docs/rag/MODULE.md`, `docs/superpowers/specs/INDEX.md`, `docs/superpowers/plans/INDEX.md`, this plan, the spec header

- [ ] **Step 1: Document** in the Core README and in `docs/rag/MODULE.md`: the command and its options, the two profiles, the prompt form, the identity and licence defaults, the removal of `MODULE_VENDOR`, `MODULE_AUTHOR_NAME` and `MODULE_AUTHOR_EMAIL`, the conformance test, and the D8 sequence for an official module (create `getlaraplate/lara{name}`, `git submodule add` with `url` and `pushurl`, activation).
- [ ] **Step 2: Record the merge order** in the PR descriptions: the module backfill PRs (Task 7) first, then Core (Tasks 1-6), then the laraplate `config/modules.php` PR, which needs the Core classes to load.
- [ ] **Step 3: Close the plan**: tick the boxes by reading the code, set `status: completed` in the front matter, add `## Delivery status (date): ...` and `**Documented in:**` naming the Core docs, mark the spec as implemented in its own status line, add the line to `plans/INDEX.md` without a status, then run `bash plan-status` from the stack root and `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php`.
