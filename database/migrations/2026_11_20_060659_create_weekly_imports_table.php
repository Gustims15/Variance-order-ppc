<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_imports', function (Blueprint $table) {
            $table->id();

            $table->string('original_filename', 255);
            $table->char('file_hash', 64)->unique();
            $table->string('import_type', 20)->default('forecast');

            $table->string('file_type', 20)->nullable();
            $table->string('status', 30)->default('pending');
            $table->index('import_type');

            $table->unsignedInteger('total_worksheets')->default(0);
            $table->unsignedInteger('total_products')->default(0);
            $table->unsignedInteger('total_periods')->default(0);

            $table->dateTime('uploaded_at')->useCurrent();
            $table->dateTime('processed_at')->nullable();

            $table->longText('metadata')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_imports');
    }
};
