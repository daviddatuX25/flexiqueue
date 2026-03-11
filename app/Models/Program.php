<?php

namespace App\Models;

use App\Support\ProgramSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Program extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
        'is_paused',
        'settings',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_paused' => 'boolean',
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

    /** Per plan: allow public self-serve triage at GET /triage/start. Default false. */
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

    /** Per program diagram visualizer: one layout per program. */
    public function diagram(): HasOne
    {
        return $this->hasOne(ProgramDiagram::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
