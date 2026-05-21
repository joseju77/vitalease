<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $extensionAvailable = DB::selectOne(
            "SELECT 1 FROM pg_available_extensions WHERE name = 'citext'"
        ) !== null;

        if (! $extensionAvailable) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement(<<<'SQL'
            DO
            $$
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'citext_255') THEN
                        CREATE DOMAIN citext_255 AS citext CHECK (length(VALUE) <= 255);
                    END IF;
                END
            $$;
        SQL
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP DOMAIN IF EXISTS citext_255');
        DB::statement('DROP EXTENSION IF EXISTS citext');
    }
};
