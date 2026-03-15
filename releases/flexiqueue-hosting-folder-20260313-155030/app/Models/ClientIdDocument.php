<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Client ID document record: encrypted ID number plus deterministic hash for lookup.
 */
class ClientIdDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'id_type',
        'id_number_encrypted',
        'id_number_hash',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}

