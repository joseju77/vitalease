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
        Schema::create('neighborhoods', function (Blueprint $table) {
            $table->integerIncrements('id');
            $table->string('name');
            $table->string('zip_code', 5)->index();

            $table->foreign('zip_code')->references('code')->on('zip_codes');

            $table->unique(['name', 'zip_code']);
        });

        Citext255::applyTo('neighborhoods', 'name');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('neighborhoods');
    }
};
