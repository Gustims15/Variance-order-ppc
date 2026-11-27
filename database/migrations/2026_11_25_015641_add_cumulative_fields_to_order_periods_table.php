<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_periods', function (Blueprint $table) {
            $table->decimal('cumulative_quantity', 18, 4)
                ->nullable()
                ->after('quantity');

            $table->string('cum_status', 30)
                ->nullable()
                ->after('cumulative_quantity');

            $table->decimal('cum_difference', 18, 4)
                ->nullable()
                ->after('cum_status');

            $table->index(
                'cum_status',
                'order_period_cum_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('order_periods', function (Blueprint $table) {
            $table->dropIndex('order_period_cum_status_idx');

            $table->dropColumn([
                'cumulative_quantity',
                'cum_status',
                'cum_difference',
            ]);
        });
    }
};
