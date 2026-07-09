<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exam_session_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('id_candidate')
                ->nullable()
                ->after('actor_id');

            $table->foreign('id_candidate')
                ->references('id')
                ->on('candidates')
                ->nullOnDelete();

            $table->index(['id_exam_session', 'id_candidate']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_session_logs', function (Blueprint $table) {
            $table->dropIndex(['id_exam_session', 'id_candidate']);
            $table->dropForeign(['id_candidate']);
            $table->dropColumn('id_candidate');
        });
    }
};
