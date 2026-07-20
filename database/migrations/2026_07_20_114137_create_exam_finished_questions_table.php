<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_finished_questions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('id_exam_finished_level');

            $table->unsignedBigInteger('id_question')->nullable();
            $table->text('question_text_snapshot')->nullable();
            $table->integer('position')->nullable();

            $table->boolean('was_answered')->default(false);

            $table->foreign('id_exam_finished_level', 'fk_efq_exam_finished_level')
                ->references('id')->on('exam_finished_levels')
                ->onDelete('cascade');

            $table->foreign('id_question', 'fk_efq_question')
                ->references('id')->on('questions')
                ->onDelete('set null');

            $table->index('id_exam_finished_level', 'idx_efq_exam_finished_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_finished_questions');
    }
};
