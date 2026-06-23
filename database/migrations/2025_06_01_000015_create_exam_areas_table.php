<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_areas', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('exam_id');
            $table->text('name');
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));
            $table->timestamp('updated_at')->nullable();
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));
            $table->text('label');
            $table->integer('order');

            $table->foreign('exam_id', 'fk_exam_areas_exam')
                ->references('id')->on('exams')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_areas');
    }
};
