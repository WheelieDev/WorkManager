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
        Schema::create('leave_carryovers', function (Blueprint $table) {
            $table->id();
            // The calendar year the leave year starts in.
            $table->unsignedSmallInteger('leave_year')->unique();
            $table->decimal('hours', 6, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_carryovers');
    }
};
