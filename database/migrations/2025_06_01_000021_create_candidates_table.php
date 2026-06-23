<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('id_user');
            $table->text('name');
            $table->text('surname');
            $table->text('email');
            $table->text('phone');
            $table->text('fiscal_code');
            $table->text('sex');
            $table->date('birthdate');
            $table->text('birthplace');
            $table->text('birthprovince')->nullable();
            $table->text('birthcommun')->nullable();
            $table->text('is_foreign')->nullable();
            $table->text('birthcountry')->nullable();
            $table->timestamp('created_at')->default(DB::raw('now()'));
            $table->timestamp('updated_at')->default(DB::raw('now()'));
            $table->text('active');
            $table->text('residence_address');
            $table->text('residence_city');
            $table->text('residence_province')->nullable();
            $table->text('residence_zip')->nullable();
            $table->text('residence_country');
            $table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));

            $table->foreign('id_user', 'fk_candidates_users')
                ->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
