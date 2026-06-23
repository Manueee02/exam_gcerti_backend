<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_center', function (Blueprint $table) {
            $table->increments('id');
            $table->text('name');
            $table->text('description');
            $table->text('address');
            $table->text('city');
            $table->text('province');
            $table->text('postal_code');
            $table->text('contact_info');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->uuid('public_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_center');
    }
};
