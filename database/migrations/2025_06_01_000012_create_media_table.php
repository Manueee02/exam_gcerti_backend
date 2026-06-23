<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_name', 500);
            $table->string('url', 500);
            $table->string('path', 500);
            $table->string('mime_type', 500);
            $table->string('md5_hash', 100);
            $table->string('size', 500);
            $table->string('disk', 500);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('pending_delete_at')->nullable();
            $table->boolean('is_temporary')->nullable()->default(false);
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
