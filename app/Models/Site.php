<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'api_key_hash',
        'settings',
        'edge_settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'edge_settings' => 'array',
        ];
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}

