<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('physical_examinations', function (Blueprint $table) {
            $table->integer('medical_consultation_id')->primary();
            $table->string('neurological', 512);
            $table->string('head_neck', 512);
            $table->string('thorax_cardiopulmonary', 512);
            $table->string('abdomen', 512);
            $table->string('extremities', 512);
            $table->string('cabinet_laboratory', 512);

            $table->foreign('medical_consultation_id')->references('id')->on('medical_consultations')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('physical_examinations');
    }
};
