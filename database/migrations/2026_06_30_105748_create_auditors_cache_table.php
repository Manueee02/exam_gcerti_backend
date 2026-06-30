<?php

// database/migrations/2026_06_30_000000_create_auditors_cache_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditors_cache', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary(); // stesso id di App1, niente autoincrement
            $table->uuid('public_id')->unique();
            $table->string('name', 200);
            $table->string('surname', 200);
            $table->string('phone', 200)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('fiscal_code', 200)->nullable();
            $table->string('type', 50)->default('auditor');
            $table->string('is_examiner', 50)->default('false');
            $table->string('is_auditor', 50)->default('true');
            $table->boolean('is_decision_maker')->default(false);   // calcolato lato App1
            $table->boolean('has_qualified_status')->default(false); // calcolato lato App1
            $table->string('access', 50)->default('false');
            $table->string('employee', 50)->default('false');
            $table->date('start_workship')->nullable();
            $table->date('end_workship')->nullable();
            $table->timestamp('app1_updated_at')->nullable(); // updated_at originale di App1
            $table->timestamp('synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_examiner', 'has_qualified_status']);
            $table->index(['is_decision_maker', 'has_qualified_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditors_cache');
    }
};
