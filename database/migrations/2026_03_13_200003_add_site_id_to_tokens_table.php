<?php

use App\Models\Site;
use App\Models\Token;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per site-scoping-migration-spec §2 — Tokens (S.1).
 * Add tokens.site_id (nullable FK) and backfill from program_token → program.site_id or default site.
 * SQLite + MariaDB compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->foreignId('site_id')
                ->nullable()
                ->after('id')
                ->constrained('sites')
                ->nullOnDelete();
        });

        $this->backfillTokensSiteId();
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropColumn('site_id');
        });
    }

    /**
     * Backfill: set site_id from program_token → program.site_id, else default site.
     * Deterministic: use MIN(program.site_id) when token is in multiple sites.
     */
    private function backfillTokensSiteId(): void
    {
        $defaultSite = Site::orderBy('id')->first();

        if (! $defaultSite) {
            return;
        }

        $tokenIds = Token::withTrashed()->pluck('id');

        foreach ($tokenIds as $tokenId) {
            $siteId = $this->deriveSiteIdForToken($tokenId);

            if ($siteId === null) {
                $siteId = $defaultSite->id;
            }

            DB::table('tokens')->where('id', $tokenId)->update(['site_id' => $siteId]);
        }
    }

    /**
     * Derive site_id from program_token join programs. Use MIN(site_id) for deterministic choice
     * when token is assigned to programs in multiple sites.
     */
    private function deriveSiteIdForToken(int $tokenId): ?int
    {
        $row = DB::table('program_token')
            ->join('programs', 'program_token.program_id', '=', 'programs.id')
            ->where('program_token.token_id', $tokenId)
            ->whereNotNull('programs.site_id')
            ->selectRaw('MIN(programs.site_id) as site_id')
            ->first();

        return $row && $row->site_id !== null ? (int) $row->site_id : null;
    }
};
