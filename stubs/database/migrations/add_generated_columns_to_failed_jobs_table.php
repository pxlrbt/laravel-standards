<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = $this->table();

        if ($table === null || Schema::hasColumn($table, 'display_name')) {
            return;
        }

        $afterUuid = Schema::hasColumn($table, 'uuid');

        Schema::table($table, function (Blueprint $table) use ($afterUuid): void {
            $displayName = $table->string('display_name')
                ->storedAs("json_unquote(json_extract(`payload`, '$.displayName'))")
                ->index();

            if ($afterUuid) {
                $displayName->after('uuid');
            }

            $table->string('exception_name')
                ->storedAs("left(substring_index(`exception`, ':', 1), 255)")
                ->after('display_name')
                ->index();
        });
    }

    public function down(): void
    {
        $table = $this->table();

        if ($table === null || ! Schema::hasColumn($table, 'display_name')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->dropIndex(['display_name']);
            $table->dropIndex(['exception_name']);
            $table->dropColumn(['display_name', 'exception_name']);
        });
    }

    /**
     * Generated columns use MySQL functions, so other drivers are skipped.
     */
    protected function table(): ?string
    {
        $table = config('queue.failed.table', 'failed_jobs');

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable($table)) {
            return null;
        }

        return $table;
    }
};
