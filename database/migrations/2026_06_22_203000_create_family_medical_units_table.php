<?php

use App\Support\Database\Citext255;
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
        Schema::create('family_medical_units', function (Blueprint $table) {
            $table->integerIncrements('id');
            $table->string('name')->unique();
            $table->string('address');
        });

        Citext255::applyTo('family_medical_units', 'name');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_medical_units');
    }
};
