<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_session_answers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_exam_session');
            $table->unsignedBigInteger('id_exam_session_step')->nullable();
            $table->unsignedBigInteger('id_question');
            $table->unsignedBigInteger('id_candidate');
            $table->jsonb('answer');
            $table->boolean('is_correct')->nullable();
            $table->integer('time_spent_seconds')->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));

            // Fix 4 (gia' applicato manualmente sul DB dev, qui replicato)
            $table->unique(['id_exam_session', 'id_candidate', 'id_question'], 'uq_session_candidate_question');
            $table->foreign('id_exam_session', 'fk_ans_session')
                ->references('id')->on('exam_sessions')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_session_answers');
    }
};
