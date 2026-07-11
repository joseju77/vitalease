<?php

use App\Enums\EnrollmentSegment;
use App\Models\Enrollment;
use App\Models\FamilyMedicalUnit;
use App\Models\Municipality;
use App\Models\Neighborhood;
use App\Models\ZipCode;
use Database\Seeders\EnrollmentSeeder;
use Database\Seeders\FamilyMedicalUnitSeeder;
use Database\Seeders\LocationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function seedCatalogs(): void
{
    (new LocationSeeder)->run();
    (new FamilyMedicalUnitSeeder)->run();
    (new EnrollmentSeeder)->run();
}

describe('catalog seeding', function () {
    it('seeds the approved municipality, zip code, and neighborhood counts', function () {
        seedCatalogs();

        expect(Municipality::query()->count())->toBe(125)
            ->and(ZipCode::query()->count())->toBe(1983)
            ->and(Neighborhood::query()->count())->toBe(6290);
    });

    it('seeds the approved family medical unit count', function () {
        seedCatalogs();

        expect(FamilyMedicalUnit::query()->count())->toBe(130);
    });

    it('seeds the approved enrollment count', function () {
        seedCatalogs();

        expect(Enrollment::query()->count())->toBe(136);
    });

    it('links zip codes to their municipality and neighborhoods to their zip code', function () {
        seedCatalogs();

        $zipCode = ZipCode::query()->with(['municipality', 'neighborhoods'])->firstOrFail();

        expect($zipCode->municipality)->toBeInstanceOf(Municipality::class)
            ->and($zipCode->neighborhoods)->not->toBeEmpty()
            ->and($zipCode->neighborhoods->first()->zipCode->code)->toBe($zipCode->code);
    });

    it('casts the enrollment segment to the EnrollmentSegment enum', function () {
        seedCatalogs();

        $enrollment = Enrollment::query()->firstOrFail();

        expect($enrollment->segment)->toBeInstanceOf(EnrollmentSegment::class);
    });

    it('reruns catalog seeders idempotently without duplicating rows', function () {
        seedCatalogs();
        seedCatalogs();

        expect(Municipality::query()->count())->toBe(125)
            ->and(ZipCode::query()->count())->toBe(1983)
            ->and(Neighborhood::query()->count())->toBe(6290)
            ->and(FamilyMedicalUnit::query()->count())->toBe(130)
            ->and(Enrollment::query()->count())->toBe(136);
    });
});

describe('catalog constraints', function () {
    it('rejects a zip code that is not exactly five digits', function () {
        $municipality = Municipality::factory()->create();

        DB::table('zip_codes')->insert([
            'code' => '123',
            'municipality_id' => $municipality->id,
        ]);
    })->throws(QueryException::class);

    it('rejects a zip code referencing a municipality that does not exist', function () {
        DB::table('zip_codes')->insert([
            'code' => '99999',
            'municipality_id' => 999_999,
        ]);
    })->throws(QueryException::class);

    it('rejects an enrollment segment outside the approved domain', function () {
        DB::table('enrollments')->insert([
            'name' => 'Invalid Segment',
            'segment' => 9,
        ]);
    })->throws(QueryException::class);

    it('rejects a duplicate family medical unit name', function () {
        FamilyMedicalUnit::factory()->create(['name' => 'UMF 1']);

        FamilyMedicalUnit::factory()->create(['name' => 'UMF 1']);
    })->throws(QueryException::class);

    it('allows factories to create constraint-safe catalog graphs', function () {
        $neighborhood = Neighborhood::factory()->create();

        expect($neighborhood->zipCode)->toBeInstanceOf(ZipCode::class)
            ->and($neighborhood->zipCode->municipality)->toBeInstanceOf(Municipality::class);
    });
});
