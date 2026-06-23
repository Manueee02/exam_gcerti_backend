<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_exam')->nullable();
            $table->unsignedBigInteger('id_candidate');
            $table->text('order_number');
            $table->text('id_transaction');
            $table->text('amount');
            $table->text('currency');
            $table->text('payment_state');
            $table->text('payment_method');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('public_id');

            $table->foreign('id_candidate', 'FK_candidates_payments_candidates')
                ->references('id')->on('candidates')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_exam', 'FK_candidates_payments_exams')
                ->references('id')->on('exams')
                ->onUpdate('set null')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates_payments');
    }
};
