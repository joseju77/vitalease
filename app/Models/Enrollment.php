<?php

namespace App\Models;

use App\Enums\EnrollmentSegment;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'segment'])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Human-readable identifier shown in the audit history.
     */
    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'segment' => EnrollmentSegment::class,
        ];
    }
}
