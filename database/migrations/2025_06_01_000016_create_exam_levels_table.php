<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_levels', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('exam_area_id');
            $table->string('name', 100);
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));
            $table->timestamp('updated_at')->nullable();
            $table->text('label');
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));
            $table->integer('order');

            $table->foreign('exam_area_id', 'fk_exam_levels_area')
                ->references('id')->on('exam_areas')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_levels');
    }
};
