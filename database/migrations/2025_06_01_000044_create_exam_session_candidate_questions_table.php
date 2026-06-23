<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_session_candidate_questions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_candidate_run');
            $table->unsignedBigInteger('id_question');
            $table->unsignedBigInteger('id_exam_session_step')->nullable();
            $table->integer('position');
            $table->timestamp('created_at')->default(DB::raw('now()'));
            $table->timestamp('updated_at');

            $table->foreign('id_candidate_run', 'fk_cq_run')
                ->references('id')->on('exam_session_candidate_runs')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_session_candidate_questions');
    }
};
