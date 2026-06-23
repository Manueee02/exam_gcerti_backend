<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates_media', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_candidate');
            $table->unsignedBigInteger('id_media');
            $table->string('type');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));

            $table->foreign('id_candidate', 'FK_candidates_media_candidates')
                ->references('id')->on('candidates')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_media', 'FK_candidates_media_media')
                ->references('id')->on('media')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates_media');
    }
};
