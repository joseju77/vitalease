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
        Schema::create('patients', function (Blueprint $table) {
            $table->integerIncrements('id');
            $table->uuid()->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('second_last_name')->nullable();
            $table->date('birth_date');
            $table->smallInteger('sex_at_birth');
            $table->smallInteger('marital_status');
            $table->smallInteger('blood_type');
            $table->integer('enrollment_id')->nullable();
            $table->string('enrollment_number')->nullable();
            $table->string('external_enrollment')->nullable();
            $table->integer('family_medical_unit_id')->nullable();
            $table->string('other_family_medical_unit')->nullable();
            $table->string('social_security_number', 11)->unique();
            $table->timestampsTz();

            $table->foreign('enrollment_id')->references('id')->on('enrollments');
            $table->foreign('family_medical_unit_id')->references('id')->on('family_medical_units');

            $table->unique(['enrollment_id', 'enrollment_number']);
        });

        DB::statement('ALTER TABLE patients ADD CONSTRAINT chk_sex_at_birth_domain CHECK (sex_at_birth IN (1, 2))');
        DB::statement('ALTER TABLE patients ADD CONSTRAINT chk_marital_status_domain CHECK (marital_status IN (1, 2, 3, 4))');
        DB::statement('ALTER TABLE patients ADD CONSTRAINT chk_blood_type_domain CHECK (blood_type IN (1, 2, 3, 4, 5, 6, 7, 8))');
        DB::statement("ALTER TABLE patients ADD CONSTRAINT chk_social_security_number_format CHECK (social_security_number ~ '^[0-9]{11}$')");

        DB::statement('
            ALTER TABLE patients ADD CONSTRAINT chk_enrollment_consistency CHECK (
                (enrollment_id IS NULL AND enrollment_number IS NULL AND external_enrollment IS NOT NULL)
                OR (enrollment_id IS NOT NULL AND enrollment_number IS NOT NULL AND external_enrollment IS NULL)
            )
        ');

        DB::statement('
            ALTER TABLE patients ADD CONSTRAINT chk_family_medical_unit_consistency CHECK (
                (family_medical_unit_id IS NULL AND other_family_medical_unit IS NOT NULL)
                OR (family_medical_unit_id IS NOT NULL AND other_family_medical_unit IS NULL)
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
