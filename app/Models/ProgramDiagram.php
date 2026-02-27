<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per program diagram visualizer plan: one layout (nodes, edges, viewport) per program.
 * Layout is JSON: { viewport?: {...}, nodes: [...], edges: [...] }.
 */
class ProgramDiagram extends Model
{
    protected $fillable = [
        'program_id',
        'layout',
    ];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
