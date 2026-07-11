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
        Schema::create('patient_ailments', function (Blueprint $table) {
            $table->integerIncrements('id');
            $table->integer('patient_id')->index();
            $table->smallInteger('ailment_type');
            $table->date('diagnosed_at');
            $table->text('treatment_notes')->nullable();

            $table->foreign('patient_id')->references('id')->on('patients');

            $table->unique(['patient_id', 'ailment_type']);
        });

        DB::statement('ALTER TABLE patient_ailments ADD CONSTRAINT chk_ailment_type_domain CHECK (ailment_type IN (1, 2, 3, 4, 5, 6))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_ailments');
    }
};
