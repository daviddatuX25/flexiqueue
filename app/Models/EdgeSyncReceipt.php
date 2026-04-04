<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EdgeSyncReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'batch_id',
        'status',
        'payload_summary',
        'receipt_data',
        'started_at',
        'completed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
            'receipt_data' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function markComplete(array $receiptData): void
    {
        $this->update([
            'status' => 'complete',
            'receipt_data' => $receiptData,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
        ]);
    }

    public function markPartial(array $receiptData): void
    {
        $this->update([
            'status' => 'partial',
            'receipt_data' => $receiptData,
        ]);
    }
}