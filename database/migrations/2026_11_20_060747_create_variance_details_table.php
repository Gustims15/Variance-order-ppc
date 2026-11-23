<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variance_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('variance_report_id')
                ->constrained('variance_reports')
                ->cascadeOnDelete();

            $table->string('department', 100);
            $table->string('sr_number', 100);
            $table->string('product_code', 150);

            $table->string('previous_line_number', 100)->nullable();
            $table->string('current_line_number', 100)->nullable();

            $table->string('product_name', 500)->nullable();

            $table->unsignedInteger('previous_source_row')->nullable();
            $table->unsignedInteger('current_source_row')->nullable();

            $table->decimal('previous_total', 18, 4)->nullable();
            $table->decimal('current_total', 18, 4)->nullable();
            $table->decimal('total_variance', 18, 4)->nullable();

            $table->string('status', 30);
            $table->string('reason', 100)->nullable();

            $table->timestamps();

            $table->index(
                ['variance_report_id', 'department'],
                'variance_department_idx'
            );

            $table->index(
                ['variance_report_id', 'status'],
                'variance_status_idx'
            );

            $table->index(
                ['sr_number', 'product_code'],
                'variance_product_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variance_details');
    }
};
