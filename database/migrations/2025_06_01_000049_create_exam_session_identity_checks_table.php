<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_session_identity_checks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_exam_session');
            $table->unsignedBigInteger('id_candidate');
            $table->string('provider', 50);
            $table->string('status', 20);
            $table->boolean('matched')->nullable()->default(false);
            $table->text('external_reference')->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));

            // NOTA: nessun vincolo FK presente nel DB attuale (scaffold Fase 4).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_session_identity_checks');
    }
};
