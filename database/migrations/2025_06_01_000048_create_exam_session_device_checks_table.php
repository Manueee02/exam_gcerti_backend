<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_session_device_checks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_exam_session');
            $table->unsignedBigInteger('id_candidate');
            $table->boolean('webcam');
            $table->boolean('microphone');
            $table->boolean('audio');
            $table->boolean('connection_stable');
            $table->string('status', 20);
            $table->timestamp('created_at')->default(DB::raw('now()'));
            $table->timestamp('updated_at');

            // NOTA: nessun vincolo FK presente nel DB attuale (tabella di
            // scaffold Fase 4, non ancora collegata da nessun service).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_session_device_checks');
    }
};
