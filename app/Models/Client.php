<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Minimal client identity record for XM2O identity binding.
 */
class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'birth_year',
    ];

    public function idDocuments(): HasMany
    {
        return $this->hasMany(ClientIdDocument::class);
    }
}

