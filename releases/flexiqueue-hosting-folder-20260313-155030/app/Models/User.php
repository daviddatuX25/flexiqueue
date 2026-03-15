<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'override_pin',
        'override_qr_token',
        'assigned_station_id',
        'is_active',
        'availability_status',
        'avatar_path',
        'staff_triage_allow_hid_barcode',
        'staff_triage_allow_camera_scanner',
    ];

    protected $appends = ['avatar_url'];

    protected $hidden = [
        'password',
        'remember_token',
        'override_pin',
        'override_qr_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'availability_updated_at' => 'datetime',
            'staff_triage_allow_hid_barcode' => 'boolean',
            'staff_triage_allow_camera_scanner' => 'boolean',
        ];
    }

    public const AVAILABILITY_AVAILABLE = 'available';
    public const AVAILABILITY_ON_BREAK = 'on_break';
    public const AVAILABILITY_AWAY = 'away';
    public const AVAILABILITY_OFFLINE = 'offline';

    public function assignedStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'assigned_station_id');
    }

    public function transactionLogs(): HasMany
    {
        return $this->hasMany(TransactionLog::class, 'staff_user_id');
    }

    public function supervisedPrograms(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'program_supervisors')
            ->withTimestamps();
    }

    public function programStationAssignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProgramStationAssignment::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isSupervisorForProgram(int $programId): bool
    {
        return $this->supervisedPrograms()->where('programs.id', $programId)->exists();
    }

    public function isSupervisorForAnyProgram(): bool
    {
        return $this->supervisedPrograms()->exists();
    }

    public function temporaryAuthorizations(): HasMany
    {
        return $this->hasMany(TemporaryAuthorization::class);
    }

    /**
     * Public URL for avatar image. Null when no avatar_path.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url('avatars/'.$this->avatar_path);
    }
}
