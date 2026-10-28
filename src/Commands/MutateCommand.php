<?php

namespace Pxlrbt\LaravelStandards\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class MutateCommand extends Command
{
    public const int MIN_SCORE = 80;

    public const int BUSINESS_LOGIC_MIN_SCORE = 90;

    /**
     * Tests mirror the path of the class they test, so these folders hold the tests of business logic.
     */
    public const string BUSINESS_LOGIC_TESTS_PATTERN = '#/(Actions|Models|Services|Support)/#';

    protected $signature = 'standards:mutate
        {tests* : Test files to mutation-test}
        {--filter= : Only run tests matching this filter}
        {--min= : Minimum mutation score (default 90 for tests of actions, models, services and support classes, otherwise 80)}';

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

        $result = Process::path(base_path())
            ->forever()
            ->run([
                base_path('vendor/bin/pest'),
                '--mutate',
                '--covered-only',
                ...(InstallCommand::usesPestFive() ? ['--no-tia'] : []),
                ...($this->output->isDecorated() ? ['--colors=always'] : []),
                '--min='.($this->option('min') ?? self::minScoreFor($tests)),
                ...($this->option('filter') ? ["--filter={$this->option('filter')}"] : []),
                ...$tests,
            ], fn (string $type, string $output) => $this->output->write($output));

        // Pest scores a class without any mutation (e.g. an enum with a plain match) as 0 %, no test can change that.
        if ($result->failed() && str_contains($result->output(), 'No mutations created')) {
            return self::SUCCESS;
        }

        return $result->exitCode() ?? self::FAILURE;
    }

    /**
     * @param  list<string>  $tests
     */
    public static function minScoreFor(array $tests): int
    {
        foreach ($tests as $test) {
            if (preg_match(self::BUSINESS_LOGIC_TESTS_PATTERN, '/'.$test) === 1) {
                return self::BUSINESS_LOGIC_MIN_SCORE;
            }
        }

        return self::MIN_SCORE;
    }
}
