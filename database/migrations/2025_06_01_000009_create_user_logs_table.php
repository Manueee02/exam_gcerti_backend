<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('event_type');
            $table->jsonb('event_data')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->default(DB::raw('now()'));
            $table->text('status')->nullable();

            // NOTA: nessun vincolo FK presente nel DB attuale verso "users",
            // replicato fedelmente cosi' com'e' in produzione.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_logs');
    }
};
