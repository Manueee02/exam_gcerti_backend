<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_practice_media', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_media');
            $table->unsignedBigInteger('id_test_practice');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('public_id');

            $table->foreign('id_media', 'FK__media')
                ->references('id')->on('media')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_test_practice', 'FK__test_practice')
                ->references('id')->on('test_practice')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_practice_media');
    }
};
