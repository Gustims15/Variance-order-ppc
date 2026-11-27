<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_periods', function (Blueprint $table) {
            $table->decimal('expected_cumulative_quantity', 18, 4)
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_periods', function (Blueprint $table) {
            $table->dropColumn('expected_cumulative_quantity');
        });
    }
};
