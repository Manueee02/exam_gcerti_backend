<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates_company', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_candidates');
            $table->text('billing_type')->default('personal');
            $table->text('piva')->nullable();
            $table->text('company_piva')->nullable();
            $table->text('company_social_reason')->nullable();
            $table->text('company_mail')->nullable();
            $table->text('company_province')->nullable();
            $table->text('company_legal_address')->nullable();
            $table->text('company_city')->nullable();
            $table->text('company_phone')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->text('company_foreign')->nullable()->default('false');
            $table->text('company_zip')->nullable();
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));

            $table->foreign('id_candidates', 'FK_candidates_company_candidates')
                ->references('id')->on('candidates')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates_company');
    }
};
