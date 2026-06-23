<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Necessaria per gen_random_uuid(), usata come default
     * su quasi tutte le colonne public_id del progetto.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
    }

    public function down(): void
    {
        // Non rimuoviamo l'estensione in down: potrebbe essere
        // usata da altri oggetti del database.
    }
};
