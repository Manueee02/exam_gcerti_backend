<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_session_candidate_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('current_exam_area_id')->nullable()->after('current_step');
            $table->unsignedBigInteger('current_exam_level_id')->nullable()->after('current_exam_area_id');
            $table->timestamp('current_step_started_at')->nullable()->after('current_exam_level_id');
        });
    }

    public function down(): void
    {
        Schema::table('exam_session_candidate_runs', function (Blueprint $table) {
            $table->dropColumn(['current_exam_area_id', 'current_exam_level_id', 'current_step_started_at']);
        });
    }
};
