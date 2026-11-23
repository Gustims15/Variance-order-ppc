<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_item_histories', function (Blueprint $table) {
            $table->foreignId('review_item_id')
                ->constrained('review_items')
                ->cascadeOnDelete();

            $table->string('action', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('note')->nullable();

            $table->foreignId('performed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('performed_at');

            $table->index(
                ['review_item_id', 'performed_at'],
                'review_history_item_date_idx'
            );

            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::table('review_item_histories', function (Blueprint $table) {
            $table->dropForeign(['review_item_id']);
            $table->dropForeign(['performed_by']);

            $table->dropIndex('review_history_item_date_idx');
            $table->dropIndex(['action']);

            $table->dropColumn([
                'review_item_id',
                'action',
                'from_status',
                'to_status',
                'note',
                'performed_by',
                'performed_at',
            ]);
        });
    }
};
