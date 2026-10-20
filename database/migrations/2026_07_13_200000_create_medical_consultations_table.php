<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('medical_consultations', function (Blueprint $table) {
            $table->integerIncrements('id');
            $table->uuid()->unique();
            $table->string('code', 14)->unique();
            $table->string('current_condition', 1024);
            $table->string('diagnosis', 1024);
            $table->smallInteger('condition');
            $table->smallInteger('prognosis');
            $table->jsonb('treatment');
            $table->smallInteger('medical_classification');
            $table->integer('physician_id');
            $table->integer('patient_id');
            $table->timestampTz('voided_at')->nullable();
            $table->integer('voided_by_id')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('physician_id')->references('id')->on('users');
            $table->foreign('patient_id')->references('id')->on('patients');
            $table->foreign('voided_by_id')->references('id')->on('users')->restrictOnDelete();

            $table->index(['physician_id', 'created_at']);
            $table->index(['patient_id', 'created_at']);
            $table->index('voided_at');
        });

        DB::statement("ALTER TABLE medical_consultations ADD CONSTRAINT chk_code_format CHECK (code ~ '^MC-[0-9]{6}-[0-9]{4}$')");
        DB::statement('ALTER TABLE medical_consultations ADD CONSTRAINT chk_condition_domain CHECK (condition BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE medical_consultations ADD CONSTRAINT chk_prognosis_domain CHECK (prognosis BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE medical_consultations ADD CONSTRAINT chk_medical_classification_domain CHECK (medical_classification BETWEEN 1 AND 10)');
        DB::statement('ALTER TABLE medical_consultations ADD CONSTRAINT chk_void_state_all_or_none CHECK ((voided_at IS NULL AND voided_by_id IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by_id IS NOT NULL AND void_reason IS NOT NULL))');
        DB::statement("ALTER TABLE medical_consultations ADD CONSTRAINT chk_treatment_is_array CHECK (jsonb_typeof(treatment) = 'array')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_consultations');
    }
};
