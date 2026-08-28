<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_patterns', function (Blueprint $table) {
            // The months of the year the pattern works. Existing patterns work
            // all of them, which is what they meant before this column existed.
            $table->json('months')->default('[1,2,3,4,5,6,7,8,9,10,11,12]');
        });
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
