<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_exams', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_exam');
            $table->unsignedBigInteger('id_test_center')->default(1);
            $table->unsignedBigInteger('id_examiner');
            $table->unsignedBigInteger('id_decision_maker');
            $table->date('date');
            $table->time('time');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->text('location')->default('Piattaforma Gcerti');
            $table->time('end_time');
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));

            // NOTA: nel DB attuale id_examiner e id_decision_maker NON hanno
            // un vincolo FK applicato, a differenza di id_exam/id_test_center.
            // Replicato fedelmente cosi' com'e' in produzione.
            $table->foreign('id_exam', 'FK_planned_exams_exams')
                ->references('id')->on('exams')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_test_center', 'FK_planned_exams_test_center')
                ->references('id')->on('test_center')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_exams');
    }
};
