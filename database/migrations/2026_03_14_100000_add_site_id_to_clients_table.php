<?php

use App\Models\Client;
use App\Models\Site;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per site-scoping-migration-spec §3 — Clients (S.3).
 * Add clients.site_id (nullable FK to sites.id) and backfill from
 * queue_sessions.program_id → program.site_id, else identity_registrations.program_id → program.site_id,
 * else default site. SQLite + MariaDB compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('site_id')
                ->nullable()
                ->after('id')
                ->constrained('sites')
                ->nullOnDelete();
        });

        $this->backfillClientsSiteId();
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropColumn('site_id');
        });
    }

    /**
     * Backfill: set site_id from first queue_sessions.program_id → program.site_id,
     * else first identity_registrations.program_id → program.site_id, else default site.
     */
    private function backfillClientsSiteId(): void
    {
        $defaultSite = Site::orderBy('id')->first();

        if (! $defaultSite) {
            return;
        }

        $clientIds = Client::query()->pluck('id');

        foreach ($clientIds as $clientId) {
            $siteId = $this->deriveSiteIdForClient($clientId);

            if ($siteId === null) {
                $siteId = $defaultSite->id;
            }

            DB::table('clients')->where('id', $clientId)->update(['site_id' => $siteId]);
        }
    }

    /**
     * Derive site_id from first queue_session's program or first identity_registration's program.
     */
    private function deriveSiteIdForClient(int $clientId): ?int
    {
        $row = DB::table('queue_sessions')
            ->where('queue_sessions.client_id', $clientId)
            ->join('programs', 'queue_sessions.program_id', '=', 'programs.id')
            ->whereNotNull('programs.site_id')
            ->orderBy('queue_sessions.id')
            ->select('programs.site_id')
            ->first();

        if ($row && $row->site_id !== null) {
            return (int) $row->site_id;
        }

        $row = DB::table('identity_registrations')
            ->where('identity_registrations.client_id', $clientId)
            ->join('programs', 'identity_registrations.program_id', '=', 'programs.id')
            ->whereNotNull('programs.site_id')
            ->orderBy('identity_registrations.id')
            ->select('programs.site_id')
            ->first();

        return $row && $row->site_id !== null ? (int) $row->site_id : null;
    }
};
