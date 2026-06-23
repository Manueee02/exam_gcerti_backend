<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_created_examiner_decisionmaker', function (Blueprint $table) {
            $table->increments('id');
            $table->text('auditor_public_id');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->integer('id_user');

            $table->foreign('id_user', 'FK_user_created_examiner_decisionmaker_users')
                ->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_created_examiner_decisionmaker');
    }
};
