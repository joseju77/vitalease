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
        Schema::create('patient_contact_information', function (Blueprint $table) {
            $table->integer('patient_id')->primary();
            $table->string('address');
            $table->string('phone_number', 16);
            $table->string('personal_email');
            $table->string('institutional_email')->nullable();
            $table->integer('neighborhood_id')->nullable();

            $table->foreign('patient_id')->references('id')->on('patients');
            $table->foreign('neighborhood_id')->references('id')->on('neighborhoods');
        });

        Citext255::applyTo('patient_contact_information', 'personal_email');
        Citext255::applyTo('patient_contact_information', 'institutional_email');

        DB::statement("ALTER TABLE patient_contact_information ADD CONSTRAINT chk_phone_number_format CHECK (phone_number ~ '^\\+[1-9]\\d{1,14}$')");

        DB::statement('
            CREATE UNIQUE INDEX patient_contact_information_institutional_email_unique
            ON patient_contact_information (institutional_email)
            WHERE institutional_email IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_contact_information');
    }
};
