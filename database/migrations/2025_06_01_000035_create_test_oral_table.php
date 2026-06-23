<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_oral', function (Blueprint $table) {
            $table->increments('id');
            $table->text('valutation');
            $table->json('exam_log');
            $table->unsignedBigInteger('id_candidates_exam');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->text('time_finished');
            $table->text('closed_session')->default('false');
            $table->uuid('public_id');

            $table->foreign('id_candidates_exam', 'FK__candidates_exams')
                ->references('id')->on('candidates_exams')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_oral');
    }
};
