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
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'edge_settings' => 'array',
            'is_default' => 'boolean',
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

    /** Per addition-to-public-site-plan: short links for site/program QR codes. */
    public function shortLinks(): HasMany
    {
        return $this->hasMany(\App\Models\SiteShortLink::class);
    }
}

