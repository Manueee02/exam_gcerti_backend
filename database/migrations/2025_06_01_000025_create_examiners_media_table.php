<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiners_media', function (Blueprint $table) {
            $table->increments('id');
            $table->text('type');
            $table->unsignedBigInteger('id_examiner');
            $table->unsignedBigInteger('id_media');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('public_id');

            $table->foreign('id_examiner', 'FK__examiners')
                ->references('id')->on('examiners')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_media', 'FK__media')
                ->references('id')->on('media')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiners_media');
    }
};
