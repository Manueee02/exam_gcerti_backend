<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('exam_id');
            $table->text('text');
            $table->string('type', 50)->default('multiple_choice');
            $table->integer('points')->default(1);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->unsignedBigInteger('exam_area_id');
            $table->unsignedBigInteger('exam_level_id');

            $table->unique('public_id', 'uq_questions_public_key');

            // NOTA: nel DB attuale queste due FK sono ON DELETE SET NULL
            // ma le colonne sono NOT NULL -> incoerenza preesistente,
            // replicata fedelmente. Una DELETE su exam_areas/exam_levels
            // con domande collegate fallirebbe a runtime.
            $table->foreign('exam_area_id', 'fk_questions_area')
                ->references('id')->on('exam_areas')
                ->onUpdate('no action')->onDelete('set null');
            $table->foreign('exam_id', 'fk_questions_exam')
                ->references('id')->on('exams')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('exam_level_id', 'fk_questions_level')
                ->references('id')->on('exam_levels')
                ->onUpdate('no action')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
