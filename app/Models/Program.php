<?php

namespace App\Models;

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

    public function getNoShowTimerSeconds(): int
    {
        return (int) ($this->settings['no_show_timer_seconds'] ?? 10);
    }

    public function getRequirePermissionBeforeOverride(): bool
    {
        return (bool) ($this->settings['require_permission_before_override'] ?? true);
    }

    public function getPriorityFirst(): bool
    {
        return (bool) ($this->settings['priority_first'] ?? true);
    }

    public function getBalanceMode(): string
    {
        $mode = $this->settings['balance_mode'] ?? 'fifo';
        return in_array($mode, ['fifo', 'alternate'], true) ? $mode : 'fifo';
    }

    /**
     * @return array{0: int, 1: int} [priority_count, regular_count] e.g. [2, 1] = 2 priority per 1 regular
     */
    public function getAlternateRatio(): array
    {
        $ratio = $this->settings['alternate_ratio'] ?? [1, 1];
        if (! is_array($ratio) || count($ratio) < 2) {
            return [1, 1];
        }
        $p = max(1, (int) ($ratio[0] ?? 1));
        $r = max(1, (int) ($ratio[1] ?? 1));

        return [$p, $r];
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

    public function getStationSelectionMode(): string
    {
        $mode = $this->settings['station_selection_mode'] ?? 'fixed';

        return in_array($mode, ['fixed', 'shortest_queue', 'least_busy', 'round_robin', 'least_recently_served'], true)
            ? $mode
            : 'fixed';
    }

    /** Per flexiqueue-87p: display board scan auto-close. 0 = no auto-close; default 20 seconds. */
    public function getDisplayScanTimeoutSeconds(): int
    {
        $settings = $this->settings ?? [];
        $v = $settings['display_scan_timeout_seconds'] ?? null;

        return $v === null ? 20 : max(0, (int) $v);
    }

    /** Per plan: display board audio mute (admin-controlled). Default false. */
    public function getDisplayAudioMuted(): bool
    {
        $settings = $this->settings ?? [];

        return (bool) ($settings['display_audio_muted'] ?? false);
    }

    /** Per plan: display board audio volume 0–1 (admin-controlled). Default 1. */
    public function getDisplayAudioVolume(): float
    {
        $settings = $this->settings ?? [];
        $v = $settings['display_audio_volume'] ?? 1;

        return (float) max(0, min(1, $v));
    }

    /** Preferred TTS voice name for call announcements (browser SpeechSynthesisVoice.name). Null = use browser default (Microsoft Sonia Online / female). */
    public function getDisplayTtsVoice(): ?string
    {
        $settings = $this->settings ?? [];
        $v = $settings['display_tts_voice'] ?? null;

        return $v !== null && $v !== '' ? (string) $v : null;
    }

    /** Per plan: allow public self-serve triage at GET /triage/start. Default false. */
    public function getAllowPublicTriage(): bool
    {
        $settings = $this->settings ?? [];

        return (bool) ($settings['allow_public_triage'] ?? false);
    }

    /** Per barcode-hid plan: enable HID barcode input on Display board. Default true. */
    public function getEnableDisplayHidBarcode(): bool
    {
        $settings = $this->settings ?? [];

        return (bool) ($settings['enable_display_hid_barcode'] ?? true);
    }

    /** Per barcode-hid plan: enable HID barcode input on Public triage. Default true. */
    public function getEnablePublicTriageHidBarcode(): bool
    {
        $settings = $this->settings ?? [];

        return (bool) ($settings['enable_public_triage_hid_barcode'] ?? true);
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
