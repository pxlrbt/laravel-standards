<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    File::delete(File::glob(database_path('migrations/*_add_generated_columns_to_failed_jobs_table.php')));
});

afterEach(function (): void {
    File::delete(File::glob(database_path('migrations/*_add_generated_columns_to_failed_jobs_table.php')));
});

it('publishes the migration once', function (): void {
    $this->artisan('standards:failed-jobs')->assertSuccessful();
    $this->artisan('standards:failed-jobs')->expectsOutputToContain('exists, skipped')->assertSuccessful();

    expect(File::glob(database_path('migrations/*_add_generated_columns_to_failed_jobs_table.php')))->toHaveCount(1);
});

it('skips drivers other than mysql and mariadb', function (): void {
    config(['database.default' => 'testing', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:']]);

    Schema::create('jobs_failed', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid');
        $table->longText('payload');
        $table->longText('exception');
    });

    config(['queue.failed.table' => 'jobs_failed']);

    $migration = require __DIR__.'/../stubs/database/migrations/add_generated_columns_to_failed_jobs_table.php';
    $migration->up();

    expect(Schema::hasColumn('jobs_failed', 'display_name'))->toBeFalse();

    $migration->down();
});
