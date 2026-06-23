<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_canidates_exam');
            $table->unsignedBigInteger('id_examiner');
            $table->json('messages');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('public_id');

            $table->foreign('id_canidates_exam', 'FK__candidates_exams')
                ->references('id')->on('candidates_exams')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_examiner', 'FK__examiners')
                ->references('id')->on('examiners')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat');
    }
};
