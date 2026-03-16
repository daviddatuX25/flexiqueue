<?php

namespace Database\Seeders\Central;

use App\Models\Program;
use App\Models\Site;
use App\Models\Token;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Creates 60 tokens per site (A1–A9 … F1–F9, G1–G6), assigned to AICS. Per docs/seeder-plan.txt §6.
 */
class CentralTokenSeeder extends Seeder
{
    public function run(): void
    {
        $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        $count = 0;
        foreach (['tagudin-mswdo', 'candon-mswdo'] as $slug) {
            $site = Site::where('slug', $slug)->firstOrFail();
            $aics = Program::where('site_id', $site->id)->where('name', 'like', '%AICS%')->firstOrFail();

            for ($letterIndex = 0; $letterIndex < 7; $letterIndex++) {
                $maxNum = $letterIndex < 6 ? 9 : 6;
                for ($num = 1; $num <= $maxNum; $num++) {
                    $physicalId = $letters[$letterIndex] . $num;
                    $qrCodeHash = \Database\Seeders\Shared\HistoryHelper::makeQrHash($site->slug, $physicalId);
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
                    $count++;
                }
            }

            $tokenIds = Token::where('site_id', $site->id)->pluck('id');
            $pivotRows = $tokenIds->map(fn ($id) => [
                'program_id' => $aics->id,
                'token_id' => $id,
                'created_at' => now(),
            ])->all();
            foreach (array_chunk($pivotRows, 50) as $chunk) {
                DB::table('program_token')->insertOrIgnore($chunk);
            }
        }
    }
}
