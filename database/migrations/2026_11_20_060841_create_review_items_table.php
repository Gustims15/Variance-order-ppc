<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('variance_report_id')
                ->constrained('variance_reports');

            $table->foreignId('variance_detail_id')
                ->nullable()
                ->constrained('variance_details');

            $table->string('reason', 100);

            $table->string('status', 30)->default('pending');

            $table->text('resolution_note')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(
                ['variance_report_id', 'status'],
                'review_report_status_idx'
            );

            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_items');
    }
};
