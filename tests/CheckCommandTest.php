<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    File::ensureDirectoryExists(base_path('tests/Models'));
    File::put(base_path('tests/Models/NewTest.php'), '<?php mutates(Invoice::class);');
    File::put(base_path('tests/Models/UntrackedTest.php'), '<?php mutates(Invoice::class);');
    File::put(base_path('tests/Models/ExistingTest.php'), '<?php mutates(Invoice::class);');
});

afterEach(function (): void {
    File::delete([base_path('tests/Models/NewTest.php'), base_path('tests/Models/UntrackedTest.php'), base_path('tests/Models/ExistingTest.php')]);
});

it('mutation-tests new test files, not changed ones, while analysing all of them', function (): void {
    Process::fake(function (PendingProcess $process) {
        $command = (array) $process->command;

        if (($command[0] ?? null) !== 'git' || ($command[1] ?? null) === 'rev-parse') {
            return Process::result();
        }

        if ($command[1] === 'ls-files') {
            return Process::result('tests/Models/UntrackedTest.php');
        }

        return Process::result(in_array('--diff-filter=A', $command, true)
            ? 'tests/Models/NewTest.php'
            : "tests/Models/NewTest.php\ntests/Models/ExistingTest.php");
    });

    $this->artisan('standards:check')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => in_array('standards:mutate', (array) $process->command, true)
        && in_array('tests/Models/NewTest.php', (array) $process->command, true)
        && in_array('tests/Models/UntrackedTest.php', (array) $process->command, true)
        && ! in_array('tests/Models/ExistingTest.php', (array) $process->command, true));
})->skip(fn (): bool => ! extension_loaded('pcov') && ! extension_loaded('xdebug'), 'needs a coverage driver');

it('skips mutation testing when only existing test files changed', function (): void {
    Process::fake(function (PendingProcess $process) {
        $command = (array) $process->command;

        if (($command[0] ?? null) !== 'git' || $command[1] !== 'diff' || in_array('--diff-filter=A', $command, true)) {
            return Process::result();
        }

        return Process::result('tests/Models/ExistingTest.php');
    });

    $this->artisan('standards:check')->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('standards:mutate', (array) $process->command, true));
});
