<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Minimal client identity record for XM2O identity binding.
 * Per site-scoping-migration-spec §3: scoped by site_id.
 */
class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'mobile_encrypted',
        'mobile_hash',
        'identity_hash',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    /**
     * Single display name for exports/reports. Per plan: no display_name in API; frontend computes.
     */
    public function getDisplayNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name ?? '',
            $this->middle_name ?? '',
            $this->last_name ?? '',
        ]))) ?: 'Unknown';
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Scope query to a given site (same pattern as Program/Token).
     * Null siteId => no rows (site admin must have a site).
     */
    public function scopeForSite($query, ?int $siteId)
    {
        if ($siteId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('site_id', $siteId);
    }
}

