# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package overview

Laravel Models Organizer: a development-only Artisan command, `models:organize`, that moves the relations, scopes and accessors of Eloquent models into dedicated traits (`app/Models/Concerns/{Model}/Relations.php`, `Scopes.php`, `Attributes.php`), then deletes the traits of `app/Models` left empty or unused. The command is registered only when `app()->isLocal()` and running in console.

- **Namespace:** `Axn\ModelsOrganizer`
- **Requires:** PHP 8.4+, Laravel 12+ or 13+, nikic/php-parser 5.7+
- **Auto-discovered** via `ServiceProvider` (registered in `composer.json` extra.laravel.providers)
- **Branches:** work on `develop`; `main` only moves at release time (fast-forward), tags `x.y.z` without `v`.

## Commands

```bash
composer install

composer test              # Pest suite (Testbench)

vendor/bin/pint            # fix
vendor/bin/pint --test     # check only

vendor/bin/rector process --dry-run  # preview
vendor/bin/rector process            # apply
```

## Architecture

- **`ServiceProvider`**: merges the `models-organizer` config; in local console only, registers the command and publishes the config (`models-organizer-config` tag).
- **`Console/OrganizeModelsCommand`**: the whole logic, in one class. It analyses everything before writing anything:
  1. static scan of `app/` with php-parser (names resolved, no class loaded): every class, enum and trait with the traits it uses; a file that cannot be parsed stops the command before any change;
  2. deletion of the traits of `app/Models` reached by no class or enum (nor by `ignored_traits`);
  3. protected traits, never traversed nor modified: `ignored_traits`, traits used by a class other than the organized models, traits of a model whose analysis failed, and the traits they use;
  4. one plan per model (generated trait contents, line ranges to remove, source traits to re-examine), computed on the files' original contents, cached at first read; a failing model protects its traits and restarts the analysis;
  5. line removals accumulated per file on its original content, deletable traits computed to a fixed point, `use` of deleted traits removed by resolved name (aliases included), then writes model by model.
- Method boundaries come from php-parser: the `ClassMethod` node (attributes included) plus the comments right above it, without a blank line, that start their line.
- `--dry-run` runs the same computation and writes nothing; empty directories are derived from the recorded writes and deletions, so the output matches a real run.

User documentation lives in `docs/` (Savane format: front-matter `title` and `order`, underlined H1/H2, `_index.md`). When behaviour changes, update the matching page, `README.md` and the Boost guideline.

## Tests

Pest on Testbench (`tests/`). Each test builds a throwaway application with `Tests\Support\TemporaryApplication::create([...])`: files are written to a temp directory set as `app_path()`, and `App\` classes autoload from it.

- A loaded class stays loaded for the whole process: every test uses class names no other test uses (`create()` throws if a class is already loaded).
- Results are checked in a separate process, on the files as the command left them: `lintErrors()` (`php -l`) and `methods()` (reflection, through `tests/Support/list-methods.php`).
- `TestCase` forces the `local` environment, otherwise the command is not registered.
- Shared fixtures (`emptyModel()`, `modelUsingTrait()`) live in `tests/Pest.php`.

## Changelog

`CHANGELOG.md` entries are in English, one line each, under `X.Y.Z (YYYY-MM-DD)` underlined with dashes.

## Laravel Boost Assets

The package provides Laravel Boost integration assets in `resources/boost/`:
- **Guidelines** (`guidelines/core.blade.php`): Package overview for AI assistants

**Important:** These files must be kept up to date when components, configuration keys, or usage patterns change. When adding, renaming, or removing components or config options, update the corresponding Boost assets accordingly.
