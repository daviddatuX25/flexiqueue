<?php

namespace App\Models;

use App\Support\ProgramSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Program extends Model
{
    protected $attributes = [
        'slug' => 'program',
    ];

    protected $fillable = [
        'site_id',
        'name',
        'slug',
        'description',
        'is_active',
        'is_paused',
        'is_published',
        'settings',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_paused' => 'boolean',
            'is_published' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function settings(): ProgramSettings
    {
        return ProgramSettings::fromArray($this->settings ?? []);
    }

    /** @deprecated Use `$program->settings()->getNoShowTimerSeconds()` */
    public function getNoShowTimerSeconds(): int
    {
        return $this->settings()->getNoShowTimerSeconds();
    }

    /** @deprecated Use `$program->settings()->getRequirePermissionBeforeOverride()` */
    public function getRequirePermissionBeforeOverride(): bool
    {
        return $this->settings()->getRequirePermissionBeforeOverride();
    }

    /** @deprecated Use `$program->settings()->getPriorityFirst()` */
    public function getPriorityFirst(): bool
    {
        return $this->settings()->getPriorityFirst();
    }

    /** @deprecated Use `$program->settings()->getBalanceMode()` */
    public function getBalanceMode(): string
    {
        return $this->settings()->getBalanceMode();
    }

    /**
     * @return array{0: int, 1: int} [priority_count, regular_count] e.g. [2, 1] = 2 priority per 1 regular
     * @deprecated Use `$program->settings()->getAlternateRatio()`
     */
    public function getAlternateRatio(): array
    {
        return $this->settings()->getAlternateRatio();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function serviceTracks(): HasMany
    {
        return $this->hasMany(ServiceTrack::class);
    }

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }

    /**
     * Per PROCESS-STATION-REFACTOR: Logical work types for the program.
     */
    public function processes(): HasMany
    {
        return $this->hasMany(Process::class);
    }

    /** @deprecated Use `$program->settings()->getStationSelectionMode()` */
    public function getStationSelectionMode(): string
    {
        return $this->settings()->getStationSelectionMode();
    }

    /** Per flexiqueue-87p: display board scan auto-close. 0 = no auto-close; default 20 seconds. */
    /** @deprecated Use `$program->settings()->getDisplayScanTimeoutSeconds()` */
    public function getDisplayScanTimeoutSeconds(): int
    {
        return $this->settings()->getDisplayScanTimeoutSeconds();
    }

    /** Per plan: display board audio mute (admin-controlled). Default false. */
    /** @deprecated Use `$program->settings()->getDisplayAudioMuted()` */
    public function getDisplayAudioMuted(): bool
    {
        return $this->settings()->getDisplayAudioMuted();
    }

    /** Per plan: display board audio volume 0–1 (admin-controlled). Default 1. */
    /** @deprecated Use `$program->settings()->getDisplayAudioVolume()` */
    public function getDisplayAudioVolume(): float
    {
        return $this->settings()->getDisplayAudioVolume();
    }

    /** Display TTS announcement repeat count (1–3: Once, Twice, Three times). Default 1. */
    /** @deprecated Use `$program->settings()->getDisplayTtsRepeatCount()` */
    public function getDisplayTtsRepeatCount(): int
    {
        return $this->settings()->getDisplayTtsRepeatCount();
    }

    /** Delay between repeated announcements in milliseconds (500–10000). Default 2000. */
    /** @deprecated Use `$program->settings()->getDisplayTtsRepeatDelayMs()` */
    public function getDisplayTtsRepeatDelayMs(): int
    {
        return $this->settings()->getDisplayTtsRepeatDelayMs();
    }

    /** Per plan: allow public self-serve triage at GET /public-triage. Default false. */
    /** @deprecated Use `$program->settings()->getAllowPublicTriage()` */
    public function getAllowPublicTriage(): bool
    {
        return $this->settings()->getAllowPublicTriage();
    }

    /** Per barcode-hid plan: enable HID barcode input on Display board. Default true. */
    /** @deprecated Use `$program->settings()->getEnableDisplayHidBarcode()` */
    public function getEnableDisplayHidBarcode(): bool
    {
        return $this->settings()->getEnableDisplayHidBarcode();
    }

    /** Per barcode-hid plan: enable HID barcode input on Public triage. Default true. */
    /** @deprecated Use `$program->settings()->getEnablePublicTriageHidBarcode()` */
    public function getEnablePublicTriageHidBarcode(): bool
    {
        return $this->settings()->getEnablePublicTriageHidBarcode();
    }

    /** Per plan: enable camera/QR scanner on Display board. Default true. */
    /** @deprecated Use `$program->settings()->getEnableDisplayCameraScanner()` */
    public function getEnableDisplayCameraScanner(): bool
    {
        return $this->settings()->getEnableDisplayCameraScanner();
    }

    /**
     * Active TTS language for this program (used by displays and generation).
     * Defaults to 'en' when not explicitly configured.
     * @deprecated Use `$program->settings()->getTtsActiveLanguage()`
     */
    public function getTtsActiveLanguage(): string
    {
        return $this->settings()->getTtsActiveLanguage();
    }

    public function queueSessions(): HasMany
    {
        return $this->hasMany(Session::class, 'program_id');
    }

    public function supervisedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'program_supervisors')
            ->withTimestamps();
    }

    public function stationAssignments(): HasMany
    {
        return $this->hasMany(ProgramStationAssignment::class, 'program_id');
    }

    /**
     * Tokens assigned to this program via program_token pivot.
     * Per central-edge-v2-final §Phase C — Token–Program Association.
     */
    public function tokens(): BelongsToMany
    {
        return $this->belongsToMany(Token::class, 'program_token')
            ->withPivot('created_at');
    }

    /** Per program diagram visualizer: one layout per program. */
    public function diagram(): HasOne
    {
        return $this->hasOne(ProgramDiagram::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Program $program): void {
            $base = Str::slug($program->name) ?: 'program';
            $program->slug = $base;
            $siteId = $program->site_id;
            while (static::where('site_id', $siteId)->where('slug', $program->slug)->exists()) {
                $program->slug = $base.'-'.Str::random(5);
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Per addition-to-public-site-plan: programs visible on site landing (published and public within site). */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function programAccessTokens(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProgramAccessToken::class);
    }

    public function shortLinks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SiteShortLink::class, 'program_id');
    }

    /**
     * Scope to programs belonging to the given site.
     * Per central-edge B.4: admin program list and single-resource access are site-scoped.
     * When $siteId is null, returns no rows (admin with no site cannot see any program).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Program>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Program>
     */
    public function scopeForSite($query, ?int $siteId)
    {
        if ($siteId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('site_id', $siteId);
    }
}
