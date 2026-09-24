# pxlrbt Laravel Standards

Shared tooling, AI guidelines and CI workflows for pxlrbt Laravel projects, plus an installer that sets up new projects the same way every time.

It is a **dev dependency** that provides:

- **Code style & static analysis:** shared Pint, PHPStan (Larastan) and Rector configs that projects extend instead of copying.
- **AI guidelines:** a [Laravel Boost](https://github.com/laravel/boost) guideline with our conventions that ends up in every project's `CLAUDE.md` / agent files.
- **CI workflows:** reusable GitHub workflows for formatting, analysis, tests, coverage, deploys (Ploi) and Sentry releases. All actions are pinned to commit SHAs.
- **Installer:** `php artisan standards:install` sets up all of the above, Dependabot, a default user via [laravel-database-state](https://github.com/pxlrbt/laravel-database-state) and optionally Filament with our plugins.
- **Mutation testing:** `php artisan standards:mutate` mutation-tests specific (new) tests locally.

Nothing here is used at runtime, so servers can keep running `composer install --no-dev`.

## Installation

### New project

```bash
laravel new my-app
cd my-app
composer require --dev pxlrbt/laravel-standards
```

Composer asks once whether to trust the `pxlrbt/laravel-standards` plugin. After that, the installer starts automatically and asks:

- which branch deploys to your development server (`main`, `dev`, `develop`, …)
- a password for the default user `info@pixelarbeit.de` (leave it empty to generate one)
- whether to install Filament with the pxlrbt plugins

### Existing project

```bash
composer require --dev pxlrbt/laravel-standards
```

The installer skips files that already exist, so running it in an existing project is safe. To get the shared configs, replace your local `phpstan.neon`, `rector.php` and `pint.json` with the thin versions shown under [Configuration](#configuration) and keep only your project-specific overrides.

If your project doesn't need the installer (e.g. it seeds users differently), mark it as done before requiring the package:

```bash
composer config extra.pxlrbt-standards.installed true --json
composer config allow-plugins.pxlrbt/laravel-standards true
composer require --dev pxlrbt/laravel-standards
```

### When does the installer run?

The installer only starts on its own when **all** of these are true:

- Composer runs interactively
- the project has an `artisan` file
- `composer.json` doesn't contain `extra.pxlrbt-standards.installed: true`

CI and deploys run Composer non-interactively and the installer sets the marker when it's done, so it never runs there. You can always run it manually:

```bash
php artisan standards:install
php artisan standards:install --no-interaction --filament --dev-branch=dev
```

| Option | Description |
|---|---|
| `--filament` / `--no-filament` | Install Filament without asking, or skip it |
| `--dev-branch=` | Branch that deploys to development (default: `main`) |

## What the installer does

1. **Tooling:** creates `phpstan.neon`, an empty `phpstan-baseline.neon`, `rector.php`, `pint.json` and `.editorconfig`, adds `min-release-age=7` to `.npmrc`, and adds the `analyse` and `format` Composer scripts.
2. **Workflows:** creates `.github/workflows/ci.yml`, `.github/workflows/deploy.yml` and `.github/dependabot.yml`.
3. **Default user:** requires `pxlrbt/laravel-database-state` and creates `database/states/UserState.php` for `info@pixelarbeit.de`. States run automatically after every `migrate`, including on deploy.
4. **Filament (optional):**
   - requires Filament, `filament-environment-indicator`, `laravel-docs`, `filament-changelog` and `filament-spotlight-pro`
   - adds the paid Composer repositories
   - creates the admin panel and registers the plugins in it
5. **Pest 5 projects:** requires `pestphp/pest-plugin-rector` and enables test impact analysis (see [Testing](#testing)).
6. **Boost:** installs `laravel/boost` and adds this package's guideline.
7. Runs the migrations and prints the GitHub secrets you still need to set.

## Configuration

Every config extends the shared one, so your project file only contains what's different.

### Pint

```json
{
    "extend": "vendor/pxlrbt/laravel-standards/config/pint.json"
}
```

Rules you add to this file override the shared ones.

### PHPStan

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/nesbot/carbon/extension.neon
    - vendor/pxlrbt/laravel-standards/config/phpstan.neon
    - phpstan-baseline.neon

parameters:
    paths:
        - app/
```

The shared config sets level 5. Parameters in your file win, so raise the level, add paths or add extensions (e.g. `calebdw/larastan-livewire`) right there. Existing errors go into the baseline with `vendor/bin/phpstan --generate-baseline`.

### Rector

```php
<?php

use Pxlrbt\LaravelStandards\Standards;

return Standards::rector(__DIR__);
```

`Standards::rector()` returns a regular `RectorConfigBuilder`, so you can keep chaining:

```php
return Standards::rector(__DIR__)
    ->withPaths([__DIR__.'/domain'])
    ->withSets([SetList::DEAD_CODE])
    ->withSkip([SomeRector::class]);
```

The Pest rules (`PestSetList::CODING_STYLE`, `ChainExpectCallsRector`, `ConvertAndToExpectRector`) are applied automatically when `pestphp/pest-plugin-rector` is installed.

### AI guidelines (Boost)

Add the package to `boost.json` (the installer does this for you) and run `php artisan boost:update`:

```json
{
    "packages": ["pxlrbt/laravel-standards"]
}
```

Project-specific guidelines still go into `.ai/guidelines/`.

## GitHub workflows

Projects call the reusable workflows from this repository. The installer creates these callers; a typical `ci.yml`:

```yaml
jobs:
  format:
    uses: pxlrbt/laravel-standards/.github/workflows/format.yml@v1
    permissions:
      contents: write
    secrets:
      COMPOSER_AUTH: ${{ secrets.COMPOSER_AUTH }}

  tests:
    needs: format
    uses: pxlrbt/laravel-standards/.github/workflows/tests.yml@v1
    permissions:
      contents: read
    secrets:
      COMPOSER_AUTH: ${{ secrets.COMPOSER_AUTH }}
    with:
      database: sqlite
      shards: '[1, 2, 3, 4]'
```

| Workflow | What it does | Inputs |
|---|---|---|
| `format.yml` | Runs Rector and Pint. Commits the fixes on pushes and same-repo PRs, and fails on fork and Dependabot PRs instead | `php-version` |
| `analyse.yml` | Runs PHPStan, then `composer audit` | `php-version` |
| `tests.yml` | Runs Pest in parallel, sharded | `php-version`, `database` (`mysql` or `sqlite`), `shards` (JSON array), `node-version-file` |
| `coverage.yml` | Runs the full suite with PCOV and writes coverage to the run summary | `php-version`, `database`, `min` (0 = report only), `node-version-file` |
| `deploy.yml` | Triggers a Ploi deploy webhook, and skips if the URL is empty | `environment`; secret `DEPLOY_URL` |
| `sentry-release.yml` | Creates a Sentry release, and skips if `SENTRY_PROJECT` isn't set | secret `SENTRY_AUTH_TOKEN` |

`database: mysql` starts a MySQL 8.4 service. `sqlite` uses whatever your `phpunit.xml` configures.

### Secrets and variables

| Name | Type | Used for |
|---|---|---|
| `COMPOSER_AUTH` | Actions secret (and Dependabot secret) | Paid Composer repositories, as the content of `auth.json` |
| `PLOI_DEV_DEPLOY_URL`, `PLOI_PROD_DEPLOY_URL` | Actions secret | Deploy webhooks |
| `SENTRY_AUTH_TOKEN` | Actions secret | Sentry releases |
| `SENTRY_ORG`, `SENTRY_PROJECT` | Actions variable | Sentry releases |
| `<REGISTRY>_USERNAME`, `<REGISTRY>_PASSWORD` | Dependabot secret | One pair per paid registry in `dependabot.yml` |

```bash
gh secret set COMPOSER_AUTH < auth.json
gh secret set COMPOSER_AUTH --app dependabot < auth.json
gh variable set SENTRY_ORG --body pixelarbeit
```

Dependabot PRs only receive Dependabot secrets, so `COMPOSER_AUTH` has to exist in both places for CI to run on them.

### Dependabot

The generated `dependabot.yml` updates Composer, npm and GitHub Actions weekly, groups minor and patch updates, and waits 7 days before suggesting a new release. It also bumps the pinned action SHAs.

## Supply-chain protection

Freshly published releases are the most common way malicious packages spread, so new versions have to be at least 7 days old:

- **npm:** the installer adds `min-release-age=7` to `.npmrc`. `npm install` and `npm update` then ignore versions published within the last 7 days. `npm ci` installs the lockfile as-is, so CI and deploys aren't affected.
- **Composer:** Composer doesn't support a minimum release age yet. It's reserved for a future release. Composer 2.10+ blocks packages flagged as malware and versions with known security advisories by default, so don't disable `policy` in `composer.json`.
- **Dependabot:** waits 7 days (`cooldown`) for both ecosystems and for GitHub Actions.
- **CI:** `analyse.yml` runs `composer audit`, and all actions are pinned to commit SHAs.

## Testing

### Coverage

Coverage runs in CI through `coverage.yml`. Start with `min: '0'` to see where a project stands, then raise it.

### Mutation testing (local only)

Mutation testing is slow, so it only runs locally, and only on the tests you name:

```bash
php artisan standards:mutate tests/Unit/Billing/PayoutCalculatorTest.php
php artisan standards:mutate tests/Feature/CheckoutTest.php --filter="applies the coupon"
```

| Option | Description |
|---|---|
| `tests*` | Test files to mutation-test |
| `--filter=` | Only run tests matching this filter |
| `--min=80` | Minimum mutation score |

Every test file you pass has to declare the class it tests:

```php
mutates(PayoutCalculator::class);
```

The command changes that class in small ways (mutations) and checks whether your tests notice. Surviving mutations show behavior that no test pins down. The Boost guideline tells AI agents to add `mutates()` to new tests and to run this command on exactly the tests they wrote.

It needs a coverage driver. Install PCOV:

```bash
pecl install pcov
# Homebrew PHP fails with "pcre2.h not found"? Use:
CPPFLAGS="-I/opt/homebrew/include" pecl install pcov
```

### Test impact analysis (Pest 5)

On Pest 5 projects whose tests are all Pest-style (no PHPUnit test classes), the installer adds this to `tests/Pest.php`:

```php
pest()->tia()
    ->locally()
    ->filtered();
```

Local test runs then only execute the tests affected by your changes. CI still runs the full suite. It also needs PCOV. Use `--no-tia` to force a full local run.

## Releasing

- Tag a release (`v1.2.3`) and move the `v1` branch to it:

  ```bash
  git tag -a v1.2.3 -m "v1.2.3"
  git branch -f v1 main
  git push origin main v1 v1.2.3
  ```

- Projects reference the workflows via the `v1` branch, so workflow changes reach every project on their next CI run. Breaking changes go into a new major version (`v2` branch).
- `v1` is a branch, not a tag, because Composer would read a `v1` tag as version `1.0.0`.
- Every push runs [zizmor](https://github.com/zizmorcore/zizmor) on the workflows. Keep third-party actions pinned to full commit SHAs.
