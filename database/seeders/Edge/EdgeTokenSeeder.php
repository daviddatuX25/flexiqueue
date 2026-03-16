<?php

namespace Database\Seeders\Edge;

use App\Models\Program;
use App\Models\Site;
use App\Models\Token;
use Database\Seeders\Shared\HistoryHelper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Creates 40 tokens: A1–A9, B1–B9, C1–C9, D1–D9, E1–E4. Per docs/seeder-plan.txt §12.
 */
class EdgeTokenSeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::where('slug', 'tagudin-mswdo-field')->firstOrFail();
        $aics = Program::where('site_id', $site->id)->where('name', 'like', '%AICS%')->firstOrFail();

        for ($index = 0; $index < 40; $index++) {
            $physicalId = HistoryHelper::makeTokenPhysicalId($index);
            $qrCodeHash = HistoryHelper::makeQrHash($site->slug, $physicalId);
            DB::table('tokens')->insertOrIgnore([
                'site_id' => $site->id,
                'physical_id' => $physicalId,
                'pronounce_as' => null,
                'qr_code_hash' => $qrCodeHash,
                'status' => 'available',
                'is_global' => false,
                'tts_status' => null,
                'tts_settings' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $tokenIds = Token::where('site_id', $site->id)->pluck('id');
        $pivotRows = $tokenIds->map(fn ($id) => [
            'program_id' => $aics->id,
            'token_id' => $id,
            'created_at' => now(),
        ])->all();
        DB::table('program_token')->insertOrIgnore($pivotRows);
    }
}
