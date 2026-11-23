<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('weekly_import_id')
                ->constrained('weekly_imports')
                ->cascadeOnDelete();

            $table->string('department', 100);
            $table->string('worksheet_name', 100);

            $table->string('sr_number', 100);
            $table->string('product_code', 150);
            $table->string('line_number', 100);

            $table->string('car_model', 150)->nullable();
            $table->string('family', 150)->nullable();
            $table->string('jig_type', 150)->nullable();

            $table->unsignedInteger('source_row')->nullable();

            $table->char('identity_hash', 64);

            $table->timestamps();

            $table->unique(
                ['weekly_import_id', 'worksheet_name', 'source_row'],
                'import_source_row_unique'
            );

            $table->index(
                ['weekly_import_id', 'department'],
                'import_department_idx'
            );

            $table->index(
                ['department', 'sr_number', 'product_code'],
                'import_product_identity_idx'
            );

            $table->index('identity_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_orders');
    }
};
