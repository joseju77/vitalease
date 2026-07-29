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
        Schema::create('vital_signs', function (Blueprint $table) {
            $table->integer('medical_consultation_id')->primary();
            $table->decimal('weight', 5, 2);
            $table->decimal('height', 3, 2);
            $table->smallInteger('blood_pressure_systolic');
            $table->smallInteger('blood_pressure_diastolic');
            $table->smallInteger('heart_rate');
            $table->smallInteger('respiratory_rate');
            $table->decimal('temperature', 3, 1);
            $table->smallInteger('oxygen_saturation');
            $table->smallInteger('glasgow');
            $table->smallInteger('glucose')->nullable();

            $table->foreign('medical_consultation_id')->references('id')->on('medical_consultations')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE vital_signs ADD CONSTRAINT chk_glasgow_domain CHECK (glasgow BETWEEN 3 AND 15)');
        DB::statement('ALTER TABLE vital_signs ADD CONSTRAINT chk_oxygen_saturation_domain CHECK (oxygen_saturation BETWEEN 0 AND 100)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vital_signs');
    }
};
