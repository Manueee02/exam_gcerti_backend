<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_session_proctor_actions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_exam_session');
            $table->unsignedBigInteger('id_candidate')->nullable();
            $table->string('action', 50);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));

            $table->foreign('id_exam_session', 'fk_proctor_session')
                ->references('id')->on('exam_sessions')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_session_proctor_actions');
    }
};
