<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answers', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('id_question');
            $table->text('text');
            $table->string('is_correct')->default('false');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique('public_id', 'uq_answers_public_key');
            $table->foreign('id_question', 'FK_answers_questions')
                ->references('id')->on('questions')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
};
