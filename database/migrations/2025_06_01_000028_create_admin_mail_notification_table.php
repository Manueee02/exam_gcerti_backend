<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_mail_notification', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_user');
            $table->text('type');
            $table->text('active');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));

            $table->unique('public_id', 'auditors_public_id_unique');
            $table->foreign('id_user', 'FK__users')
                ->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_mail_notification');
    }
};
