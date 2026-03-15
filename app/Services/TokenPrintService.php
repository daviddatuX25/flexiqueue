<?php

namespace App\Services;

use App\Models\Token;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Per docs/plans/QR-TOKEN-PRINT-SYSTEM.md QR-1, QR-2: Token print template and QR generation.
 * Prepares tokens for printing: generates QR PNG data URIs, excludes invalid tokens (empty hash, library failure).
 */
class TokenPrintService
{
    /**
     * Minimum QR size for reliable scanning (~2cm at print resolution). Per QR-TOKEN-PRINT-SYSTEM §4.4.
     */
    private const QR_SIZE_PX = 200;

    /**
     * Prepare tokens for print. Excludes tokens with empty qr_code_hash or on QR generation failure.
     *
     * @param  Collection<int, Token>  $tokens
     * @return array{cards: array<int, array{physical_id: string, qr_data_uri: string, qr_hash: string}>, skipped: int, skip_reasons: array<int, string>}
     */
    public function prepareTokensForPrint(Collection $tokens, ?string $baseUrl = null): array
    {
        $baseUrl = rtrim($baseUrl ?? config('app.url'), '/');
        $cards = [];
        $skipReasons = [];

        foreach ($tokens as $token) {
            $hash = $token->qr_code_hash ?? '';
            if ($hash === '') {
                $skipReasons[$token->id] = 'empty_hash';
                Log::warning('Token print: skipping token with empty qr_code_hash', ['token_id' => $token->id]);

                continue;
            }

            try {
                // Per site-scoping: encode site_id in QR URL so token is unambiguous across sites.
                $url = $token->site_id !== null
                    ? $baseUrl.'/display/status/'.(int) $token->site_id.'/'.$hash
                    : $baseUrl.'/display/status/'.$hash;
                $qrDataUri = $this->generateQrDataUri($url);
            } catch (\Throwable $e) {
                $skipReasons[$token->id] = 'qr_generation_failed';
                Log::error('Token print: QR generation failed for token', [
                    'token_id' => $token->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $cards[] = [
                'physical_id' => $token->physical_id,
                'qr_data_uri' => $qrDataUri,
                'qr_hash' => $hash,
            ];
        }

        return [
            'cards' => $cards,
            'skipped' => count($skipReasons),
            'skip_reasons' => $skipReasons,
        ];
    }

    /**
     * Generate QR code as PNG data URI for embedding in HTML.
     */
    public function generateQrDataUri(string $data): string
    {
        $builder = new Builder(
            writer: new PngWriter,
            writerOptions: [],
            validateResult: false,
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: self::QR_SIZE_PX,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        $result = $builder->build();

        return $result->getDataUri();
    }
}
