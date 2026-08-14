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
        Schema::create('medical_consultation_treatments', function (Blueprint $table) {
            $table->integerIncrements('id');
            $table->integer('medical_consultation_id');
            $table->integer('medication_id');
            $table->integer('quantity_dispensed');
            $table->string('dose', 255);
            $table->string('frequency', 255);
            $table->string('duration', 255);
            $table->timestampsTz();

            $table->foreign('medical_consultation_id')->references('id')->on('medical_consultations')->cascadeOnDelete();
            $table->foreign('medication_id')->references('id')->on('medications')->restrictOnDelete();

            $table->unique(['medical_consultation_id', 'medication_id']);
            $table->index('medication_id');
        });

        DB::statement('ALTER TABLE medical_consultation_treatments ADD CONSTRAINT chk_quantity_dispensed_positive CHECK (quantity_dispensed > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_consultation_treatments');
    }
};
