<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Treatment lines now live in `medical_consultation_treatments`; the
     * free-text jsonb column and its shape-only CHECK constraint are no
     * longer needed.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE medical_consultations DROP CONSTRAINT chk_treatment_is_array');

        Schema::table('medical_consultations', function (Blueprint $table) {
            $table->dropColumn('treatment');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Recreates the column's shape only — the jsonb line data that existed
     * before the column was dropped is not restored.
     */
    public function down(): void
    {
        Schema::table('medical_consultations', function (Blueprint $table) {
            $table->jsonb('treatment')->default(DB::raw("'[]'::jsonb"));
        });

        DB::statement("ALTER TABLE medical_consultations ADD CONSTRAINT chk_treatment_is_array CHECK (jsonb_typeof(treatment) = 'array')");

        DB::statement('ALTER TABLE medical_consultations ALTER COLUMN treatment DROP DEFAULT');
    }
};
