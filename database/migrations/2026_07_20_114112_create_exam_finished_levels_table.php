<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_finished_levels', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('id_exam_finished');
            $table->unsignedBigInteger('id_exam_finished_area');

            $table->unsignedBigInteger('id_exam_level')->nullable();
            $table->text('level_name_snapshot')->nullable();
            $table->integer('level_order_snapshot')->nullable();

            // Snapshot della regola di estrazione applicata a questo tentativo
            $table->integer('rule_n_questions_snapshot')->nullable();
            $table->integer('rule_duration_minutes_snapshot')->nullable();
            $table->integer('rule_passing_score_snapshot')->nullable();

            $table->integer('correct')->nullable();
            $table->integer('total')->nullable();

            // null = interrotto a metà, non valutabile
            $table->boolean('passed')->nullable();

            // completed | timeout | submitted_by_candidate | terminated_mid_level
            $table->string('reason', 40);

            // true se il livello era ancora in corso quando il run è stato
            // chiuso (terminazione manuale, sweep heartbeat, endSession forzato)
            $table->boolean('is_final_incomplete_level')->default(false);

            $table->integer('duration_used_seconds')->nullable();

            $table->foreign('id_exam_finished', 'fk_efl_exam_finished')
                ->references('id')->on('exam_finished')
                ->onDelete('cascade');

            $table->foreign('id_exam_finished_area', 'fk_efl_exam_finished_area')
                ->references('id')->on('exam_finished_areas')
                ->onDelete('cascade');

            $table->foreign('id_exam_level', 'fk_efl_level')
                ->references('id')->on('exam_levels')
                ->onDelete('set null');

            $table->index('id_exam_finished', 'idx_efl_exam_finished');
            $table->index('id_exam_finished_area', 'idx_efl_exam_finished_area');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_finished_levels');
    }
};
