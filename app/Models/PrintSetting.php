<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintSetting extends Model
{
    protected $fillable = [
        'site_id',
        'cards_per_page',
        'paper',
        'orientation',
        'show_hint',
        'show_cut_lines',
        'logo_url',
        'footer_text',
        'bg_image_url',
    ];

    protected $casts = [
        'show_hint' => 'boolean',
        'show_cut_lines' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
