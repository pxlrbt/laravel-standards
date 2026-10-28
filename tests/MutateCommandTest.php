<?php

use Pxlrbt\LaravelStandards\Commands\MutateCommand;

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
