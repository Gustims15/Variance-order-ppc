<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variance_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('previous_import_id')
                ->constrained('weekly_imports');

            $table->foreignId('current_import_id')
                ->constrained('weekly_imports');

            $table->string('status', 30)->default('pending');

            $table->unsignedInteger('total_products')->default(0);

            $table->unsignedInteger('new_order_count')->default(0);
            $table->unsignedInteger('increased_count')->default(0);
            $table->unsignedInteger('decreased_count')->default(0);
            $table->unsignedInteger('mixed_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('needs_review_count')->default(0);

            $table->unsignedInteger('total_changes')->default(0);

            $table->dateTime('generated_at')->nullable();

            $table->timestamps();

            $table->index('status');

            $table->index(
                ['previous_import_id', 'current_import_id'],
                'variance_import_pair_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variance_reports');
    }
};
