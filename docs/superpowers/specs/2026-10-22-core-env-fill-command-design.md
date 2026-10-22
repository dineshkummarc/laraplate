# Core command to complete the production `.env`

**Status:** Draft, 2026-10-22. Requirements given by the owner in chat the same day; the design below fills in what they left open. The plan follows after this spec is reviewed.

**Date:** 2026-10-22

**Modules:** `Modules/Core` (the command) and the `laraplate` root (`.env.example`, one guard test).

**Related:** `2026-10-22-ai-standard-servers-design.md` (adds about a dozen new variables, which is what made the gap visible).

## 1. Problem

A `.env` always starts from `.env.example`. When a release adds entries to `.env.example`, the `.env` files already deployed (production above all) do not get them: there is no way to align them after an update except reading the diff of the example by hand.

The example is also incomplete for what is specific to Laraplate. The configuration files of the six modules read **161** distinct names through `env()` that belong to Laraplate's own configuration (packages' published configs excluded, see D8), and `.env.example` mentions only 11 of them (active or commented): **150 are missing**. By file: AI 30, CMS 18, Core 49, ERP 19, MES 21, SAO 19 (names shared between files counted once overall). Nobody can find out which Laraplate variables exist without reading every module config, and an installer who upgrades has no way to learn which variables a release added.

Nothing in the repository edits a `.env`: there is no command for it in `Modules/Core/app/Console`.

## 2. Goal

The motivation, in the owner's words: an env is always born from `.env.example`, so when the example gains entries over time the production envs must be alignable after an update. Three requirements follow.

1. `.env.example` always lists every variable the configuration can read, and a test fails when it does not.
2. A Core command compares an existing `.env` (typically the production one) with `.env.example`, proposes the variables the `.env` lacks, asks the value of each one the user picks, and writes it into the file straight away.
3. When it ends with at least one change, it offers to refresh the configuration cache.

## 3. Decisions

| # | Decision |
|---|---|
| D1 | The source of truth for "every possible variable" is `.env.example`. A name counts as listed when `.env.example` has a line `NAME=` or `# NAME=`. |
| D2 | A name is **missing** from a `.env` when the file has no active line `NAME=` for it. A commented line `# NAME=` does not define it. A line `NAME=` with an empty value does: empty is a value. |
| D3 | The command is `env:fill`, in `Modules/Core/app/Console/EnvFillCommand.php` (commands of the module are discovered from its `Console` folder). |
| D4 | The loop uses Laravel Prompts `suggest` (v0.3.26 is installed): a text field that autocompletes the missing names. An empty answer ends the loop. A name that is not missing is refused with a message and the prompt is asked again. This is the owner's wording: only the suggestions from `.env.example` that are not in the `.env` matter. |
| D5 | Each choice is written to the file before the next prompt, so interrupting the command loses nothing already answered. |
| D6 | On exit, if any variable was written, a `confirm` asks whether to run `config:clear` and `config:cache`. |
| D7 | The command lives in Core and works on the application root's `.env` and `.env.example`. Modules do not get their own example files. |
| D8 | **Scope of "every possible variable": the Laraplate-specific ones.** That is every `Modules/*/config/*.php` except the configs published from third-party packages (`scout`, `elastic*`, `media-library`, `permission`, `swagger-ui`, `laravel-swagger`). Laravel's own `config/*.php` of the root (database, cache, queue, mail, session, filesystems, logging, auth, services and the rest) is out: it is the framework's, and the example covers what the project needs of it. The owner's wording, 2026-10-22: only Laraplate's own entries. |

## 4. The command

```
php artisan env:fill [--file=] [--example=]
```

`--file` is the `.env` to complete (default `base_path('.env')`) and `--example` the reference (default `base_path('.env.example')`). Both exist so that the command can be run against a copy of the production file and so that tests need no real `.env`.

### 4.1 Flow

1. Read both files. If `--file` does not exist or is not writable, or the example is unreadable, print the reason and exit `1`; the command never creates a `.env`.
2. Compute the missing names (D1, D2) in the order they appear in `.env.example`. If none: print "Nothing missing: the `.env` defines every variable of `.env.example`." and exit `0` without any prompt.
3. Loop:
   - `suggest('Variable to set', options: <missing names still not written>, placeholder: 'Press Enter with no name to finish', hint: "<n> missing")`.
   - Empty answer: leave the loop.
   - A name that is not in the missing list (typo, already written): `error` message, ask again.
   - Otherwise ask its value (4.2) and write it (4.3); the name leaves the suggestion list; the count in the hint drops.
4. On leaving the loop: if at least one variable was written and the target is the application's own `.env` (the default path), ask `confirm('Run config:clear and config:cache now?')`; yes runs `config:clear` then `config:cache` through `$this->call()`. With a different `--file` there is nothing to refresh and no question is asked.
5. Print a summary: variables written, whether the cache was refreshed, and exit `0`.

`--no-interaction`: the command prints how many variables are missing and exits `1` without writing, because every write needs an answer.

### 4.2 The value prompt

- Label: the variable name. Hint: the comment lines directly above the variable in `.env.example` (when it has any), which is where the example already says what the variable does.
- Default offered: the value of the example line. Enter accepts it; an empty answer writes `NAME=` (the variable then exists and is empty).
- A variable whose name contains `KEY`, `SECRET`, `PASSWORD`, `PASS`, `TOKEN` or `CREDENTIAL` is asked with the masked `password` prompt and its default is not shown: a secret is never echoed on screen.

### 4.3 Writing the file

- The new line is `NAME=value`, appended at the end; the file's own content stays byte for byte as it was, a missing final newline is added first and no line is reordered or removed.
- A value is written bare when it contains only characters Dotenv reads literally. Otherwise (whitespace, `#`, quotes, `$`, backtick, backslash, or an empty value that must stay distinguishable) it is wrapped in double quotes with `\`, `"` and `$` escaped. The test round-trips every written value through `Dotenv\Parser\Parser` (installed under `vendor/vlucas/phpdotenv`) and requires the parsed value to equal the typed one.
- The write is atomic: the new content goes to a temporary file in the same directory, takes the permissions of the original and replaces it with `rename`. A `.env` usually has mode 0600 and holds secrets; it must not become world-readable, and a crash must not leave a truncated file.
- A value is never printed back or logged.

### 4.4 Interaction with the configuration cache

The command reads the two files directly, never through `env()`, so a cached configuration does not affect what it sees. Offering `config:cache` is the owner's request. The prompt text adds one line: do not leave a cached configuration on a machine that runs the test suite (the test bootstrap aborts when `bootstrap/cache/config.php` exists). The default answer is yes in the `production` environment and no elsewhere.

## 5. Keeping `.env.example` complete

- **Format.** Every name in the scope of D8 appears once as `NAME=value` or `# NAME=value`. The 150 missing names are added as **commented** lines, so that copying `.env.example` to `.env` still activates only what it activates today. The commented value is the literal default of the `env('NAME', default)` call when it is a scalar, empty otherwise. Lines are grouped under a header per owner (`# --- Core ---`, `# --- AI ---`, and so on, root first) and keep the comment the config file has above the `env()` call when there is one; a name with no comment in the config gets none.
- **Guard test.** `tests/Unit/EnvExampleCoversConfigTest.php` in the `laraplate` root reads the config files in the scope of D8, extracts every name passed to `env('NAME'` and fails with the list of names that `.env.example` does not mention (D1). The failure message says to add each as `# NAME=default`. There is no allow-list of names: a Laraplate variable the configuration reads is documented. The excluded files are the explicit list of D8.
- The narrow check `EnvExampleListsAiServersTest` of the AI plan is then redundant and is removed when this lands.
- Reading only `config/` is sound: `grep` finds no `env()` call outside configuration files in `app/` and `Modules/*/app` (checked 2026-10-22; the remaining hits are `getenv` of runtime flags and a docblock).

## 6. Components

| File | Role |
|---|---|
| `Modules/Core/app/Console/EnvFillCommand.php` | The command: flow of 4.1, prompts, cache question |
| `Modules/Core/app/Support/Env/EnvFile.php` | Reads a `.env` or example into entries (name, value, preceding comment, commented or not) and appends one variable atomically (4.3) |
| `Modules/Core/app/Support/Env/EnvEntry.php` | Readonly value object of one line |
| `Modules/Core/app/Support/Env/EnvValue.php` | Quotes and escapes a value; detects secret names (4.2) |
| `tests/Unit/EnvExampleCoversConfigTest.php` | Guard test (section 5) |

`EnvFile` is plain PHP with no Laravel dependency, so the loop of the command is the only code that needs prompts.

## 7. Tests

- `EnvFileTest`: reads active, commented, quoted and empty lines; keeps the comment block above a variable; appends without touching the rest, adds a missing final newline, keeps the file mode; a failed write leaves the original intact.
- `EnvValueTest`: round trip through Dotenv's parser for spaces, `#`, quotes, `$`, backslash, `=`, unicode and the empty value; secret detection by name.
- `EnvFillCommandTest`: with a temporary `.env` and example: proposes only the missing names in example order; a commented line in the `.env` counts as missing and an empty one does not; two answers write two lines and the second suggestion list no longer has the first; a wrong name is refused and asked again; an empty answer ends the loop; nothing missing exits `0` without prompts; no write and no cache question when the loop ends at once; the cache question appears after a write and runs `config:clear` and `config:cache` on yes, not on no; a different `--file` never asks it; `--no-interaction` exits `1` and writes nothing; a secret name uses the masked prompt.
- `EnvExampleCoversConfigTest`: passes on the repository after section 5, and a fixture directory with one uncovered name makes the same extractor report it.

## 8. Out of scope

- Validating values, generating secrets (`key:generate` stays) or deleting variables the example no longer lists.
- Editing a remote `.env` over SSH, `--list` or machine-readable output, a Filament screen.
- Variables read by the framework itself that no config file passes to `env()`.
- Encrypting the file (`env:encrypt` is Laravel's).

## 9. Facts read for this spec (2026-10-22)

- Counts and the per-owner split (2026-10-22): a script over `Modules/*/config/*.php` extracting `env('NAME'`, compared with `.env.example` lines `NAME=` and `# NAME=`: 161 names in scope, 150 missing; the excluded package configs add 65 more missing and the root `config/` of Laravel 99 (not covered).
- `Modules/Core/app/Console` has no `.env` command; commands are discovered through the provider's `Console` subpaths.
- `laravel/prompts` v0.3.26: `SearchPrompt` refuses `required: false` and needs a highlighted choice, and `select` requires a choice, so an empty answer to end the loop needs `suggest`.
- `vlucas/phpdotenv` is installed (`src/Parser`), used for the round-trip test.
- `tests/bootstrap.php` runs `TestEnvironmentGuard`, which aborts when a cached configuration exists.
