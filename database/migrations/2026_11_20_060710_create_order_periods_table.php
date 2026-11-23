<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('imported_order_id')
                ->constrained('imported_orders')
                ->cascadeOnDelete();

            $table->date('sr_issue_date');

            $table->decimal('quantity', 18, 4)->default(0);

            $table->timestamps();

            $table->unique(
                ['imported_order_id', 'sr_issue_date'],
                'order_period_unique'
            );

            $table->index('sr_issue_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_periods');
    }
};
