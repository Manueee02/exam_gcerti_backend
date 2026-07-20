<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_finished_areas', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('id_exam_finished');

            // Nullable: l'area potrebbe essere cancellata in futuro,
            // lo snapshot testuale resta comunque leggibile.
            $table->unsignedBigInteger('id_exam_area')->nullable();
            $table->text('area_name_snapshot')->nullable();
            $table->text('area_label_snapshot')->nullable();
            $table->integer('area_order_snapshot')->nullable();

            // passed | failed_or_skipped | not_reached
            $table->string('area_status', 30);

            $table->unsignedBigInteger('id_exam_level_certified')->nullable();
            $table->text('level_certified_name_snapshot')->nullable();
            $table->text('level_certified_label_snapshot')->nullable();
            $table->integer('level_certified_order_snapshot')->nullable();

            $table->integer('correct')->nullable();
            $table->integer('total')->nullable();

            $table->foreign('id_exam_finished', 'fk_efa_exam_finished')
                ->references('id')->on('exam_finished')
                ->onDelete('cascade');

            $table->foreign('id_exam_area', 'fk_efa_area')
                ->references('id')->on('exam_areas')
                ->onDelete('set null');

            $table->foreign('id_exam_level_certified', 'fk_efa_level_certified')
                ->references('id')->on('exam_levels')
                ->onDelete('set null');

            $table->index('id_exam_finished', 'idx_efa_exam_finished');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_finished_areas');
    }
};
