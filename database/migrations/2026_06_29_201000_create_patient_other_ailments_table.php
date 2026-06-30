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
        Schema::create('patient_other_ailments', function (Blueprint $table) {
            $table->integer('patient_id')->primary();
            $table->text('surgeries')->nullable();
            $table->text('allergies')->nullable();
            $table->text('others')->nullable();

            $table->foreign('patient_id')->references('id')->on('patients');
        });

        DB::statement('
            ALTER TABLE patient_other_ailments ADD CONSTRAINT chk_other_ailments_nonempty CHECK (
                surgeries IS NOT NULL OR allergies IS NOT NULL OR others IS NOT NULL
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_other_ailments');
    }
};
