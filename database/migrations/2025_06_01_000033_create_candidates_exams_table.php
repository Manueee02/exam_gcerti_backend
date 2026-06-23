<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates_exams', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_planned_exam');
            $table->unsignedBigInteger('id_candidate');
            $table->unsignedBigInteger('id_candidate_payment');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('public_id');

            $table->foreign('id_candidate', 'FK__candidates')
                ->references('id')->on('candidates')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_candidate_payment', 'FK__candidates_payments')
                ->references('id')->on('candidates_payments')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_planned_exam', 'FK__planned_exams')
                ->references('id')->on('planned_exams')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates_exams');
    }
};
