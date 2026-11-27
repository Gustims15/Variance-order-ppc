<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variance_period_details', function (Blueprint $table) {
            $table->decimal('previous_cumulative_quantity', 18, 4)->nullable();
            $table->decimal('current_cumulative_quantity', 18, 4)->nullable();
            $table->decimal('cumulative_variance', 18, 4)->nullable();
            $table->string('cum_status', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('variance_period_details', function (Blueprint $table) {
            $table->dropColumn([
                'previous_cumulative_quantity',
                'current_cumulative_quantity',
                'cumulative_variance',
                'cum_status',
            ]);
        });
    }
};
