<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->increments('id');
            $table->text('type');
            $table->text('name');
            $table->text('description');
            $table->text('cost');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->string('active')->default('true');
            $table->text('color');
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));
            $table->integer('duration_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
