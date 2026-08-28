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
        Schema::create('leave_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('type')->default('annual');
            // Hours taken, so half a day is as easy to record as a whole one.
            $table->decimal('hours', 5, 2);
            $table->timestamps();

            $table->unique(['date', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_days');
    }
};
