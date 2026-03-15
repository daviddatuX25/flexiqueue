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
        'identity_registration_id',
        'staff_user_id',
        'action',
        'mobile_last2',
        'reason',
        'created_at',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function identityRegistration(): BelongsTo
    {
        return $this->belongsTo(IdentityRegistration::class);
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}

