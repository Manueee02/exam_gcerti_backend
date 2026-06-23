<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_session_candidate_runs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_exam_session');
            $table->unsignedBigInteger('id_candidate');
            $table->string('status', 20)->nullable()->default('pending');
            $table->integer('current_step')->nullable()->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('created_at')->default(DB::raw('now()'));
            $table->timestamp('updated_at');
            // Fix 3 (gia' applicato manualmente sul DB dev, qui replicato)
            $table->decimal('score', 5, 2)->nullable();
            $table->boolean('passed')->nullable();

            $table->foreign('id_exam_session', 'fk_run_session')
                ->references('id')->on('exam_sessions')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_session_candidate_runs');
    }
};
