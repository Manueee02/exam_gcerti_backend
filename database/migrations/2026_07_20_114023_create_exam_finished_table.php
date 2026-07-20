<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_finished', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));

            $table->unsignedBigInteger('id_exam_session');
            $table->unsignedBigInteger('id_exam');
            $table->unsignedBigInteger('id_candidate');

            // Snapshot dei dati dell'esame al momento della generazione,
            // per non dipendere da `exams` se cambia in futuro.
            $table->text('exam_name_snapshot')->nullable();
            $table->integer('exam_duration_minutes_snapshot')->nullable();

            // Stato della ExamSession (contesto: era ancora live o già chiusa)
            $table->string('session_status', 20);

            // Esito vero e proprio del candidato: completed | timeout | terminated
            $table->string('run_status', 20);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('total_duration_seconds')->nullable();

            // Report leggibile, stesso payload di REPORT_GENERATED
            $table->jsonb('report_snapshot')->nullable();

            $table->timestamp('generated_at')->useCurrent();

            $table->foreign('id_exam_session', 'fk_exam_finished_session')
                ->references('id')->on('exam_sessions')
                ->onDelete('cascade');

            $table->foreign('id_exam', 'fk_exam_finished_exam')
                ->references('id')->on('exams')
                ->onDelete('cascade');

            $table->foreign('id_candidate', 'fk_exam_finished_candidate')
                ->references('id')->on('candidates')
                ->onDelete('cascade');

            // Un solo snapshot per candidato/sessione: protegge l'idempotenza
            // anche a livello DB, non solo applicativo.
            $table->unique(['id_exam_session', 'id_candidate'], 'uq_exam_finished_session_candidate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_finished');
    }
};
