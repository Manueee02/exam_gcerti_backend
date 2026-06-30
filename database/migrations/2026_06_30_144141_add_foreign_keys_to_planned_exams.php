<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planned_exams', function (Blueprint $table) {
            // RESTRICT (default): impedisce che auditors_cache venga cancellata
            // fisicamente se referenziata da un esame pianificato. Coerente con
            // la scelta di non cancellare mai fisicamente la cache (solo deactivate).
            $table->foreign('id_examiner')
                ->references('id')->on('auditors_cache');

            $table->foreign('id_decision_maker')
                ->references('id')->on('auditors_cache');
        });
    }

    public function down(): void
    {
        Schema::table('planned_exams', function (Blueprint $table) {
            $table->dropForeign(['id_examiner']);
            $table->dropForeign(['id_decision_maker']);
        });
    }
};
