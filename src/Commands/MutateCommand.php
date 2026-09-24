<?php

namespace Pxlrbt\LaravelStandards\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class MutateCommand extends Command
{
    protected $signature = 'standards:mutate
        {tests* : Test files to mutation-test}
        {--filter= : Only run tests matching this filter}
        {--min=80 : Minimum mutation score}';

    protected $description = 'Mutation-test the given test files against their mutates() classes';

    public function handle(): int
    {
        if (! extension_loaded('pcov') && ! extension_loaded('xdebug')) {
            $this->components->error('Mutation testing needs a coverage driver. Install PCOV: pecl install pcov');

            return self::FAILURE;
        }

        /** @var list<string> $tests */
        $tests = $this->argument('tests');

        $testsWithoutSubject = array_filter(
            $tests,
            fn (string $test): bool => ! File::exists(base_path($test))
                || preg_match('/\b(mutates|covers)\(/', File::get(base_path($test))) !== 1,
        );

        if ($testsWithoutSubject !== []) {
            $this->components->error('These test files are missing or do not declare mutates(Subject::class):');
            $this->components->bulletList($testsWithoutSubject);

            return self::FAILURE;
        }

        return Process::path(base_path())
            ->forever()
            ->tty(Process::supportsTty())
            ->run([
                base_path('vendor/bin/pest'),
                '--mutate',
                '--covered-only',
                "--min={$this->option('min')}",
                ...($this->option('filter') ? ["--filter={$this->option('filter')}"] : []),
                ...$tests,
            ], fn (string $type, string $output) => $this->output->write($output))
            ->exitCode();
    }
}
