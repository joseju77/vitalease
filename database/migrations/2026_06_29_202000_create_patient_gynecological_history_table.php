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
        Schema::create('patient_gynecological_history', function (Blueprint $table) {
            $table->integer('patient_id')->primary();
            $table->smallInteger('menarche');
            $table->boolean('has_cramps');
            $table->boolean('is_cycle_regular');
            $table->smallInteger('cycle_intensity');
            $table->smallInteger('cycle_duration');
            $table->smallInteger('cycle_flow_level');
            $table->date('last_cycle_date');
            $table->smallInteger('sexual_activity_start_age')->nullable();
            $table->smallInteger('contraceptive_method')->nullable();
            $table->date('last_pap_smear_date')->nullable();
            $table->boolean('last_pap_smear_was_positive')->nullable();
            $table->smallInteger('pregnancies');
            $table->smallInteger('vaginal_deliveries');
            $table->smallInteger('cesareans');
            $table->smallInteger('abortions');

            $table->foreign('patient_id')->references('id')->on('patients');
        });

        DB::statement('ALTER TABLE patient_gynecological_history ADD CONSTRAINT chk_menarche_positive CHECK (menarche > 0)');
        DB::statement('ALTER TABLE patient_gynecological_history ADD CONSTRAINT chk_cycle_intensity_domain CHECK (cycle_intensity BETWEEN 1 AND 10)');
        DB::statement('ALTER TABLE patient_gynecological_history ADD CONSTRAINT chk_cycle_duration_positive CHECK (cycle_duration > 0)');
        DB::statement('ALTER TABLE patient_gynecological_history ADD CONSTRAINT chk_cycle_flow_level_positive CHECK (cycle_flow_level > 0)');
        DB::statement('ALTER TABLE patient_gynecological_history ADD CONSTRAINT chk_contraceptive_method_domain CHECK (contraceptive_method IN (1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11))');
        DB::statement('ALTER TABLE patient_gynecological_history ADD CONSTRAINT chk_pregnancies_nonnegative CHECK (pregnancies >= 0)');
        DB::statement('ALTER TABLE patient_gynecological_history ADD CONSTRAINT chk_vaginal_deliveries_nonnegative CHECK (vaginal_deliveries >= 0)');
        DB::statement('ALTER TABLE patient_gynecological_history ADD CONSTRAINT chk_cesareans_nonnegative CHECK (cesareans >= 0)');
        DB::statement('ALTER TABLE patient_gynecological_history ADD CONSTRAINT chk_abortions_nonnegative CHECK (abortions >= 0)');

        DB::statement('
            ALTER TABLE patient_gynecological_history ADD CONSTRAINT chk_last_pap_smear_consistency CHECK (
                (last_pap_smear_date IS NULL AND last_pap_smear_was_positive IS NULL)
                OR (last_pap_smear_date IS NOT NULL AND last_pap_smear_was_positive IS NOT NULL)
            )
        ');

        DB::statement('
            ALTER TABLE patient_gynecological_history ADD CONSTRAINT chk_counts_consistent CHECK (
                vaginal_deliveries + cesareans + abortions <= pregnancies
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_gynecological_history');
    }
};
