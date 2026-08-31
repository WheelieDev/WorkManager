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
        Schema::table('work_sessions', function (Blueprint $table) {
            // Both are nullable: everything recorded before now has neither, and
            // starting the timer should never wait on deciding what to call the
            // work. Deleting an area keeps its hours and forgets only the label.
            $table->foreignId('work_area_id')->nullable()->after('kind')->constrained()->nullOnDelete();
            $table->text('note')->nullable()->after('work_area_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->dropForeign(['work_area_id']);
            $table->dropColumn(['work_area_id', 'note']);
        });
    }
};
