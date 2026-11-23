<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variance_period_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('variance_detail_id')
                ->constrained('variance_details')
                ->cascadeOnDelete();

            $table->date('sr_issue_date');

            $table->decimal('forecast_quantity', 18, 4)->nullable();
            $table->decimal('latest_quantity', 18, 4)->nullable();
            $table->decimal('variance', 18, 4)->nullable();

            $table->string('status', 30);

            $table->timestamps();

            $table->unique(
                ['variance_detail_id', 'sr_issue_date'],
                'variance_period_unique'
            );

            $table->index('sr_issue_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variance_period_details');
    }
};
