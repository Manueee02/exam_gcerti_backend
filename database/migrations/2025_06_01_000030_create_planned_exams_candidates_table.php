<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_exams_candidates', function (Blueprint $table) {
            // NOTA: la PRIMARY KEY non risultava esplicita nel dump originale
            // per questa tabella. Aggiunta per coerenza con Eloquent/le altre
            // tabelle - VERIFICARE che corrisponda al vincolo reale sul DB.
            $table->increments('id');
            $table->unsignedBigInteger('id_candidate');
            $table->unsignedBigInteger('id_planned_exam');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));

            $table->foreign('id_candidate', 'FK__candidates')
                ->references('id')->on('candidates')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_planned_exam', 'FK__planned_exams')
                ->references('id')->on('planned_exams')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_exams_candidates');
    }
};
