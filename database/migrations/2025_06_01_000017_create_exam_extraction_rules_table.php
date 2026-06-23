<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_extraction_rules', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('exam_area_id');
            $table->unsignedBigInteger('exam_level_id');
            $table->integer('n_questions')->default(1);
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));
            $table->timestamp('updated_at')->nullable();
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));
            $table->integer('duration_minutes');
            $table->integer('passing_score');

            $table->unique(['exam_area_id', 'exam_level_id'], 'uq_extraction_area_level');
            $table->foreign('exam_area_id', 'fk_extraction_area')
                ->references('id')->on('exam_areas')
                ->onUpdate('no action')->onDelete('cascade');
            $table->foreign('exam_level_id', 'fk_extraction_level')
                ->references('id')->on('exam_levels')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_extraction_rules');
    }
};
