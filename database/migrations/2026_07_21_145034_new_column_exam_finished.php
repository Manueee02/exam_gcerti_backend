<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_finished', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('pending')->after('report_snapshot');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_note')->nullable()->after('approved_at');

            // approved_by punta sempre e solo al decisionmaker che ha deciso
            // (auditors_cache.id) — non esiste override admin, quindi non
            // serve distinguere "chi ha cliccato" da "chi è il deliberante".
            $table->foreign('approved_by', 'fk_exam_finished_approved_by')
                ->references('id')->on('auditors_cache')
                ->onDelete('set null');

            $table->index('approval_status', 'idx_exam_finished_approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('exam_finished', function (Blueprint $table) {
            $table->dropForeign('fk_exam_finished_approved_by');
            $table->dropIndex('idx_exam_finished_approval_status');
            $table->dropColumn(['approval_status', 'approved_by', 'approved_at', 'approval_note']);
        });
    }
};
