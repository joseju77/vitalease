<?php

namespace App\Models;

use Database\Factories\FamilyMedicalUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'address'])]
class FamilyMedicalUnit extends Model
{
    /** @use HasFactory<FamilyMedicalUnitFactory> */
    use HasFactory;

    public $timestamps = false;
}
