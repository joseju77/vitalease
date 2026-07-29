<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class MedicalConsultationCode
{
    private const PREFIX = 'MC';

    private const MAX_DAILY_SEQUENCE = 9999;

    /**
     * Generate the next sequential `MC-YYMMDD-NNNN` code for the current
     * calendar day in `America/Mexico_City`.
     *
     * Must run inside an open database transaction: it takes a transaction
     * scoped advisory lock keyed on today's day, so concurrent requests
     * serialize on that lock instead of racing for the same sequence
     * number. The lock is released automatically when the transaction
     * commits or rolls back.
     */
    public static function next(): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('MedicalConsultationCode::next() must be called inside a database transaction.');
        }

        $day = now('America/Mexico_City')->format('ymd');

        DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?), ?)', ['medical_consultations.code', (int) $day]);

        $prefix = self::PREFIX.'-'.$day.'-';

        $lastCode = DB::table('medical_consultations')
            ->where('code', 'like', $prefix.'%')
            ->max('code');

        $sequence = $lastCode === null ? 1 : ((int) substr((string) $lastCode, -4)) + 1;

        if ($sequence > self::MAX_DAILY_SEQUENCE) {
            throw new RuntimeException("Medical consultation code sequence exhausted for {$day}.");
        }

        return sprintf('%s%04d', $prefix, $sequence);
    }
}
