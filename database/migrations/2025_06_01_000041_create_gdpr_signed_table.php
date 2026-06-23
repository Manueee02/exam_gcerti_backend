<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('GDPR_signed', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_gdpr_version');
            $table->unsignedBigInteger('id_candidate')->nullable();
            $table->date('date');
            $table->text('accepted')->default('false');
            $table->text('preference')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->unsignedBigInteger('id_user');
            $table->timestamp('accepted_at')->nullable();

            $table->foreign('id_candidate', 'FK_GDPR_signed_candidates')
                ->references('id')->on('candidates')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_gdpr_version', 'FK_GDPR_signed_gdpr_version')
                ->references('id')->on('GDPR_versions')
                ->onUpdate('no action')->onDelete('restrict');
            $table->foreign('id_user', 'FK_GDPR_signed_users')
                ->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('GDPR_signed');
    }
};
