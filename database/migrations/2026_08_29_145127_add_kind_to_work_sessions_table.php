<?php

use App\Enums\WorkKind;
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
            // Everything recorded before now was contracted hours, which is
            // also what tracking does unless you say otherwise.
            $table->string('kind')->default(WorkKind::Contracted->value)->after('ended_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
