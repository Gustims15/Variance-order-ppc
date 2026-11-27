<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variance_reports', function (Blueprint $table) {
            $table->unsignedInteger('retained_count')
                ->default(0)
                ->after('unchanged_count');
        });
    }

    public function down(): void
    {
        Schema::table('variance_reports', function (Blueprint $table) {
            $table->dropColumn('retained_count');
        });
    }
};