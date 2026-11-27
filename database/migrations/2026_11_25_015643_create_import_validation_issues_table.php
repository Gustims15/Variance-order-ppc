<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_validation_issues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('weekly_import_id')
                ->constrained('weekly_imports')
                ->cascadeOnDelete();

            $table->foreignId('imported_order_id')
  		  ->nullable()
   		  ->constrained('imported_orders')
  		  ->onDelete('no action');

            $table->date('sr_issue_date')->nullable();

            $table->unsignedInteger('source_row')->nullable();

            $table->string('issue_type', 80);

            $table->decimal('expected_value', 18, 4)
                ->nullable();

            $table->decimal('actual_value', 18, 4)
                ->nullable();

            $table->decimal('difference', 18, 4)
                ->nullable();

            $table->string('status', 30)
                ->default('pending');

            $table->text('resolution_note')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('reviewed_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(
                ['weekly_import_id', 'status'],
                'import_issue_status_idx'
            );

            $table->index(
                ['weekly_import_id', 'issue_type'],
                'import_issue_type_idx'
            );

            $table->index(
                ['imported_order_id', 'sr_issue_date'],
                'import_issue_order_period_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_validation_issues');
    }
};
