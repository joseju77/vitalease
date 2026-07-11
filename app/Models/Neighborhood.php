<?php

namespace App\Models;

use Database\Factories\NeighborhoodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'zip_code'])]
class Neighborhood extends Model
{
    /** @use HasFactory<NeighborhoodFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<ZipCode, $this>
     */
    public function zipCode(): BelongsTo
    {
        return $this->belongsTo(ZipCode::class, 'zip_code');
    }
}
