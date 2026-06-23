<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_exams_inscription', function (Blueprint $table) {
            // NOTA: la PRIMARY KEY non risultava esplicita nel dump originale
            // per questa tabella. Aggiunta per coerenza - VERIFICARE sul DB reale.
            $table->increments('id');
            $table->unsignedBigInteger('id_planned_exam');
            $table->text('status');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->unsignedBigInteger('document')->nullable();
            $table->unsignedBigInteger('invoice')->nullable();
            $table->integer('id_candidate');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('unsigned_document')->nullable();
            $table->unsignedBigInteger('unsigned_invoice')->nullable();
            $table->uuid('public_id')->nullable()->default(DB::raw('gen_random_uuid()'));

            $table->foreign('id_planned_exam', 'FK__planned_exams')
                ->references('id')->on('planned_exams')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_candidate', 'FK_planned_exams_inscription_candidates')
                ->references('id')->on('candidates')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('unsigned_document', 'FK_planned_exams_inscription_media_3')
                ->references('id')->on('media')
                ->onUpdate('set null')->onDelete('set null');
            $table->foreign('unsigned_invoice', 'FK_planned_exams_inscription_media_4')
                ->references('id')->on('media')
                ->onUpdate('set null')->onDelete('set null');
            $table->foreign('document', 'FK_planned_exams_iscription_media')
                ->references('id')->on('media')
                ->onUpdate('set null')->onDelete('set null');
            $table->foreign('invoice', 'FK_planned_exams_iscription_media_2')
                ->references('id')->on('media')
                ->onUpdate('set null')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_exams_inscription');
    }
};
