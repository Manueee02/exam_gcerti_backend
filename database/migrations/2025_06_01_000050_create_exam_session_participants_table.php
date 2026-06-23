<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_session_participants', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_exam_session');
            $table->unsignedBigInteger('id_candidate')->nullable();
            $table->unsignedBigInteger('id_examiner')->nullable();
            $table->string('role', 20);
            $table->string('status', 20)->default('joined');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            // NOTA: nessun vincolo FK presente nel DB attuale (scaffold Fase 5).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_session_participants');
    }
};
