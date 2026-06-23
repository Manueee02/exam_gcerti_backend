<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('GDPR', function (Blueprint $table) {
            $table->increments('id');
            $table->text('title');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->text('type')->nullable();
            $table->unsignedBigInteger('id_exam')->nullable();
            $table->uuid('public_id')->nullable()->default(DB::raw('gen_random_uuid()'));

            $table->unique('public_id', 'gdpr_public_id_unique');
            $table->foreign('id_exam', 'FK_GDPR_exams')
                ->references('id')->on('exams')
                ->onUpdate('cascade')->onDelete('cascade');
        });

        DB::statement(
            'ALTER TABLE "GDPR" ADD CONSTRAINT gdpr_type_check ' .
            "CHECK (type IN ('inscription', 'exam', 'cookie'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('GDPR');
    }
};
