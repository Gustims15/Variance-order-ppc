<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_imports', function (Blueprint $table) {
            $table->date('snapshot_date')
                ->nullable();

            $table->string('snapshot_week', 10)
                ->nullable();

            $table->index(
                ['snapshot_date', 'status'],
                'weekly_import_snapshot_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('weekly_imports', function (Blueprint $table) {
            $table->dropIndex(
                'weekly_import_snapshot_status_idx'
            );

            $table->dropColumn([
                'snapshot_date',
                'snapshot_week',
            ]);
        });
    }
};
