<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_session_candidate_runs', function (Blueprint $table) {
            $table->timestamp('level_started_by_candidate_at')->nullable()->after('current_step_started_at');
        });

        // Backfill per run già in corso al momento del deploy: se un run
        // 'in_progress' ha già almeno una risposta salvata nel suo gruppo
        // corrente, il livello è di fatto già stato iniziato dal candidato
        // — senza questo backfill risulterebbe "mai iniziato" e il countdown
        // in vista "level" sparirebbe fino alla prossima transizione.
        DB::table('exam_session_candidate_runs as r')
            ->join('exam_sessions as s', 's.id', '=', 'r.id_exam_session')
            ->where('r.status', 'in_progress')
            ->whereNotNull('r.current_exam_area_id')
            ->whereNotNull('r.current_exam_level_id')
            ->whereNotNull('r.current_step_started_at')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('exam_session_answers as a')
                    ->join('questions as q', 'q.id', '=', 'a.id_question')
                    ->whereColumn('a.id_exam_session', 'r.id_exam_session')
                    ->whereColumn('a.id_candidate', 'r.id_candidate')
                    ->whereColumn('q.exam_area_id', 'r.current_exam_area_id')
                    ->whereColumn('q.exam_level_id', 'r.current_exam_level_id');
            })
            ->update(['r.level_started_by_candidate_at' => DB::raw('r.current_step_started_at')]);
    }

    public function down(): void
    {
        Schema::table('exam_session_candidate_runs', function (Blueprint $table) {
            $table->dropColumn('level_started_by_candidate_at');
        });
    }
};
