<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'site_id',
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

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function transactionLogs(): HasMany
    {
        return $this->hasMany(TransactionLog::class, 'staff_user_id');
    }

    public function supervisedPrograms(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'program_supervisors')
            ->using(ProgramSupervisor::class)
            ->withTimestamps();
    }

    public function programStationAssignments(): HasMany
    {
        return $this->hasMany(ProgramStationAssignment::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Super admin can manage all sites and assign/change user site.
     * Per central-edge follow-up: assign-site-to-user-ui.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isSupervisorForProgram(int $programId): bool
    {
        return $this->supervisedPrograms()->where('programs.id', $programId)->exists();
    }

    public function isSupervisorForAnyProgram(): bool
    {
        return $this->supervisedPrograms()->exists();
    }

    /**
     * Scope to users belonging to the given site.
     * Per central-edge B.4: admin user list and single-resource access are site-scoped.
     * When $siteId is null, returns no rows (admin with no site cannot see any user).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeForSite($query, ?int $siteId)
    {
        if ($siteId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('site_id', $siteId);
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

        return Storage::disk('public')->url('avatars/'.$this->avatar_path);
    }

    /**
     * Logged-in staff/admin on a public display or kiosk may POST /api/public/display-settings without PIN/QR
     * when changing settings for a program on their site (matches UI canBypassDeviceLock).
     */
    public function canBypassPublicDisplaySettingsPinForProgram(int $programId): bool
    {
        $program = Program::find($programId);
        if (! $program) {
            return false;
        }
        if ($this->role === UserRole::SuperAdmin) {
            return true;
        }
        if (in_array($this->role, [UserRole::Admin, UserRole::Staff], true)) {
            return $this->site_id !== null && (int) $this->site_id === (int) $program->site_id;
        }

        return false;
    }
}
