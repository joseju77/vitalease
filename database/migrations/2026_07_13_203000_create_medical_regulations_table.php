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
        Schema::create('medical_regulations', function (Blueprint $table) {
            $table->integer('medical_consultation_id')->primary();
            $table->smallInteger('transfer_type');
            $table->string('ambulance_registration')->nullable();
            $table->string('regulation_number')->nullable();
            $table->string('clinic_id')->nullable();
            $table->timestampTz('regulated_at');
            $table->string('receiver_physician')->nullable();

            $table->foreign('medical_consultation_id')->references('id')->on('medical_consultations')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE medical_regulations ADD CONSTRAINT chk_transfer_type_domain CHECK (transfer_type BETWEEN 1 AND 3)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_regulations');
    }
};
