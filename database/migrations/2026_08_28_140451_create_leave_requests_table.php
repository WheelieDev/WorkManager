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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->index('starts_on');
        });

        Schema::table('leave_days', function (Blueprint $table) {
            $table->foreignId('leave_request_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // Days booked before requests existed each become a request of one day.
        foreach (DB::table('leave_days')->orderBy('date')->get() as $day) {
            $id = DB::table('leave_requests')->insertGetId([
                'starts_on' => $day->date,
                'ends_on' => $day->date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('leave_days')->where('id', $day->id)->update(['leave_request_id' => $id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_days', function (Blueprint $table) {
            $table->dropForeign(['leave_request_id']);
            $table->dropColumn('leave_request_id');
        });

        Schema::dropIfExists('leave_requests');
    }
};
