<?php

namespace Pxlrbt\LaravelStandards\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class CheckCommand extends Command
{
    protected $signature = 'standards:check
        {--hook : Run as Claude Code Stop hook: read the hook input and report failures on stderr with exit code 2}';

    protected $description = 'Run PHPStan, type coverage and mutation testing on the uncommitted changes';

    public function handle(): int
    {
        if ($this->option('hook') && $this->isRetryOfBlockedStop()) {
            return self::SUCCESS;
        }

        $changedFiles = $this->changedPhpFiles();

        if ($changedFiles === []) {
            return self::SUCCESS;
        }

        $failures = array_filter([
            'PHPStan' => $this->runPhpStan($changedFiles),
            'Type coverage' => $this->runTypeCoverage(),
            'Mutation testing' => $this->runMutationTesting($changedFiles),
        ]);

        if ($failures === []) {
            return self::SUCCESS;
        }

        $report = collect($failures)
            ->map(fn (string $output, string $check): string => "{$check} failed:\n".trim($output))
            ->implode("\n\n");

        if (! $this->option('hook')) {
            $this->output->writeln($report);

            return self::FAILURE;
        }

        fwrite(STDERR, $report.PHP_EOL.'Fix these before finishing. Do not lower thresholds, add ignores or baseline entries.'.PHP_EOL);

        return 2;
    }

    /**
     * Blocks the stop only once, so a check the agent cannot fix doesn't loop forever.
     */
    protected function isRetryOfBlockedStop(): bool
    {
        $input = json_decode((string) stream_get_contents(STDIN), true);

        return is_array($input) && ($input['stop_hook_active'] ?? false) === true;
    }

    /**
     * @return list<string>
     */
    protected function changedPhpFiles(): array
    {
        $changed = Process::path(base_path())->run(['git', 'diff', '--name-only', 'HEAD'])->output();
        $untracked = Process::path(base_path())->run(['git', 'ls-files', '--others', '--exclude-standard'])->output();

        return collect(explode("\n", $changed."\n".$untracked))
            ->filter(fn (string $file): bool => str_ends_with($file, '.php') && File::exists(base_path($file)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $changedFiles
     */
    protected function runPhpStan(array $changedFiles): ?string
    {
        $sourceFiles = array_values(array_filter($changedFiles, fn (string $file): bool => ! str_starts_with($file, 'tests/')));

        if ($sourceFiles === []) {
            return null;
        }

        return $this->failedOutput([base_path('vendor/bin/phpstan'), 'analyse', '--no-progress', '--memory-limit=-1', ...$sourceFiles]);
    }

    protected function runTypeCoverage(): ?string
    {
        if (! InstalledVersions::isInstalled('pestphp/pest-plugin-type-coverage')) {
            return null;
        }

        return $this->failedOutput([PHP_BINARY, '-d', 'memory_limit=-1', base_path('vendor/bin/pest'), '--type-coverage', '--min=100', ...(InstallCommand::usesPestFive() ? ['--no-tia'] : [])]);
    }

    /**
     * @param  list<string>  $changedFiles
     */
    protected function runMutationTesting(array $changedFiles): ?string
    {
        $tests = array_values(array_filter(
            $changedFiles,
            fn (string $file): bool => str_starts_with($file, 'tests/')
                && str_ends_with($file, 'Test.php')
                && preg_match(MutateCommand::BUSINESS_LOGIC_TESTS_PATTERN, '/'.$file) === 1
                && preg_match('/\b(mutates|covers)\(/', File::get(base_path($file))) === 1,
        ));

        if ($tests === [] || (! extension_loaded('pcov') && ! extension_loaded('xdebug'))) {
            return null;
        }

        return $this->failedOutput([PHP_BINARY, base_path('artisan'), 'standards:mutate', ...$tests], env: []);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    protected function failedOutput(array $command, array $env = ['XDEBUG_MODE' => 'off']): ?string
    {
        $result = Process::path(base_path())
            ->forever()
            ->env($env)
            ->run($command);

        if ($result->successful()) {
            return null;
        }

        return $result->output().$result->errorOutput();
    }
}
