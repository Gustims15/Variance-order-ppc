<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove the old unique constraint on import pair.
        Schema::table('variance_reports', function (Blueprint $table) {
            $table->dropUnique('variance_import_pair_unique');
        });

        Schema::table('variance_reports', function (Blueprint $table) {
            $table->unsignedInteger('revision_number')
                ->default(1);

           $table->foreignId('parent_report_id')
                ->nullable()
                ->constrained('variance_reports')
                ->noActionOnDelete();

            $table->boolean('is_latest')
                ->default(true);

            // Each import pair can have multiple revisions,
            // but each revision number must be unique.
            $table->unique(
                [
                    'previous_import_id',
                    'current_import_id',
                    'revision_number',
                ],
                'variance_import_revision_unique'
            );

            $table->index(
                [
                    'previous_import_id',
                    'current_import_id',
                    'is_latest',
                ],
                'variance_import_latest_idx'
            );
        });
    }

    public function down(): void
    {
        // Rolling back after multiple revisions exist would
        // violate the original unique import-pair constraint.
        $hasMultipleRevisions = DB::table('variance_reports')
            ->select('previous_import_id', 'current_import_id')
            ->groupBy('previous_import_id', 'current_import_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultipleRevisions) {
            throw new RuntimeException(
                'Cannot rollback revision history while multiple revisions exist.'
            );
        }

        Schema::table('variance_reports', function (Blueprint $table) {
            $table->dropIndex('variance_import_latest_idx');
            $table->dropUnique('variance_import_revision_unique');
            $table->dropForeign(['parent_report_id']);

            $table->dropColumn([
                'revision_number',
                'parent_report_id',
                'is_latest',
            ]);

            $table->unique(
                ['previous_import_id', 'current_import_id'],
                'variance_import_pair_unique'
            );
        });
    }
};
