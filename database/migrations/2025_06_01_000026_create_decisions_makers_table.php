<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions_makers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_user')->nullable();
            $table->text('name');
            $table->text('surname');
            $table->text('email');
            $table->text('phone');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->string('active')->default('1');
            $table->uuid('public_id');

            $table->foreign('id_user', 'FK__users')
                ->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions_makers');
    }
};
