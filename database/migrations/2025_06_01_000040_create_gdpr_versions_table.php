<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('GDPR_versions', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('id_gdpr');
            $table->text('text');
            $table->integer('version')->default(1);
            $table->boolean('active')->default(false);
            $table->timestamp('created_at')->default(DB::raw('now()'));
            $table->timestamp('updated_at')->default(DB::raw('now()'));

            $table->unique('public_id', 'gdpr_versions_public_id_key');
            $table->unique(['id_gdpr', 'version'], 'gdpr_versions_id_gdpr_version_key');
            $table->foreign('id_gdpr', 'gdpr_versions_id_gdpr_fkey')
                ->references('id')->on('GDPR')
                ->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('GDPR_versions');
    }
};
