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
            // A text column is measured in bytes but the note is limited in
            // characters, so anything multibyte could pass validation and then
            // be cut short by the database. Widening it puts the character
            // limit comfortably inside whatever the column can hold, with room
            // for notes to be written in Markdown later.
            $table->mediumText('note')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->text('note')->nullable()->change();
        });
    }
};
