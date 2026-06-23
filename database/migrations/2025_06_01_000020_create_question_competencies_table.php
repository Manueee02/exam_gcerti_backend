<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_competencies', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_question');
            $table->string('competency_code', 100);
            $table->string('area', 100)->nullable();
            $table->string('level', 50)->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));

            $table->foreign('id_question', 'fk_qc_question')
                ->references('id')->on('questions')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_competencies');
    }
};
