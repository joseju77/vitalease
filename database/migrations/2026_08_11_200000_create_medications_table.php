<?php

use App\Support\Database\Citext255;
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
        Schema::create('medications', function (Blueprint $table) {
            $table->integerIncrements('id');
            $table->uuid()->unique();
            $table->string('name');
            $table->string('presentation', 64);
            $table->string('concentration', 64);
            $table->string('dispensing_unit', 32);
            $table->integer('current_stock')->default(0);
            $table->integer('minimum_stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['name', 'presentation', 'concentration']);
        });

        Citext255::applyTo('medications', 'name');

        DB::statement('ALTER TABLE medications ADD CONSTRAINT chk_current_stock_nonnegative CHECK (current_stock >= 0)');
        DB::statement('ALTER TABLE medications ADD CONSTRAINT chk_minimum_stock_nonnegative CHECK (minimum_stock >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medications');
    }
};
