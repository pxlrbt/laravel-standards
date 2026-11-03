<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Pxlrbt\LaravelStandards\Commands\MutateCommand;

beforeEach(function (): void {
    File::ensureDirectoryExists(base_path('tests/Models'));
    File::put(base_path('tests/Models/InvoiceTest.php'), '<?php mutates(Invoice::class);');
    File::put(base_path('tests/PageTest.php'), '<?php mutates(Page::class);');
});

afterEach(function (): void {
    File::delete([base_path('tests/Models/InvoiceTest.php'), base_path('tests/PageTest.php')]);
});

it('runs every test file in its own process with its own minimum score', function (): void {
    Process::fake();

    $this->artisan('standards:mutate', ['tests' => ['tests/Models/InvoiceTest.php', 'tests/PageTest.php']])
        ->assertSuccessful();

    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 2);
    Process::assertRan(fn (PendingProcess $process): bool => in_array('--min=90', $process->command, true)
        && in_array('tests/Models/InvoiceTest.php', $process->command, true)
        && ! in_array('tests/PageTest.php', $process->command, true));
    Process::assertRan(fn (PendingProcess $process): bool => in_array('--min=80', $process->command, true)
        && in_array('tests/PageTest.php', $process->command, true)
        && ! in_array('tests/Models/InvoiceTest.php', $process->command, true));
})->skip(fn (): bool => ! extension_loaded('pcov') && ! extension_loaded('xdebug'), 'needs a coverage driver');

it('fails but still runs the remaining files when one file fails', function (): void {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(exitCode: 1))
        ->push(Process::result())]);

    $this->artisan('standards:mutate', ['tests' => ['tests/Models/InvoiceTest.php', 'tests/PageTest.php']])
        ->assertFailed();

    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 2);
})->skip(fn (): bool => ! extension_loaded('pcov') && ! extension_loaded('xdebug'), 'needs a coverage driver');

it('requires a higher mutation score for tests of business logic', function (array $tests, int $minScore): void {
    expect(MutateCommand::minScoreFor($tests))->toBe($minScore);
})->with([
    'action' => [['tests/Feature/Actions/SendInvoiceActionTest.php'], 90],
    'model' => [['tests/Feature/Models/InvoiceTest.php'], 90],
    'service' => [['tests/Unit/Services/PayoutCalculatorTest.php'], 90],
    'support class' => [['tests/Feature/Support/MoneyTest.php'], 90],
    'filament resource' => [['tests/Feature/Filament/Resources/InvoiceResourceTest.php'], 80],
    'mixed' => [['tests/Feature/Filament/PageTest.php', 'tests/Feature/Models/InvoiceTest.php'], 90],
    'name only contains a folder name' => [['tests/Feature/ModelsOverviewTest.php'], 80],
]);
