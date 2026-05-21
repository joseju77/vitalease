<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

class Citext255
{
    /**
     * Determine whether the citext_255 Postgres domain is available.
     */
    public static function isAvailable(): bool
    {
        return DB::selectOne("SELECT 1 FROM pg_type WHERE typname = 'citext_255'") !== null;
    }

    /**
     * Convert the given column to citext_255 when the domain is available.
     * Leaves the column untouched (plain string) otherwise.
     */
    public static function applyTo(string $table, string $column): void
    {
        if (! static::isAvailable()) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE citext_255");
    }
}
