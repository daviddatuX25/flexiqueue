<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintSetting extends Model
{
    protected $fillable = [
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

    /**
     * Get the singleton print settings. Creates default row if none exists.
     */
    public static function instance(): self
    {
        $settings = self::first();
        if (! $settings) {
            $settings = self::create([
                'cards_per_page' => 6,
                'paper' => 'a4',
                'orientation' => 'portrait',
                'show_hint' => true,
                'show_cut_lines' => true,
            ]);
        }

        return $settings;
    }
}
