<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_finished_question_options', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('id_exam_finished_question');

            $table->unsignedBigInteger('id_answer')->nullable();
            $table->text('answer_text_snapshot')->nullable();

            $table->boolean('is_correct_snapshot')->default(false);
            $table->boolean('was_selected_by_candidate')->default(false);
            $table->integer('display_order')->nullable();

            $table->foreign('id_exam_finished_question', 'fk_efqo_exam_finished_question')
                ->references('id')->on('exam_finished_questions')
                ->onDelete('cascade');

            $table->foreign('id_answer', 'fk_efqo_answer')
                ->references('id')->on('answers')
                ->onDelete('set null');

            $table->index('id_exam_finished_question', 'idx_efqo_exam_finished_question');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_finished_question_options');
    }
};
