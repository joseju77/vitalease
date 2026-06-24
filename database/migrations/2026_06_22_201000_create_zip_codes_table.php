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
        Schema::create('zip_codes', function (Blueprint $table) {
            $table->string('code', 5)->primary();
            $table->smallInteger('municipality_id');

            $table->foreign('municipality_id')->references('id')->on('municipalities');
        });

        DB::statement("ALTER TABLE zip_codes ADD CONSTRAINT chk_zip_code_format CHECK (code ~ '^[0-9]{5}$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zip_codes');
    }
};
