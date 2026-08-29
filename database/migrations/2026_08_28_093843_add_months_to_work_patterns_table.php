<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_patterns', function (Blueprint $table) {
            // MySQL forbids a default on a JSON column, so the column is added
            // nullable and the existing rows are backfilled below instead.
            $table->json('months')->nullable();
        });

        DB::table('work_patterns')
            ->whereNull('months')
            ->update(['months' => json_encode(range(1, 12))]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_patterns', function (Blueprint $table) {
            $table->dropColumn('months');
        });
    }
};
