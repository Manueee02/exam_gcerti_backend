<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('id_planned_exam');
            $table->unsignedBigInteger('id_exam');
            $table->string('status', 20)->default('scheduled');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('join_url')->nullable();
            $table->jsonb('session_snapshot')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->foreign('id_planned_exam', 'fk_exam_sessions_planned')
                ->references('id')->on('planned_exams')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sessions');
    }
};
