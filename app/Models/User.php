<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Services\RbacContextService;
use App\Services\UserProvisioningService;
use App\Support\PermissionCatalog;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'site_id',
        'name',
        'username',
        'email',
        'recovery_gmail',
        'password',
        'override_pin',
        'override_qr_token',
        'assigned_station_id',
        'is_active',
        'pending_assignment',
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
            'is_active' => 'boolean',
            'pending_assignment' => 'boolean',
            'availability_updated_at' => 'datetime',
            'staff_triage_allow_hid_barcode' => 'boolean',
            'staff_triage_allow_camera_scanner' => 'boolean',
        ];
    }

    /**
     * Primary product role comes from the Spatie global-team role (admin | staff | super_admin).
     *
     * @see PermissionCatalogSeeder
     */
    protected function role(): Attribute
    {
        return Attribute::make(
            get: function (): ?UserRole {
                $name = $this->primaryGlobalRoleName();
                if ($name === null) {
                    return null;
                }

                return UserRole::tryFrom($name);
            },
        );
    }

    /**
     * First global-team Spatie role name, or null if none assigned.
     */
    public function primaryGlobalRoleName(): ?string
    {
        return self::withGlobalPermissionsTeam(function (): ?string {
            $this->unsetRelation('roles');

            return $this->roles()->first()?->name;
        });
    }

    /**
     * Set Spatie global role(s) and run provisioning (credentials + supervisor direct permissions).
     * Use this instead of a DB column when creating/updating users outside {@see UserController}.
     */
    public static function assignGlobalRoleAndSyncProvisioning(User $user, string $roleName): void
    {
        self::withGlobalPermissionsTeam(function () use ($user, $roleName): void {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->syncRoles([$roleName]);
        });
        app(UserProvisioningService::class)->syncIdentityAndRbac($user);
    }

    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            if (! self::shouldRunProvisioningAfterSave($user)) {
                return;
            }
            app(UserProvisioningService::class)->syncIdentityAndRbac($user);
        });
    }

    /**
     * End-state R6: avoid running Spatie/credential sync on every attribute change (e.g. availability only).
     * Role / auth identifiers / password drive provisioning.
     */
    private static function shouldRunProvisioningAfterSave(User $user): bool
    {
        return $user->wasChanged([
            'username',
            'password',
        ]);
    }

    /**
     * Spatie role scopes resolve role IDs using {@see getPermissionsTeamId()}; use this for global-team queries.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withGlobalPermissionsTeam(callable $callback): mixed
    {
        $previous = getPermissionsTeamId();
        setPermissionsTeamId(RbacTeam::GLOBAL_TEAM_ID);
        try {
            return $callback();
        } finally {
            setPermissionsTeamId($previous);
        }
    }

    /**
     * R5: Whether this user has a Spatie role on the global team (authoritative for access checks).
     */
    public function hasSpatieRole(string $roleName): bool
    {
        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId(RbacTeam::GLOBAL_TEAM_ID);
            $this->unsetRelation('roles')->unsetRelation('permissions');

            return $this->hasRole($roleName);
        } finally {
            setPermissionsTeamId($previous);
            $this->unsetRelation('roles')->unsetRelation('permissions');
        }
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

    /**
     * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md §4.1: local + optional google credential rows.
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(UserCredential::class);
    }

    /**
     * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md §4.1: reset tokens and mail use Gmail on file, not login email.
     */
    public function getEmailForPasswordReset(): string
    {
        return (string) ($this->recovery_gmail ?? '');
    }

    /**
     * Route reset mail to recovery Gmail; other notifications keep using users.email when applicable.
     */
    public function routeNotificationForMail(mixed $notification = null): mixed
    {
        if ($notification instanceof ResetPasswordNotification) {
            return $this->recovery_gmail;
        }

        return $this->email;
    }

    /**
     * Count distinct programs where this user has `programs.supervise` on the program {@see RbacTeam}.
     * Replaces legacy `supervisedPrograms` pivot count.
     */
    public function scopeWithSupervisorProgramCount(Builder $query): void
    {
        $morph = (new static)->getMorphClass();
        if ($query->getQuery()->columns === null) {
            $query->select('users.*');
        }
        $query->selectSub(
            DB::table('model_has_permissions as mhp')
                ->join('permissions as p', 'p.id', '=', 'mhp.permission_id')
                ->join('rbac_teams as rt', 'rt.id', '=', 'mhp.team_id')
                ->where('p.name', PermissionCatalog::PROGRAMS_SUPERVISE)
                ->where('rt.type', 'program')
                ->where('mhp.model_type', $morph)
                ->whereColumn('mhp.model_id', 'users.id')
                ->selectRaw('count(distinct rt.program_id)'),
            'supervised_program_count'
        );
    }

    public function programStationAssignments(): HasMany
    {
        return $this->hasMany(ProgramStationAssignment::class);
    }

    public function isAdmin(): bool
    {
        return $this->hasSpatieRole(UserRole::Admin->value);
    }

    /**
     * Super admin can manage all sites and assign/change user site.
     * Per central-edge follow-up: assign-site-to-user-ui.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasSpatieRole(UserRole::SuperAdmin->value);
    }

    public function isStaff(): bool
    {
        return $this->hasSpatieRole(UserRole::Staff->value);
    }

    public function isAdminOrSuperAdmin(): bool
    {
        return $this->isAdmin() || $this->isSuperAdmin();
    }

    /**
     * Program supervisor: `programs.supervise` on this program's {@see RbacTeam}.
     */
    public function isSupervisorForProgram(int $programId): bool
    {
        $program = Program::query()->find($programId);
        if ($program === null) {
            return false;
        }

        return app(RbacContextService::class)->canInProgramTeamOnly($this, PermissionCatalog::PROGRAMS_SUPERVISE, $program);
    }

    public function isSupervisorForAnyProgram(): bool
    {
        return app(RbacContextService::class)->hasProgramTeamSuperviseOnAnyActiveProgramInUserScope($this);
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

        if ($this->can(PermissionCatalog::PLATFORM_MANAGE)) {
            return true;
        }

        return $this->site_id !== null && (int) $this->site_id === (int) $program->site_id;
    }
}
