<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions_makers_media', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_decision_maker');
            $table->unsignedBigInteger('id_media');
            $table->text('type');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('public_id');

            $table->foreign('id_decision_maker', 'FK__decisions_makers')
                ->references('id')->on('decisions_makers')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_media', 'FK__media')
                ->references('id')->on('media')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions_makers_media');
    }
};
