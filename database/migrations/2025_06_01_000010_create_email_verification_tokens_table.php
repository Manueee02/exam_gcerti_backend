<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_id');
            $table->string('token', 100)->unique('email_verification_tokens_token_key');
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->default(DB::raw('now()'));
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->index(['user_id', 'token'], 'idx_user_token');
            $table->foreign('user_id', 'email_verification_tokens_user_id_fkey')
                ->references('id')->on('users')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');
    }
};
