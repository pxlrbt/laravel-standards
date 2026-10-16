<?php

namespace Pxlrbt\LaravelStandards\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class FailedJobsCommand extends Command
{
    protected $signature = 'standards:failed-jobs';

    protected $description = 'Publish a migration that adds indexed display_name and exception_name columns to the failed jobs table';

    protected const MIGRATION = 'add_generated_columns_to_failed_jobs_table.php';

    public function handle(): int
    {
        $existing = File::glob(database_path('migrations/*_'.self::MIGRATION));

        if ($existing !== []) {
            $this->components->twoColumnDetail('database/migrations/'.basename($existing[0]), '<fg=yellow>exists, skipped</>');

            return self::SUCCESS;
        }

        $target = 'database/migrations/'.now()->format('Y_m_d_His').'_'.self::MIGRATION;

        File::ensureDirectoryExists(database_path('migrations'));
        File::copy(__DIR__.'/../../stubs/database/migrations/'.self::MIGRATION, base_path($target));

        $this->components->twoColumnDetail($target, '<fg=green>created</>');

        return self::SUCCESS;
    }
}
