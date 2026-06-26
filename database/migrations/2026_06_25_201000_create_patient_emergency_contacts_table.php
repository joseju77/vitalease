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
        Schema::create('patient_emergency_contacts', function (Blueprint $table) {
            $table->integerIncrements('id');
            $table->integer('patient_id')->index();
            $table->string('name');
            $table->string('phone_number', 16);
            $table->smallInteger('kinship_type');

            $table->foreign('patient_id')->references('id')->on('patients');

            $table->unique(['patient_id', 'phone_number']);
        });

        DB::statement("ALTER TABLE patient_emergency_contacts ADD CONSTRAINT chk_phone_number_format CHECK (phone_number ~ '^\\+[1-9]\\d{1,14}$')");
        DB::statement('ALTER TABLE patient_emergency_contacts ADD CONSTRAINT chk_kinship_type_domain CHECK (kinship_type IN (1, 2, 3, 4, 5, 6))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_emergency_contacts');
    }
};
