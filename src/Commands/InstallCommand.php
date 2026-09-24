<?php

namespace Pxlrbt\LaravelStandards\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\suggest;

class InstallCommand extends Command
{
    protected $signature = 'standards:install
        {--filament : Install Filament with the pxlrbt plugins}
        {--no-filament : Skip Filament}
        {--dev-branch= : Branch that deploys to development}';

    protected $description = 'Install pxlrbt tooling, workflows and default packages';

    /** @var array<string, string> */
    protected const PAID_REPOSITORIES = [
        'pxlrbt/filament-changelog' => 'https://filament-changelog.composer.pxlrbt.de',
        'pxlrbt/filament-spotlight-pro' => 'https://filament-spotlight-pro.composer.pxlrbt.de',
    ];

    public function handle(): int
    {
        $shouldInstallFilament = $this->shouldInstallFilament();
        $devBranch = $this->option('dev-branch') ?: ($this->input->isInteractive()
            ? suggest('Which branch deploys to development?', ['main', 'dev', 'develop'], default: 'main', required: true)
            : 'main');

        $this->installTooling();
        $this->installWorkflows($devBranch, $shouldInstallFilament);
        $this->installDatabaseState();

        if ($shouldInstallFilament) {
            $this->installFilament();
        }

        $this->installBoost();
        $this->runProcess([PHP_BINARY, 'artisan', 'migrate', '--graceful']);

        $this->updateComposerJson(function (array $composer): array {
            $composer['extra']['pxlrbt-standards']['installed'] = true;

            return $composer;
        });

        $this->components->info('pxlrbt standards installed.');
        $this->printSecretInstructions($shouldInstallFilament);

        return self::SUCCESS;
    }

    protected function shouldInstallFilament(): bool
    {
        if ($this->option('filament')) {
            return true;
        }

        if ($this->option('no-filament') || $this->isRequired('filament/filament')) {
            return false;
        }

        return $this->input->isInteractive() && confirm('Install Filament with the pxlrbt plugins?');
    }

    protected function installTooling(): void
    {
        $this->copyStub('phpstan.neon');
        $this->copyStub('phpstan-baseline.neon');
        $this->copyStub('rector.php');
        $this->copyStub('pint.json');
        $this->copyStub('.editorconfig');

        $this->updateComposerJson(function (array $composer): array {
            $composer['scripts']['analyse'] ??= ['@putenv XDEBUG_MODE=off', 'phpstan analyse --memory-limit=2G'];
            $composer['scripts']['format'] ??= ['rector', 'pint --parallel --dirty'];
            $boostUpdate = "@php -r \"if (getenv('APP_ENV') !== 'production') { passthru('php artisan boost:update --ansi'); }\"";
            $postUpdate = (array) ($composer['scripts']['post-update-cmd'] ?? []);

            if (! in_array($boostUpdate, $postUpdate, true)) {
                $composer['scripts']['post-update-cmd'] = [...$postUpdate, $boostUpdate];
            }

            return $composer;
        });

        if (! $this->isRequired('pestphp/pest')) {
            $this->components->warn('Pest is not installed. The shared test workflow runs vendor/bin/pest.');

            return;
        }

        if (! self::usesPestFive()) {
            return;
        }

        $this->requirePackages(['pestphp/pest-plugin-rector'], dev: true);

        $classBasedTests = collect(File::allFiles(base_path('tests')))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), 'Test.php')
                && preg_match('/^(final\s+)?class\s+\w+\s+extends\s/m', $file->getContents()) === 1);

        if ($classBasedTests->isNotEmpty()) {
            $this->components->warn("Test impact analysis skipped: it needs Pest-style tests, found {$classBasedTests->count()} PHPUnit class tests.");

            return;
        }

        $pestConfigPath = base_path('tests/Pest.php');

        $pestConfig = File::exists($pestConfigPath) ? File::get($pestConfigPath) : '<?php'.PHP_EOL;

        if (! str_contains($pestConfig, 'tia()')) {
            File::put($pestConfigPath, rtrim($pestConfig).PHP_EOL.PHP_EOL.'pest()->tia()'.PHP_EOL.'    ->locally()'.PHP_EOL.'    ->filtered();'.PHP_EOL);
            $this->components->twoColumnDetail('tests/Pest.php', '<fg=green>test impact analysis enabled</>');
        }
    }

    public static function usesPestFive(): bool
    {
        return version_compare((string) InstalledVersions::getVersion('pestphp/pest'), '5.0.0', '>=');
    }

    protected function installWorkflows(string $devBranch, bool $withPaidRepositories): void
    {
        $this->copyStub('.github/workflows/ci.yml', replacements: ['{{ devBranch }}' => $devBranch]);
        $this->copyStub('.github/workflows/deploy.yml', replacements: ['{{ devBranch }}' => $devBranch]);

        $registries = $withPaidRepositories ? $this->dependabotRegistries() : [];

        $this->copyStub('.github/dependabot.yml', replacements: [
            '{{ devBranch }}' => $devBranch,
            "{{ registries }}\n" => $registries === [] ? '' : "registries:\n".collect($registries)
                ->map(fn (string $url, string $name): string => "  {$name}:\n    type: composer-repository\n    url: {$url}\n    username: \${{ secrets.".$this->secretName($name, 'USERNAME')." }}\n    password: \${{ secrets.".$this->secretName($name, 'PASSWORD')." }}\n")
                ->implode(''),
            "{{ composerRegistries }}\n" => $registries === [] ? '' : "    registries:\n".collect($registries)
                ->keys()
                ->map(fn (string $name): string => "      - {$name}\n")
                ->implode(''),
        ]);
    }

    protected function installDatabaseState(): void
    {
        $this->requirePackages(['pxlrbt/laravel-database-state']);

        $this->updateComposerJson(function (array $composer): array {
            $composer['autoload']['psr-4']['Database\\States\\'] ??= 'database/states/';

            return $composer;
        });

        if (! File::exists(base_path('database/states/UserState.php'))) {
            $this->copyStub('database/states/UserState.php', replacements: [
                '{{ passwordHash }}' => Hash::make($this->askForPassword()),
            ]);
        }

        $this->runProcess(['composer', 'dump-autoload']);
    }

    protected function askForPassword(): string
    {
        $password = $this->input->isInteractive()
            ? password('Password for info@pixelarbeit.de', hint: 'Leave empty to generate one.')
            : '';

        if ($password !== '') {
            return $password;
        }

        $password = Str::password(24);

        $this->components->warn("Generated password for info@pixelarbeit.de: {$password}");

        return $password;
    }

    protected function installFilament(): void
    {
        foreach (self::PAID_REPOSITORIES as $package => $url) {
            $this->runProcess(['composer', 'config', "repositories.{$package}", 'composer', $url]);
        }

        $this->requirePackages([
            'filament/filament',
            'pxlrbt/filament-environment-indicator',
            'pxlrbt/laravel-docs',
            'pxlrbt/filament-changelog',
            'pxlrbt/filament-spotlight-pro',
        ]);

        $panelProviderPath = app_path('Providers/Filament/AdminPanelProvider.php');

        if (! File::exists($panelProviderPath)) {
            $this->runProcess([PHP_BINARY, 'artisan', 'filament:install', '--panels', '--no-interaction']);
        }

        $this->runProcess([PHP_BINARY, 'artisan', 'vendor:publish', '--tag=docs-assets', '--force']);

        File::ensureDirectoryExists(base_path('changelog'));
        File::ensureDirectoryExists(base_path('docs'));

        if (! File::exists(base_path('docs/index.md'))) {
            File::put(base_path('docs/index.md'), "# Documentation\n");
        }

        $this->registerFilamentPlugins($panelProviderPath);

        $this->updateComposerJson(function (array $composer): array {
            $filamentUpgrade = '@php artisan filament:upgrade';
            $postAutoloadDump = (array) ($composer['scripts']['post-autoload-dump'] ?? []);

            if (! in_array($filamentUpgrade, $postAutoloadDump, true)) {
                $composer['scripts']['post-autoload-dump'] = [...$postAutoloadDump, $filamentUpgrade];
            }

            return $composer;
        });
    }

    protected function registerFilamentPlugins(string $panelProviderPath): void
    {
        $content = File::get($panelProviderPath);

        if (str_contains($content, 'EnvironmentIndicatorPlugin')) {
            return;
        }

        $plugins = <<<'PHP'
            ->pages([
                ChangelogPage::class,
            ])
            ->plugins([
                EnvironmentIndicatorPlugin::make(),
                DocsPlugin::make(),
                ChangelogPlugin::make()
                    ->files(base_path('changelog')),
                SpotlightPlugin::make()->registerItems([
                    RegisterPages::make(),
                    RegisterResources::make(),
                    RegisterCommands::make(),
                ]),
            ])
PHP;

        $imports = <<<'PHP'
use pxlrbt\FilamentChangelog\ChangelogPlugin;
use pxlrbt\FilamentChangelog\Filament\Pages\ChangelogPage;
use pxlrbt\FilamentEnvironmentIndicator\EnvironmentIndicatorPlugin;
use pxlrbt\FilamentSpotlightPro\SpotlightPlugin;
use pxlrbt\FilamentSpotlightPro\SpotlightProviders\RegisterCommands;
use pxlrbt\FilamentSpotlightPro\SpotlightProviders\RegisterPages;
use pxlrbt\FilamentSpotlightPro\SpotlightProviders\RegisterResources;
use pxlrbt\LaravelDocs\Filament\DocsPlugin;
PHP;

        if (preg_match('/^(\s*)->id\(\'admin\'\)$/m', $content, $matches) !== 1) {
            $this->components->warn('Could not find ->id(\'admin\') in the panel provider. Register these plugins manually:');
            $this->line($imports.PHP_EOL.PHP_EOL.$plugins);

            return;
        }

        $indentation = $matches[1];
        $indentedPlugins = preg_replace('/^/m', $indentation, $plugins);

        $content = str_replace($matches[0], $matches[0].PHP_EOL.$indentedPlugins, $content);
        $content = preg_replace('/^(namespace [^;]+;)$/m', '$1'.PHP_EOL.PHP_EOL.$imports, $content, 1);

        File::put($panelProviderPath, $content);

        $this->runProcess([base_path('vendor/bin/pint'), $panelProviderPath]);
    }

    protected function installBoost(): void
    {
        if (! $this->isRequired('laravel/boost')) {
            $this->requirePackages(['laravel/boost'], dev: true);
        }

        $boostConfigPath = base_path('boost.json');
        $boostConfig = File::exists($boostConfigPath) ? File::json($boostConfigPath) : [];
        $boostConfig['packages'] = array_values(array_unique([...$boostConfig['packages'] ?? [], 'pxlrbt/laravel-standards']));

        File::put($boostConfigPath, json_encode($boostConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->runProcess([PHP_BINARY, 'artisan', 'boost:install', ...($this->input->isInteractive() ? [] : ['--no-interaction'])]);
    }

    protected function printSecretInstructions(bool $withPaidRepositories): void
    {
        $this->newLine();
        $this->line('Set these GitHub secrets (Actions and Dependabot):');
        $this->line('  gh secret set COMPOSER_AUTH < auth.json');
        $this->line('  gh secret set COMPOSER_AUTH --app dependabot < auth.json');
        $this->line('  gh secret set PLOI_DEV_DEPLOY_URL');
        $this->line('  gh secret set PLOI_PROD_DEPLOY_URL');
        $this->line('  gh secret set SENTRY_AUTH_TOKEN && gh variable set SENTRY_ORG && gh variable set SENTRY_PROJECT');

        if (! $withPaidRepositories) {
            return;
        }

        foreach (array_keys($this->dependabotRegistries()) as $name) {
            $this->line("  gh secret set {$this->secretName($name, 'USERNAME')} --app dependabot");
            $this->line("  gh secret set {$this->secretName($name, 'PASSWORD')} --app dependabot");
        }
    }

    /**
     * @return array<string, string>
     */
    protected function dependabotRegistries(): array
    {
        return collect(self::PAID_REPOSITORIES)
            ->mapWithKeys(fn (string $url, string $package): array => [Str::after($package, '/') => $url])
            ->all();
    }

    protected function secretName(string $registry, string $suffix): string
    {
        return Str::of($registry)->upper()->replace('-', '_')->append('_', $suffix)->toString();
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function copyStub(string $stub, ?string $target = null, array $replacements = []): void
    {
        $targetPath = base_path($target ?? $stub);

        if (File::exists($targetPath)) {
            $this->components->twoColumnDetail($stub, '<fg=yellow>exists, skipped</>');

            return;
        }

        File::ensureDirectoryExists(dirname($targetPath));
        File::put($targetPath, strtr(File::get(__DIR__.'/../../stubs/'.$stub), $replacements));

        $this->components->twoColumnDetail($stub, '<fg=green>created</>');
    }

    /**
     * @param  list<string>  $packages
     */
    protected function requirePackages(array $packages, bool $dev = false): void
    {
        $missingPackages = array_values(array_filter($packages, fn (string $package): bool => ! $this->isRequired($package)));

        if ($missingPackages === []) {
            return;
        }

        $this->runProcess(['composer', 'require', ...$missingPackages, '--with-all-dependencies', ...($dev ? ['--dev'] : [])]);
    }

    protected function isRequired(string $package): bool
    {
        $composer = $this->readComposerJson();

        return isset($composer['require'][$package]) || isset($composer['require-dev'][$package]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function readComposerJson(): array
    {
        return json_decode(File::get(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     */
    protected function updateComposerJson(callable $callback): void
    {
        $composer = $callback($this->readComposerJson());

        File::put(
            base_path('composer.json'),
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
        );
    }

    /**
     * @param  list<string>  $command
     */
    protected function runProcess(array $command): void
    {
        $process = new Process($command, base_path(), ['PXLRBT_STANDARDS_INSTALLING' => '1'], timeout: null);

        if ($this->input->isInteractive() && Process::isTtySupported()) {
            $process->setTty(true);
        }

        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Command failed: '.implode(' ', $command));
        }
    }
}
