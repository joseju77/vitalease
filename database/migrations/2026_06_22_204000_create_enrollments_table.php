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
        Schema::create('enrollments', function (Blueprint $table) {
            $table->integerIncrements('id');
            $table->string('name');
            $table->smallInteger('segment');

            $table->unique(['name', 'segment']);
        });

        Citext255::applyTo('enrollments', 'name');

        DB::statement('ALTER TABLE enrollments ADD CONSTRAINT chk_enrollment_segment CHECK (segment IN (1, 2, 3, 4))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
