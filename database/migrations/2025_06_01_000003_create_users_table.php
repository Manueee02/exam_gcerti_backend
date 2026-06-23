<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('refresh_token', 100)->nullable();
            $table->unsignedBigInteger('id_role')->default(3);
            $table->string('first_access', 50)->default('true');
            $table->string('password_reset_tokens', 250)->nullable();
            $table->string('candidate_registration_completed', 50)->default('false');
            $table->text('active_token')->nullable();
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));

            $table->unique('email', 'users_email_unique');
            $table->index('id_role', 'FK_users_user_roles');
            $table->foreign('id_role', 'users_id_role_foreign')
                ->references('id')->on('user_roles')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
