<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_written', function (Blueprint $table) {
            // NOTA: la PRIMARY KEY non risultava esplicita nel dump originale
            // per questa tabella. Aggiunta per coerenza - VERIFICARE sul DB reale.
            $table->increments('id');
            $table->unsignedBigInteger('id_candidates_exam');
            $table->json('question_answers');
            $table->json('exam_log');
            $table->text('results');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('public_id');

            $table->foreign('id_candidates_exam', 'FK__candidates_exams')
                ->references('id')->on('candidates_exams')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_written');
    }
};
