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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->integerIncrements('id');
            $table->integer('medication_id');
            $table->smallInteger('type');
            $table->integer('quantity');
            $table->integer('stock_after');
            $table->integer('medical_consultation_id')->nullable();
            $table->string('medical_consultation_code', 14)->nullable();
            $table->integer('user_id');
            $table->string('notes', 1024)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('medication_id')->references('id')->on('medications')->restrictOnDelete();
            $table->foreign('medical_consultation_id')->references('id')->on('medical_consultations')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users');

            $table->index(['medication_id', 'occurred_at']);
            $table->index('medical_consultation_id');
        });

        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT chk_type_domain CHECK (type IN (1, 2, 3, 4))');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT chk_quantity_nonzero CHECK (quantity <> 0)');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT chk_quantity_sign_by_type CHECK (
            (type = 1 AND quantity > 0)
            OR (type = 2 AND quantity < 0)
            OR (type = 3)
            OR (type = 4 AND quantity > 0)
        )');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT chk_stock_after_nonnegative CHECK (stock_after >= 0)');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT chk_consultation_code_by_type CHECK (
            (type IN (2, 4)) = (medical_consultation_code IS NOT NULL)
        )');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT chk_consultation_id_by_type CHECK (
            type IN (2, 4) OR medical_consultation_id IS NULL
        )');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT chk_adjustment_notes CHECK (
            type <> 3 OR btrim(coalesce(notes, '')) <> ''
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
