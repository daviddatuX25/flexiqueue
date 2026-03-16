<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientIdAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'client_id_audit_log';

    protected $fillable = [
        'client_id',
        'client_id_document_id',
        'staff_user_id',
        'action',
        'reason',
        'id_type',
        'id_last4',
        'created_at',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ClientIdDocument::class, 'client_id_document_id');
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}

